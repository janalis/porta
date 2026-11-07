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
     * {@see write()} for a file of Porta's own in the project $root, which is
     * not written through a link ({@see Files::throughLink()}), nor into a
     * project that is gone — moved or deleted while open, it was made again,
     * holding nothing but the file: false then, as for any write that fails.
     *
     * @param array<array-key, mixed> $data
     */
    public static function writeInProject(Filesystem $fs, string $root, string $path, array $data): bool
    {
        return \is_dir($root) && !Files::throughLink($root, $path) && self::write($fs, $path, $data);
    }

    /** Remove what {@see writeInProject()} wrote — never through a link either, which would remove what it points into. */
    public static function removeInProject(Filesystem $fs, string $root, string $path): void
    {
        if (!Files::throughLink($root, $path)) {
            $fs->remove($path);
        }
    }

    /**
     * {@see write()} for a file a person edits too — `.porta.json`,
     * `composer.json` — which ends in a newline the way an editor leaves it,
     * so the next hand edit's diff is not a "no newline at end of file".
     *
     * And which keeps the shape the person gave it, so changing one setting
     * changes one line: an empty object the file had (`"env": {}`) stays an
     * object — decoded to an array, it would come back `[]`, which reads as a
     * list and which composer refuses — and the file's own indent stays its
     * own, where PHP only knows four spaces.
     *
     * @param array<array-key, mixed> $data
     */
    public static function writeEditable(Filesystem $fs, string $path, array $data): bool
    {
        $before = \is_file($path) ? (string) \file_get_contents($path) : '';
        try {
            $shape = self::node(\json_decode($before, associative: false, flags: \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            $shape = null;
        }
        $m = [];
        $indent = 1 === \preg_match('/^\{\R([ \t]+)"/', $before, $m) ? $m[1] : '    ';

        return self::dump($fs, $path, self::shaped($data, $shape), \PHP_EOL, $indent);
    }

    /**
     * $data with an empty array turned back into an object wherever $shape —
     * the same document as it was decoded with objects — held one.
     *
     * @param array<array-key, mixed>|\stdClass|null $shape
     */
    private static function shaped(mixed $data, array|\stdClass|null $shape): mixed
    {
        return match (true) {
            [] === $data && $shape instanceof \stdClass => new \stdClass(),
            \is_array($data) => self::shapedChildren($data, $shape),
            default => $data,
        };
    }

    /**
     * @param array<array-key, mixed>          $data
     * @param array<array-key, mixed>|\stdClass|null $shape
     *
     * @return array<array-key, mixed>
     */
    private static function shapedChildren(array $data, array|\stdClass|null $shape): array
    {
        $children = $shape instanceof \stdClass ? \get_object_vars($shape) : $shape ?? [];
        foreach (\array_keys($data) as $key) {
            $data[$key] = self::shaped($data[$key], self::node($children[$key] ?? null));
        }

        return $data;
    }

    /**
     * A decoded value as {@see shaped()} walks it: what can hold children.
     *
     * @return array<array-key, mixed>|\stdClass|null
     */
    private static function node(mixed $value): array|\stdClass|null
    {
        return $value instanceof \stdClass || \is_array($value) ? $value : null;
    }

    private static function dump(Filesystem $fs, string $path, mixed $data, string $tail, string $indent = '    '): bool
    {
        try {
            $json = \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            if ('    ' !== $indent) {
                // Every line break in the output is structure — a newline in a
                // string is escaped — so each run of leading four-space steps is
                // one level deep.
                $json = (string) \preg_replace_callback('/^(?:    )+/m', static fn (array $m): string => \str_replace('    ', $indent, $m[0]), $json);
            }
            $fs->dumpFile($path, $json.$tail);
        } catch (IOExceptionInterface|\JsonException) {
            return false;
        }

        return true;
    }
}
