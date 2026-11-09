<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * The newest version each kind of source has of a tool, asked asynchronously.
 *
 * One answer per source, each from the place that source installs from —
 * so "newer" means "what an Update here would bring":
 *
 *   - github: the `releases/latest` redirect ({@see GithubInstaller::latestTag()});
 *   - composer: Packagist's `p2/<package>.json`, whose first entry is the newest stable;
 *   - brew / brew-cask: `brew info --json=v2`, answered from brew's local index;
 *   - cargo: crates.io's `max_stable_version`;
 *   - npm: the registry's `latest` dist-tag.
 *
 * Every failure answers null, never a message: this runs in the background,
 * and a check that toasts "cannot reach github.com" at every launch on a
 * plane is worse than no check at all.
 */
#[Autoconfigure(constructor: 'create')]
final readonly class LatestVersions
{
    /** An index answers in one piece; nothing is watched as it arrives. */
    private const null NO_CHUNKS = null;

    /** Bounded and HTTPS-only, like every download Porta makes. */
    public const array CURL = ['curl', '-fsSL', '--connect-timeout', '15', '--max-time', '30', '--proto', '=https', '--proto-redir', '=https'];

    /** @var \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        inject a fake to test without the network
     * @param string $token a GitHub token for the API fallback, or ''
     */
    public function __construct(
        ?\Closure $run = null,
        #[\SensitiveParameter]
        private string $token = '',
    ) {
        $this->run = $run ?? ProcessRunner::default();
    }

    /** Production wiring: real processes, kept in $runs so quitting stops them, and the environment's GitHub token. */
    public static function create(RunningProcesses $runs): self
    {
        return new self(ProcessRunner::default(runs: $runs), GithubInstaller::tokenFromEnvironment());
    }

    /**
     * The newest version $source has of $tool, bare (no `v`), or null.
     *
     * @param \Closure(?string): void $onVersion exactly once
     */
    public function of(ToolRequirement $tool, ToolSource $source, \Closure $onVersion): void
    {
        $package = $source->package;
        match ($source->manager) {
            ToolManager::Github => $this->github($tool, $source, $onVersion),
            ToolManager::Brew => $this->fetch(['brew', 'info', '--json=v2', '--formula', $package], self::brew(...), $onVersion),
            ToolManager::BrewCask => $this->fetch(['brew', 'info', '--json=v2', '--cask', $package], self::brew(...), $onVersion),
            // crates.io refuses a request with no User-Agent.
            ToolManager::Cargo => $this->fetch(
                [...self::CURL, '-A', 'porta (+https://github.com/janalis/porta)', 'https://crates.io/api/v1/crates/'.\rawurlencode($package)],
                self::crate(...),
                $onVersion,
            ),
            ToolManager::Npm => $this->fetch([...self::CURL, 'https://registry.npmjs.org/'.\str_replace('/', replace: '%2F', subject: $package).'/latest'], self::npm(...), $onVersion),
            // A composer tool's update is `composer update`, which keeps to the
            // project's constraint: Packagist's newest is often out of its
            // reach, and offering it would promise what Update cannot do.
            // A plugin's own installer is the only judge of its own tool.
            ToolManager::Composer, ToolManager::Plugin => $onVersion(null),
        };
    }

    /** Whether $latest is newer than $installed; false when either is unknown. */
    public static function newer(string $installed, ?string $latest): bool
    {
        return null !== $latest && '' !== $installed && '' !== $latest && \version_compare(\ltrim($latest, characters: 'v'), \ltrim($installed, characters: 'v'), operator: '>');
    }

    /** `brew info --json=v2`: a formula's stable version, or a cask's version. */
    public static function brew(string $json): ?string
    {
        return self::at($json, 'formulae', 0, 'versions', 'stable') ?? self::at($json, 'casks', 0, 'version');
    }

    /** crates.io: the newest version that is not a pre-release. */
    public static function crate(string $json): ?string
    {
        return self::at($json, 'crate', 'max_stable_version');
    }

    /** The npm registry's `<package>/latest` manifest. */
    public static function npm(string $json): ?string
    {
        return self::at($json, 'version');
    }

    /** @param \Closure(?string): void $onVersion */
    private function github(ToolRequirement $tool, ToolSource $source, \Closure $onVersion): void
    {
        $release = new GithubRelease($source->package, $source->asset ?? new GithubAsset('', [], []));
        // Only the tag lookup runs, which writes nothing: no bin or scratch
        // directory is ever touched.
        new GithubInstaller($release, $tool->binary, '', '', $this->run, $this->token)->latestTag(static function (?string $tag) use ($release, $onVersion): void {
            $onVersion(null === $tag ? null : $release->versionOfTag($tag));
        });
    }

    /**
     * @param list<string>               $argv
     * @param \Closure(string): ?string  $parse
     * @param \Closure(?string): void    $onVersion
     */
    private function fetch(array $argv, \Closure $parse, \Closure $onVersion): void
    {
        ($this->run)(
            $argv,
            \sys_get_temp_dir(),
            static function (int $code, string $out) use ($parse, $onVersion): void {
                $onVersion(0 === $code ? $parse($out) : null);
            },
            self::NO_CHUNKS,
        );
    }

    /**
     * The version at $path in the JSON $json, without its `v` — or null when
     * the answer is not JSON, the path is not there, or what is there is not
     * shaped like a version.
     */
    private static function at(string $json, string|int ...$path): ?string
    {
        // @noinspection JsonEncodingApiUsageInspection -- an index answer that is not JSON is dropped, not thrown: it comes off the network
        // @mago-expect analysis:mixed-assignment -- narrowed at every step below
        $node = \json_decode($json, associative: true);
        foreach ($path as $key) {
            // @mago-expect analysis:mixed-assignment -- narrowed at every step
            $node = \is_array($node) ? $node[$key] ?? null : null;
        }
        $bare = \is_string($node) ? \ltrim(\trim($node), characters: 'v') : '';

        return 1 === \preg_match('/^\d[0-9A-Za-z.\-+_]*$/', $bare) ? $bare : null;
    }
}
