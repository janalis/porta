<?php

declare(strict_types=1);

namespace Porta\Input;

use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Helpers for classifying raw terminal input bytes.
 */
final class TextInput
{
    private const string PASTE_START = "\x1b[200~";

    private const string PASTE_END = "\x1b[201~";

    /**
     * Whether $keys is insertable text: non-empty, not an escape sequence, and
     * free of C0/C1 control characters. Shared by the single-line input widgets
     * (find, go-to-file, results query) and the editor's prompt capture.
     */
    public static function isPrintable(string $keys): bool
    {
        // ESC is a C0 control too, so this one test also turns away every
        // escape sequence — the vendor field's own rule, shared.
        return '' !== $keys && !StringUtils::hasControlChars($keys);
    }

    /**
     * The text of a bracketed paste, or null when $data is not one. The
     * terminal wraps the whole clipboard in one chunk (the vendor Terminal
     * re-wraps it after its stdin buffer), which isPrintable() refuses for the
     * ESC in its markers. Only text survives: invalid UTF-8 and every control
     * byte go, line breaks normalize to "\n" and tabs to a space, so a pasted
     * escape sequence arrives as inert characters, never as a colour or a
     * cursor move in the field.
     */
    public static function pasted(string $data): ?string
    {
        if (!\str_starts_with($data, self::PASTE_START)) {
            return null;
        }
        $text = \substr($data, \strlen(self::PASTE_START));
        if (\str_ends_with($text, self::PASTE_END)) {
            $text = \substr($text, offset: 0, length: -\strlen(self::PASTE_END));
        }
        $text = \str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], StringUtils::sanitizeUtf8($text));

        return StringUtils::stripControlBytes($text);
    }
}
