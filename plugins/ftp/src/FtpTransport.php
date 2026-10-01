<?php

declare(strict_types=1);

namespace Porta\FtpPlugin;

use Porta\Filesystem\CommandTransport;
use Porta\Filesystem\RemoteEntry;
use Porta\Filesystem\RemoteFile;
use Porta\Filesystem\RemoteSpec;
use Porta\Filesystem\RemoteStamp;

/**
 * A server's project over FTP — or FTPS, as explicit TLS on the FTP port
 * (`--ssl-reqd`), which is what servers that offer it mostly speak. Each verb
 * is one or a few `curl` runs; curl is on every machine Porta runs on, so
 * the plugin installs nothing.
 *
 * The password is in a netrc file only this user can read
 * ({@see Credentials}), never on a command line another user's `ps` would
 * show. A directory is listed with MLSD, whose sizes and times are exact and
 * in UTC — the same clock as the MDTM a file's stamp is read from, so a
 * listing and a fetch agree on when a file changed. A server without MLSD
 * says so instead of being half-understood.
 *
 * A file is written beside itself and renamed over it, so a reader never
 * sees half of it; whether it changed since it was fetched is asked first.
 * Two runs, not one atomic step — FTP has no compare-and-swap — so a write
 * in between them is the one change this cannot see.
 */
final class FtpTransport extends CommandTransport
{
    /** The name a file is uploaded under before it is renamed over the real one. */
    private const string PARTIAL = '.porta-upload';

    /**
     * @param string|null $netrc the credentials file; null logs in anonymously
     * @param string      $curl  the curl binary
     */
    public function __construct(
        private readonly RemoteSpec $spec,
        private readonly ?string $netrc,
        private readonly string $curl = 'curl',
    ) {}

    #[\Override]
    public function list(string $path, \Closure $done): void
    {
        $this->curl(['-X', 'MLSD'], $this->directoryUrl($path), static function (int $code, string $out, string $err) use ($done): void {
            $done(0 === $code ? self::entries($out) : self::failure($code, $err));
        });
    }

    #[\Override]
    public function fetch(string $path, \Closure $done): void
    {
        $this->stamp($path, function (RemoteStamp|string $stamp) use ($path, $done): void {
            if (\is_string($stamp)) {
                $done($stamp);

                return;
            }
            $this->curl([], $this->url($path), static function (int $code, string $out, string $err) use ($stamp, $done): void {
                $done(0 === $code ? new RemoteFile($out, $stamp) : self::failure($code, $err));
            });
        });
    }

    #[\Override]
    public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void
    {
        if (null === $expected) {
            $this->upload($path, $bytes, $done);

            return;
        }
        $this->stamp($path, function (RemoteStamp|string $now) use ($path, $bytes, $expected, $done): void {
            // Gone, or another size or time: changed since it was fetched.
            if (\is_string($now) || !$now->equals($expected)) {
                $done(null);

                return;
            }
            $this->upload($path, $bytes, $done);
        });
    }

    #[\Override]
    public function mkdir(string $path, \Closure $done): void
    {
        $this->quote(self::ancestry($this->spec->remotePath($path)), $done);
    }

    #[\Override]
    public function rename(string $from, string $to, \Closure $done): void
    {
        $target = $this->spec->remotePath($to);
        $this->quote([...self::ancestry(\dirname($target)), 'RNFR '.$this->spec->remotePath($from), 'RNTO '.$target], $done);
    }

    /**
     * FTP deletes only an empty directory: what it holds goes first, one
     * entry at a time, listed as it goes.
     */
    #[\Override]
    // @mago-expect lint:no-boolean-flag-parameter -- the transport contract's own signature; FTP has a verb for each
    public function remove(string $path, bool $isDir, \Closure $done): void
    {
        if (!$isDir) {
            $this->quote(['DELE '.$this->spec->remotePath($path)], $done);

            return;
        }
        $this->list($path, function (array|string $entries) use ($path, $done): void {
            if (\is_string($entries)) {
                $done($entries);

                return;
            }
            /** @var list<RemoteEntry> $entries */
            $this->removeEach($path, $entries, $done);
        });
    }

    /**
     * The curl command line for $options against $url, with the credentials
     * and the TLS the spec asks for.
     *
     * @param list<string> $options
     *
     * @return list<string>
     */
    public function command(array $options, string $url): array
    {
        $auth = null === $this->netrc ? [] : ['--netrc-file', $this->netrc];
        $tls = 'ftps' === $this->spec->scheme ? ['--ssl-reqd'] : [];

        return [$this->curl, '--silent', '--show-error', ...$auth, ...$tls, ...$options, '--', $url];
    }

    /**
     * The URL of $path. FTP URLs are relative to the login directory, so an
     * absolute path starts with an encoded `/` — `%2F` — which curl reads as
     * "from the root".
     */
    public function url(string $path): string
    {
        $remote = $this->spec->remotePath($path);
        $segments = \array_map(\rawurlencode(...), \array_values(\array_filter(\explode('/', $remote), static fn (string $segment): bool => '' !== $segment && '.' !== $segment)));
        $segments = \str_starts_with($remote, '/') ? ['%2F', ...$segments] : $segments;
        $port = null === $this->spec->port ? '' : ':'.$this->spec->port;

        return 'ftp://'.$this->spec->host.$port.'/'.\implode('/', $segments);
    }

    /** The URL of directory $path: a trailing `/` is what tells curl it is one. */
    public function directoryUrl(string $path): string
    {
        $url = $this->url($path);

        return \str_ends_with($url, '/') ? $url : $url.'/';
    }

