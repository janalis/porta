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
     * @param string|null $error why decoding produced null, when it did — the decoder's own
     *                           reason ("Syntax error", …; PHP reports no position) or the
     *                           not-an-object case. Untouched when the file is simply absent.
     *
     * @return array<mixed>|null decoded JSON object/array, or null when the file
     *                           is absent, invalid JSON, or not a JSON array/object
     */
    public static function read(string $path, ?string &$error = null): ?array
    {
        if (!\is_file($path)) {
            return null;
        }
        try {
            /** @var array<array-key, mixed>|scalar|null $data */
            $data = \json_decode((string) \file_get_contents($path), associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                $error = 'the file is not a JSON object';
                $data = null;
            }
        } catch (\JsonException $e) {
            $error = $e->getMessage();
            $data = null;
        }

        return $data;
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
    public static function write(Filesystem $fs, string $path, array $data): bool
    {
        return self::dump($fs, $path, $data, '');
    }

    /**
     * {@see write()} for a file a person edits too — `.porta.json`,
     * `composer.json` — which ends in a newline the way an editor leaves it,
     * so the next hand edit's diff is not a "no newline at end of file".
     *
     * @param array<array-key, mixed> $data
     */
    public static function writeEditable(Filesystem $fs, string $path, array $data): bool
    {
        return self::dump($fs, $path, $data, \PHP_EOL);
    }

    /** @param array<array-key, mixed> $data */
    private static function dump(Filesystem $fs, string $path, array $data, string $tail): bool
    {
        try {
            $fs->dumpFile($path, \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES).$tail);
        } catch (IOExceptionInterface|\JsonException) {
            return false;
        }

        return true;
    }
}
