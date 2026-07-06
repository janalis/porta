<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * Reads a JSON file into an array, tolerating a missing file, unreadable
 * contents or malformed JSON by returning null. Centralizes the
 * `json_decode(file_get_contents(...), true, …, JSON_THROW_ON_ERROR)` idiom that
 * was repeated across config/session/run loaders.
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
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }
}
