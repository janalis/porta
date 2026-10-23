<?php

declare(strict_types=1);

namespace Porta\Install;

/**
 * The `porta` command on PATH, as the Command line page of the settings manages
 * it: a SYMLINK to the running binary, `~/.local/bin/porta` unless one is
 * already somewhere on PATH.
 *
 * Only a symlink is Porta's to change. A regular file named `porta` — a phar
 * copied into place, Composer's `vendor/bin` proxy — was put there some other
 * way and is left to that way ({@see CommandLinkStatus::Foreign}). A link, on
 * the other hand, can only have come from a `ln -s` like the one this makes,
 * and one that points elsewhere (a checkout since moved, another phar) is
 * exactly what an update is for.
 */
final readonly class CommandLink
{
    public const string NAME = 'porta';

    /**
     * @param string $binary what the link points at ({@see PortaBinary::path()})
     * @param string $path   the PATH to look along
     * @param string $home   whose `~/.local/bin` a new link goes into
     */
    public function __construct(
        private string $binary,
        private string $path,
        private string $home,
    ) {}

    public function status(): CommandLinkStatus
    {
        $location = $this->location();

        return match (true) {
            null === $location => CommandLinkStatus::Absent,
            !\is_link($location) => CommandLinkStatus::Foreign,
            \realpath($location) === \realpath($this->binary) => CommandLinkStatus::Current,
            default => CommandLinkStatus::Stale,
        };
    }

    /** Where the link is, or goes: the `porta` already there, else `~/.local/bin/porta`. */
    public function target(): string
    {
        return $this->location() ?? \rtrim($this->home, characters: '/').'/.local/bin/'.self::NAME;
    }

    /** What a link that is not {@see CommandLinkStatus::Current} points at now; '' when there is none. */
    public function pointsAt(): string
    {
        $location = $this->location();

        return null !== $location && \is_link($location) ? (string) \readlink($location) : '';
    }

    /** Whether the target's directory is on PATH — a link in `~/.local/bin` is no command otherwise. */
    public function onPath(): bool
    {
        return \in_array(\dirname($this->target()), $this->directories(), strict: true);
    }

    /**
     * Link (or re-link) the target to the running binary: a new link beside
     * it, renamed over, so there is never a moment without one. Refuses a
     * {@see CommandLinkStatus::Foreign} file. Null on success, else why not.
     */
    public function link(): ?string
    {
        if (CommandLinkStatus::Foreign === $this->status()) {
            return $this->target().' is not a link Porta made';
        }
        $target = $this->target();
        $dir = \dirname($target);
        // @mago-expect lint:no-error-control-operator -- a directory it may not create is reported by the return value below, not as a warning.
        $ready = \is_dir($dir) || @\mkdir($dir, permissions: 0o755, recursive: true) || \is_dir($dir);

        return $ready ? $this->relink($dir, $target) : 'could not create '.$dir;
    }

    /** A new link in $dir, renamed over $target. Null on success, else why not. */
    private function relink(string $dir, string $target): ?string
    {
        $staged = $dir.'/.'.self::NAME.'.'.\bin2hex(\random_bytes(4));
        // The rename onto the target fails only if the directory changed since
        // the symlink went in; it then warns, leaves the staged link, and is
        // reported the same way.
        // @mago-expect lint:no-error-control-operator -- likewise a directory it may not write to.
        $linked = @\symlink($this->binary, $staged) && \rename($staged, $target);

        return $linked ? null : 'could not write to '.$dir;
    }

    /** Remove the link — only ever a link. Null on success, else why not. */
    public function unlink(): ?string
    {
        $location = $this->location();
        if (null === $location || !\is_link($location)) {
            return 'there is no porta link to remove';
        }

        // @mago-expect lint:no-error-control-operator -- likewise a link it may not remove.
        return @\unlink($location) ? null : 'could not remove '.$location;
    }

    /** The first `porta` along PATH — a dangling link included, which is what a moved checkout leaves — else the default target when that exists off PATH. */
    private function location(): ?string
    {
        $fallback = \rtrim($this->home, characters: '/').'/.local/bin';
        foreach ([...$this->directories(), $fallback] as $dir) {
            $candidate = $dir.'/'.self::NAME;
            if (\is_link($candidate) || \is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function directories(): array
    {
        return \array_values(\array_filter(
            \array_map(static fn (string $dir): string => \rtrim($dir, characters: '/'), \explode(\PATH_SEPARATOR, $this->path)),
            static fn (string $dir): bool => '' !== $dir,
        ));
    }
}
