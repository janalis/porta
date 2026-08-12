<?php

declare(strict_types=1);

namespace Porta\Support;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Reads a JSON file into an array, tolerating a missing file, unreadable
 * contents or malformed JSON by returning null. Centralizes the
 * `json_decode(file_get_contents(...), true, …, JSON_THROW_ON_ERROR)` idiom that
 * was repeated across config/session/run loaders. {@see write()} is the same
 * service for the write half.
 */
final class JsonFile
{
    /**
     * @return array<mixed>|null decoded JSON object/array, or null when the file
     *                           is absent, invalid JSON, or not a JSON array/object
     */
    public static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            /** @var array<array-key, mixed>|scalar|null $data */
            $data = json_decode((string) file_get_contents($path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * Atomically write $data as pretty-printed JSON (unescaped slashes);
     * false when the encode or the write fails. Centralizes the write idiom
     * the persistence stores each hand-rolled — a spread that let two of them
     * ship catching only the IO half, where an unencodable payload (invalid
     * UTF-8 in a stored path) crashed instead of degrading. Best-effort by
     * design: a store that cannot persist keeps working from memory.
     *
     * @param array<array-key, mixed> $data
     */
    public static function write(Filesystem $fs, string $path, array $data, bool $trailingNewline = false): bool
    {
        try {
            $fs->dumpFile($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES).($trailingNewline ? \PHP_EOL : ''));
        } catch (IOExceptionInterface|\JsonException) {
            return false;
        }

        return true;
    }
}
