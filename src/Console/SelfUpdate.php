<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Support\Decoded;

/**
 * `porta --self-update`: replace the running phar with the latest GitHub
 * release of {@see REPOSITORY}.
 *
 * The repository is private, so every request carries credentials: the `gh`
 * CLI's own login when `gh` is installed, else `GH_TOKEN` / `GITHUB_TOKEN`
 * through the REST API. Each release carries `porta.phar` and
 * `porta.phar.sha256` (the release workflow attaches both).
 *
 * The swap is the careful part. The download is checked against its checksum
 * and RUN (`--version`) before it goes anywhere near the running file, then
 * written beside it and renamed over it: a rename is atomic on one filesystem,
 * so an interrupted update leaves the old phar, never half of a new one.
 *
 * Versions are release tags (`v1.2.0`). A build made from commits after a tag
 * (`v1.2.0-3-gabc123`) counts as that tag, so it is offered the next release
 * rather than a downgrade to its own; a build from no tag at all (a bare
 * commit) is offered any release.
 */
final readonly class SelfUpdate
{
    public const string REPOSITORY = 'janalis/porta';

    private const string ASSET = 'porta.phar';

    /**
     * @param \Closure(list<string>, ?string=): array{int, string} $run runs a command, with what to write to its
     *                                                                   stdin, returning its exit code and stdout
     * @param array<string, string|false>                                       $env the process environment, read
     *                                                                               for a GitHub token
     */
    public function __construct(
        private \Closure $run,
        private array $env,
    ) {}

    /**
     * The latest release's tag.
     *
     * @throws \RuntimeException with a sentence saying what to do when there are no credentials or no release
     */
    public function latest(): string
    {
        if ($this->hasGh()) {
            [$code, $out] = ($this->run)(['gh', 'release', 'view', '--repo', self::REPOSITORY, '--json', 'tagName', '--jq', '.tagName']);
            if (0 !== $code || '' === \trim($out)) {
                throw new \RuntimeException('gh could not read the latest release of '.self::REPOSITORY.' — run `gh auth login`, or check that a release exists.');
            }

            return \trim($out);
        }
        $release = $this->api('releases/latest');

        return \is_string($release['tag_name'] ?? null) ? $release['tag_name'] : '';
    }

    /** Whether $latest is a release this build has not got yet. */
    public static function isNewer(string $latest, string $current): bool
    {
        return \version_compare(self::release($latest), self::release($current), operator: '>');
    }

    /**
     * Download $tag's phar, check it, and swap it over $phar.
     *
     * @throws \RuntimeException saying what went wrong; $phar is untouched then
     */
    public function install(string $tag, string $phar, string $php): void
    {
        $dir = \dirname($phar);
        if (!\is_writable($dir) || !\is_writable($phar)) {
            throw new \RuntimeException(\sprintf('%s is not writable — rerun with the rights to replace it (sudo, if you installed it system-wide).', $phar));
        }
        $scratch = \sys_get_temp_dir().'/porta-update-'.\bin2hex(\random_bytes(6));
        // @mago-expect lint:no-error-control-operator -- a scratch folder that cannot be made is reported by the exception, not a warning
        if (!@\mkdir($scratch) && !\is_dir($scratch)) {
            // an unwritable temp dir is environmental: the folder's name is fresh
            // @codeCoverageIgnoreStart
            throw new \RuntimeException(\sprintf('cannot create %s; nothing was changed.', $scratch));

            // @codeCoverageIgnoreEnd
        }
        try {
            $this->download($tag, $scratch);
            $new = $scratch.'/'.self::ASSET;
            // `sha256sum` output: the 64 hex digits, then the file name.
            $expected = \substr((string) \file_get_contents($scratch.'/'.self::ASSET.'.sha256'), offset: 0, length: 64);
            if (!\is_file($new) || \hash_file('sha256', $new) !== $expected) {
                throw new \RuntimeException('the downloaded phar does not match its checksum; nothing was changed.');
            }
            [$code] = ($this->run)([$php, $new, '--version']);
            if (0 !== $code) {
                throw new \RuntimeException('the downloaded phar does not run here; nothing was changed.');
            }
            $staged = $dir.'/.'.\basename($phar).'.new';
            // Checked whole beside the phar before it replaces it: a disk that
            // filled mid-copy must leave the old phar, not a truncated new one.
            // @mago-expect lint:no-error-control-operator -- a copy that fails warns as well as returning false; the checks below are what is reported
            $copied = @\copy($new, $staged) && \hash_file('sha256', $staged) === $expected;
            if (!$copied || !\chmod($staged, \fileperms($phar) & 0o777) || !\rename($staged, $phar)) {
                // A copy that landed half-way goes too.
                \array_map(\unlink(...), \array_filter([$staged], \is_file(...)));

                throw new \RuntimeException(\sprintf('%s could not be replaced (is the disk full?); nothing was changed.', $phar));
            }
        } finally {
            foreach ((array) \glob($scratch.'/*') as $file) {
                \unlink((string) $file);
            }
            \rmdir($scratch);
        }
    }

    /**
     * `v1.2.0-3-gabc` → `1.2.0`; anything without a leading DOTTED version →
     * `0`. The dot is what tells a version from a commit build: `1d2bb88b`
     * starts with a digit too, and read as version 1 it was offered nothing.
     */
    private static function release(string $version): string
    {
        $match = [];

        return 1 === \preg_match('/^v?(\d+(?:\.\d+)+)/', $version, $match) ? $match[1] : '0';
    }

    private function hasGh(): bool
    {
        [$code] = ($this->run)(['gh', '--version']);

        return 0 === $code;
    }

    /** $tag's phar and its checksum, into $dir. */
    private function download(string $tag, string $dir): void
    {
        if ($this->hasGh()) {
            [$code] = ($this->run)(['gh', 'release', 'download', $tag, '--repo', self::REPOSITORY, '--dir', $dir, '--pattern', self::ASSET, '--pattern', self::ASSET.'.sha256']);
            if (0 !== $code) {
                throw new \RuntimeException('gh could not download '.$tag.'; nothing was changed.');
            }

            return;
        }
        foreach ($this->assets($tag) as $name => $url) {
            if (!\in_array($name, [self::ASSET, self::ASSET.'.sha256'], strict: true)) {
                continue;
            }
            // curl drops the Authorization header on the redirect to the
            // storage host, which is what that host requires.
            [$code] = $this->curl(['-o', $dir.'/'.$name, '-H', 'Accept: application/octet-stream', $url]);
            if (0 !== $code) {
                throw new \RuntimeException('could not download '.$name.' of '.$tag.'; nothing was changed.');
            }
        }
    }

    /** @return array<string, string> $tag's asset names => their API download URLs */
    private function assets(string $tag): array
    {
        $release = $this->api('releases/tags/'.\rawurlencode($tag));
        $assets = [];
        foreach (Decoded::objects($release['assets'] ?? null) as $asset) {
            if (!\is_string($asset['name'] ?? null) || !\is_string($asset['url'] ?? null)) {
                continue;
            }
            $assets[$asset['name']] = $asset['url'];
        }

        return $assets;
    }

    /**
     * A GitHub REST call for this repository, authenticated with the token.
     *
     * @return array<array-key, mixed>
     */
    private function api(string $path): array
    {
        [$code, $out] = $this->curl(['-H', 'Accept: application/vnd.github+json', 'https://api.github.com/repos/'.self::REPOSITORY.'/'.$path]);
        // @mago-expect analysis:mixed-assignment -- json_decode is untyped; is_array() narrows it on the next line.
        // @noinspection JsonEncodingApiUsageInspection -- another program's output that is not JSON reads as none, it does not throw
        $decoded = 0 === $code ? \json_decode($out, associative: true) : null;
        if (!\is_array($decoded)) {
            throw new \RuntimeException('could not reach the releases of '.self::REPOSITORY.' — check GH_TOKEN, and that a release exists.');
        }

        return $decoded;
    }

    /**
     * `curl` with the token, handed over as a config on its stdin so the token
     * never appears in the process list.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function curl(array $args): array
    {
        $token = $this->token();

        // HTTPS only, redirects included: the token and the binary both ride it.
        return ($this->run)(['curl', '-fsSL', '--proto', '=https', '--proto-redir', '=https', '--config', '-', ...$args], 'header = "Authorization: Bearer '.$token.'"'."\n");
    }

    private function token(): string
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $name) {
            $token = $this->env[$name] ?? false;
            if (\is_string($token) && '' !== $token) {
                return $token;
            }
        }

        throw new \RuntimeException('self-update needs the gh CLI (logged in with `gh auth login`) or a GH_TOKEN with access to '.self::REPOSITORY.'.');
    }
}
