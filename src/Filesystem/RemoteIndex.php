<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What a server said about a remote project's paths: per relative path,
 * whether it is a directory, a file's stamp, and whether the file's content
 * is in the cache or only its placeholder.
 *
 * Kept in the cache's own `.porta/` and read back next session, so a
 * placeholder is never mistaken for an empty file after a restart. A path it
 * does not know was never the server's — something made here, which nothing
 * fetches over.
 *
 * Changes are kept in memory until {@see save()}: a walk of the whole tree
 * records thousands of paths, and writing the file for each would make that
 * quadratic.
 */
final class RemoteIndex
{
    /** @var array<array-key, array{dir: bool, size: int, mtime: int, fetched: bool}>|null null until first asked; a path of digits is an int key */
    private ?array $entries = null;

    public function __construct(
        private readonly string $file,
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    /** @return array{dir: bool, size: int, mtime: int, fetched: bool}|null */
    public function get(string $relative): ?array
    {
        return $this->entries()[$relative] ?? null;
    }

    /** Whether $relative is a file whose content was never fetched — only its placeholder is here. */
    public function isPlaceholder(string $relative): bool
    {
        $entry = $this->get($relative);

        return null !== $entry && !$entry['dir'] && !$entry['fetched'];
    }

    /** The stamp $relative was fetched or last uploaded at, or null when its content is not here. */
    public function stamp(string $relative): ?RemoteStamp
    {
        $entry = $this->get($relative);

        return null === $entry || !$entry['fetched'] ? null : new RemoteStamp($entry['size'], $entry['mtime']);
    }

    public function directory(string $relative): void
    {
        $this->set($relative, ['dir' => true, 'size' => 0, 'mtime' => 0, 'fetched' => true]);
    }

    /** File $relative is at $stamp on the server, and its content is here or ($fetched false) only its placeholder. */
    public function file(string $relative, RemoteStamp $stamp, bool $fetched): void
    {
        $this->set($relative, ['dir' => false, 'size' => $stamp->size, 'mtime' => $stamp->mtime, 'fetched' => $fetched]);
    }

    /** $from, and everything under it, is now at $to. */
    public function move(string $from, string $to): void
    {
        foreach ($this->entries() as $relative => $entry) {
            $moved = self::moved((string) $relative, $from, $to); // a folder named `2024` is an int key
            if (null !== $moved) {
                unset($this->entries[$relative]);
                $this->entries[$moved] = $entry;
            }
        }
    }

    /** $relative, and everything under it, is gone. */
    public function forget(string $relative): void
    {
        $this->entries = \array_filter($this->entries(), static fn (int|string $known): bool => null === self::moved((string) $known, $relative, $relative), \ARRAY_FILTER_USE_KEY);
    }

    /** $relative re-rooted from $from to $to, or null when it is not $from or under it. */
    public static function moved(string $relative, string $from, string $to): ?string
    {
        return $relative === $from || \str_starts_with($relative, $from.'/') ? $to.\substr($relative, \strlen($from)) : null;
    }

    /** @param array{dir: bool, size: int, mtime: int, fetched: bool} $entry */
    private function set(string $relative, array $entry): void
    {
        $this->entries();
        $this->entries[$relative] = $entry;
    }

    /** Write what changed since the last save. */
    public function save(): void
    {
        JsonFile::write($this->fs, $this->file, $this->entries ?? []);
    }

    /** @return array<array-key, array{dir: bool, size: int, mtime: int, fetched: bool}> */
    private function entries(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }
        $this->entries = [];
        // @mago-expect analysis:mixed-assignment -- a decoded file: each entry is narrowed below
        foreach (JsonFile::read($this->file) ?? [] as $relative => $entry) {
            // A path of digits (`2024/`) decodes as an int key, and is a path all the same.
            if (!\is_array($entry)) {
                continue;
            }
            $this->entries[$relative] = [
                'dir' => true === ($entry['dir'] ?? null),
                'size' => \is_int($entry['size'] ?? null) ? $entry['size'] : 0,
                'mtime' => \is_int($entry['mtime'] ?? null) ? $entry['mtime'] : 0,
                'fetched' => true === ($entry['fetched'] ?? null),
            ];
        }

        return $this->entries;
    }
}
