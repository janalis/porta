<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/**
 * Where a remote project lives: a scheme, a server and the project's
 * directory on it. Never a password — the transport that needs one keeps it
 * where it keeps secrets, and a spec is written to disk as it is.
 *
 * Read from what a person types: `user@host:/path` and `host:path` (scp's
 * shape, so ssh), or a URL — `ssh://`, `sftp://`, `ftp://`, `ftps://`, with
 * an optional `:port`. A relative scp path is the login directory's, as scp
 * reads it, and is kept relative for the server to resolve: `.` is the login
 * directory itself, and a URL spells a relative path `/~/path`.
 */
final readonly class RemoteSpec
{
    public const array SCHEMES = ['ssh', 'sftp', 'ftp', 'ftps'];

    public function __construct(
        public string $scheme,
        public string $host,
        public string $path,
        public ?string $user = null,
        public ?int $port = null,
    ) {}

    /** The spec $input names, or null when it names none. */
    public static function parse(string $input): ?self
    {
        $input = \trim($input);
        $scheme = \strstr($input, needle: '://', before_needle: true);

        return false !== $scheme ? self::fromUrl(\strtolower($scheme), $input) : self::fromScp($input);
    }

    /**
     * scp's `[user@]host:path` — the colon before any slash is what makes it
     * remote, which is also how scp tells it from a local path.
     */
    private static function fromScp(string $input): ?self
    {
        $parts = [];
        if (1 !== \preg_match('#^(?:([^@/:\s]+)@)?([^@/:\s]+):(.*)$#', $input, $parts)) {
            return null;
        }

        // `~/x` is how a person writes the login directory's `x`; quoted for
        // the server's shell it would be a directory named `~`.
        $path = \preg_replace('#^~(?:/|$)#', replacement: '', subject: self::trimmed($parts[3])) ?? '';

        return new self('ssh', $parts[2], '' === $path ? '.' : $path, '' === $parts[1] ? null : $parts[1]);
    }

    private static function fromUrl(string $scheme, string $url): ?self
    {
        $parts = \parse_url($url);
        $host = false === $parts ? '' : $parts['host'] ?? '';
        if ('' === $host || false === $parts || !\in_array($scheme, self::SCHEMES, strict: true)) {
            return null;
        }
        $user = \rawurldecode($parts['user'] ?? '');
        $path = self::trimmed(\rawurldecode($parts['path'] ?? '/'));
        $path = match (true) {
            '' === $path => '/', // `//` trims to nothing, and is still the root
            '/~' === $path => '.',
            \str_starts_with($path, '/~/') => \substr($path, offset: 3),
            default => $path,
        };

        return new self($scheme, $host, $path, '' === $user ? null : $user, $parts['port'] ?? null);
    }

    /** A trailing slash names the same directory; `/` alone is the root and stays. */
    private static function trimmed(string $path): string
    {
        return '/' === $path ? $path : \rtrim($path, characters: '/');
    }

    /** The canonical URL — what is stored, and what two specs are the same project by. */
    public function url(): string
    {
        $user = null === $this->user ? '' : \rawurlencode($this->user).'@';
        $port = null === $this->port ? '' : ':'.$this->port;
        $path = match (true) {
            \str_starts_with($this->path, '/') => $this->path,
            '.' === $this->path => '/~',
            default => '/~/'.$this->path,
        };

        return $this->scheme.'://'.$user.$this->host.$port.\implode('/', \array_map(\rawurlencode(...), \explode('/', $path)));
    }

    /** The short name a person knows it by, for titles and lists. */
    public function label(): string
    {
        $user = null === $this->user ? '' : $this->user.'@';

        return $user.$this->host.':'.$this->path;
    }

    /** The folder the project is named by: the last segment of its path, or the host's for a root. */
    public function name(): string
    {
        $name = \basename($this->path);

        return \in_array($name, ['', '/', '.', '..', '~'], strict: true) ? $this->host : $name;
    }

    /** A stable, filesystem-safe id for the project — the folder its local copy lives in. */
    public function id(): string
    {
        return \substr(\hash('xxh128', $this->url()), offset: 0, length: 16);
    }

    /**
     * $relative, a path inside the project, as the server names it.
     */
    public function remotePath(string $relative): string
    {
        return match (true) {
            '' === $relative => $this->path,
            '.' === $this->path => $relative,
            '/' === $this->path => '/'.$relative,
            default => $this->path.'/'.$relative,
        };
    }
}
