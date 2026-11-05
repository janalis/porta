<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Support\Decoded;
use Porta\Support\Files;

/**
 * `porta --self-update`: replace the running phar with the latest GitHub
 * release of {@see REPOSITORY}.
 *
 * The releases are public and read through the REST API; a `GH_TOKEN` or
 * `GITHUB_TOKEN` in the environment is sent along when there is one, which
 * only lifts the anonymous rate limit. Each release carries `porta.phar` and
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

    /** curl's config on stdin for a request without the token: none. */
    private const string ANONYMOUS = '';

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
     * @throws \RuntimeException with a sentence saying what went wrong when the releases cannot be read
     */
    public function latest(): string
    {
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
        if (!Files::ensureDir($scratch)) {
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

    /** $tag's phar and its checksum, into $dir. */
    private function download(string $tag, string $dir): void
    {
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
     * A GitHub REST call for this repository, with the token when there is one.
     *
     * @return array<array-key, mixed>
     */
    private function api(string $path): array
    {
        [$code, $out] = $this->curl(['-H', 'Accept: application/vnd.github+json', 'https://api.github.com/repos/'.self::REPOSITORY.'/'.$path]);
        $decoded = 0 === $code ? Decoded::json($out) : null;
        if (null === $decoded) {
            throw new \RuntimeException('could not reach the releases of '.self::REPOSITORY.' — check the connection, and that a release exists.');
        }

        return $decoded;
    }

    /**
     * `curl`, with the token (if any) handed over as a config on its stdin so
     * it never appears in the process list.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function curl(array $args): array
    {
        $token = $this->token();
        // HTTPS only, redirects included: the token and the binary both ride it.
        $command = ['curl', '-fsSL', '--proto', '=https', '--proto-redir', '=https', '--config', '-', ...$args];
        $result = ($this->run)($command, null === $token ? self::ANONYMOUS : 'header = "Authorization: Bearer '.$token.'"'."\n");

        // A token GitHub refuses — expired, revoked, scoped to another owner —
        // is no reason to fail on releases anyone may read: ask again without it.
        return 0 !== $result[0] && null !== $token ? ($this->run)($command, self::ANONYMOUS) : $result;
    }

    private function token(): ?string
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $name) {
            $token = $this->env[$name] ?? false;
            if (\is_string($token) && '' !== $token) {
                return $token;
            }
        }

        return null;
    }
}
