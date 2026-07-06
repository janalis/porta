<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Helpers for classifying raw terminal input bytes.
 */
final class TextInput
{
    /**
     * Whether $data is insertable text: non-empty, not an escape sequence, and
     * free of C0/C1 control characters. Shared by the single-line input widgets
     * (find, go-to-file, results query) and the editor's prompt capture.
     */
    public static function isPrintable(string $data): bool
    {
        return '' !== $data && !str_contains($data, "\x1b") && !preg_match('/[\x00-\x1F\x7F]/', $data);
    }
}