    /**
     * @param list<RemoteEntry>       $entries
     * @param \Closure(?string): void $done
     */
    private function removeEach(string $dir, array $entries, \Closure $done): void
    {
        $entry = \array_shift($entries);
        if (null === $entry) {
            $this->quote(['RMD '.$this->spec->remotePath($dir)], $done);

            return;
        }
        $this->remove($dir.'/'.$entry->name, $entry->isDir, function (?string $error) use ($dir, $entries, $done): void {
            null === $error ? $this->removeEach($dir, $entries, $done) : $done($error);
        });
    }

    /**
     * Upload to a partial name beside $path, then rename it over $path — the
     * renames run after the transfer, in the directory it went to — and
     * answer the stamp it then has.
     *
     * @param \Closure(RemoteStamp|string|null): void $done
     */
    private function upload(string $path, string $bytes, \Closure $done): void
    {
        $name = self::PARTIAL.'-'.\basename($path);
        $partial = (\str_contains($path, '/') ? \dirname($path).'/' : '').$name;
        $options = ['--ftp-create-dirs', '--upload-file', '-', '--quote', '-RNFR '.$name, '--quote', '-RNTO '.\basename($path)];
        $this->guarded([$path], $done, fn () => $this->run($this->command($options, $this->url($partial)), $bytes, function (int $code, string $out, string $err) use ($path, $done): void {
            if (0 !== $code) {
                $done(self::failure($code, $err));

                return;
            }
            $this->stamp($path, $done);
        }));
    }

    /**
     * Send FTP commands — `*`-prefixed ones may fail — before an empty
     * transfer of the root.
     *
     * @param list<string>            $commands
     * @param \Closure(?string): void $done
     */
    private function quote(array $commands, \Closure $done): void
    {
        $options = [];
        foreach ($commands as $command) {
            $options = [...$options, '--quote', $command];
        }
        $this->guarded($commands, $done, fn () => $this->curl([...$options, '--list-only', '--output', '/dev/null'], $this->directoryUrl(''), static function (
            int $code,
            string $out,
            string $err,
        ) use ($done): void {
            $done(0 === $code ? null : self::failure($code, $err));
        }));
    }

    /**
     * A line break in a name would end an FTP command early and start
     * another — the server's, not the user's — so a name holding one is
     * refused before anything is sent.
     *
     * @param list<string>     $words
     * @param \Closure(string): void $done
     * @param \Closure(): void $send
     */
    private function guarded(array $words, \Closure $done, \Closure $send): void
    {
        if ([] !== \array_filter($words, static fn (string $word): bool => 1 === \preg_match('/[\r\n]/', $word))) {
            $done('a name with a line break in it cannot be sent over FTP');

            return;
        }
        $send();
    }

    /**
     * $path's stamp, from the SIZE and MDTM curl asks for with `--head`.
     *
     * @param \Closure(RemoteStamp|string): void $done
     */
    private function stamp(string $path, \Closure $done): void
    {
        $this->curl(['--head'], $this->url($path), static function (int $code, string $out, string $err) use ($done): void {
            [$length, $modified] = [[], []];
            $size = 1 === \preg_match('/^Content-Length:\s*(\d+)/mi', $out, $length) ? (int) $length[1] : null;
            $time = 1 === \preg_match('/^Last-Modified:\s*(.+?)\s*$/mi', $out, $modified) ? \strtotime($modified[1]) : false;
            $done(match (true) {
                0 !== $code => self::failure($code, $err),
                null === $size || false === $time => 'the server did not say the size and time of the file',
                default => new RemoteStamp($size, $time),
            });
        });
    }

    /**
     * @param list<string>                         $options
     * @param \Closure(int, string, string): void $done
     */
    private function curl(array $options, string $url, \Closure $done): void
    {
        $this->run($this->command($options, $url), null, $done);
    }

    /**
     * The MKD for every directory down to $path — each allowed to fail,
     * since most are already there — so a deep one can be made at once.
     *
     * @return list<string>
     */
    private static function ancestry(string $path): array
    {
        $commands = [];
        $built = \str_starts_with($path, '/') ? '' : '.';
        foreach (\array_filter(\explode('/', $path), static fn (string $segment): bool => '' !== $segment && '.' !== $segment) as $segment) {
            $built .= '/'.$segment;
            $commands[] = '*MKD '.('.' === $built[0] ? \substr($built, offset: 2) : $built);
        }

        return $commands;
    }

    /**
     * An MLSD listing's entries: `fact=value;…; name` per line, the type and
     * size and modify facts read, `.` and `..` (`cdir`, `pdir`) left out.
     *
     * @return list<RemoteEntry>
     */
    private static function entries(string $listing): array
    {
        $entries = [];
        foreach (\explode("\n", \str_replace("\r", replace: '', subject: $listing)) as $line) {
            $parts = \explode(' ', $line, limit: 2);
            if (2 !== \count($parts)) {
                continue;
            }
            /** @var array<string, string> $facts */
            $facts = [];
            foreach (\explode(';', $parts[0]) as $fact) {
                $pair = \explode('=', $fact, limit: 2);
                $facts[\strtolower($pair[0])] = $pair[1] ?? '';
            }
            $type = \strtolower($facts['type'] ?? '');
            if ('file' !== $type && 'dir' !== $type) {
                continue;
            }
            $entries[] = new RemoteEntry($parts[1], 'dir' === $type, new RemoteStamp((int) ($facts['size'] ?? 0), self::time($facts['modify'] ?? '')));
        }

        return $entries;
    }

    /** An MLSD `modify` fact — `YYYYMMDDHHMMSS[.sss]`, UTC — as a timestamp; 0 when it is not one. */
    private static function time(string $modify): int
    {
        $time = \DateTimeImmutable::createFromFormat('!YmdHis', \substr($modify, offset: 0, length: 14), new \DateTimeZone('UTC'));

        return false === $time ? 0 : $time->getTimestamp();
    }
}
