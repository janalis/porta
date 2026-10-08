<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * The file paths a drop from outside the IDE carried, or null when what
 * arrived is not one.
 *
 * A terminal has two ways to deliver a drop. Every one of them PASTES it: the
 * paths typed out as a shell would want them — `/a\ b.php`, `'/a b.php'`,
 * several separated by spaces, a few as `file://` URLs, one per line in
 * others. kitty's drag-and-drop protocol hands over a `text/uri-list` instead:
 * one URL a line, `#` lines being comments ({@see fromUriList()}).
 *
 * Either way it is believed only when EVERY token names something that exists
 * on disk, absolutely. That is the whole distinction between a drop and a
 * paste — the bytes are the same — and the strict reading is what keeps a
 * pasted sentence, a pasted path to a file that is not there, or a pasted
 * relative path a paste: the cost of a drop read as a paste is inserting its
 * text, which was the behaviour before drops existed; the other way round
 * would open or copy files nobody asked for.
 */
final class DroppedPaths
{
    /**
     * Beyond this a paste is text: no drop of a sane number of files is this
     * long, and every token of one would be stat'ed before the answer came.
     */
    private const int MAX_BYTES = 65_536;

    /**
     * The paths a bracketed paste named, in order, spelled as dropped — not
     * realpath'd: a symlink dropped is the link, and a path typed into a
     * field is the one the user saw. What needs a canonical spelling asks
     * for one ({@see \Porta\Workbench\ExternalDrop}).
     *
     * @return non-empty-list<string>|null
     */
    public static function parse(string $text): ?array
    {
        if (\strlen($text) > self::MAX_BYTES) {
            return null;
        }
        $tokens = self::shellWords($text);

        return null === $tokens ? null : self::resolve($tokens);
    }

    /**
     * The paths a `text/uri-list` named (RFC 2483): one URL a line, `#` lines
     * comments, CRLF the spec's line end and LF what most senders write.
     *
     * @return non-empty-list<string>|null
     */
    public static function fromUriList(string $list): ?array
    {
        $tokens = [];
        $lines = \preg_split('/\r?\n/', $list);
        foreach (false === $lines ? [] : $lines as $line) {
            $line = \trim($line);
            if ('' !== $line && !\str_starts_with($line, '#')) {
                $tokens[] = $line;
            }
        }

        return self::resolve($tokens);
    }

    /**
     * Split as a POSIX shell would, which is how terminals quote a dropped
     * path: unquoted blanks and newlines separate, a backslash takes the next
     * byte as itself, single quotes take everything up to the next one, and
     * double quotes the same with backslash escapes inside.
     *
     * Null for a quote left open — that is a fragment of text, not a list.
     *
     * @return list<string>|null
     */
    private static function shellWords(string $text): ?array
    {
        $tokens = [];
        $current = '';
        $inToken = false;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $byte = $text[$i];
            if (\str_contains(" \t\r\n", $byte)) {
                if ($inToken) {
                    $tokens[] = $current;
                }
                $current = '';
                $inToken = false;

                continue;
            }
            $inToken = true;
            if ('\\' === $byte) {
                $current .= $text[++$i] ?? '';

                continue;
            }
            if ("'" !== $byte && '"' !== $byte) {
                $current .= $byte;

                continue;
            }
            $close = self::closingQuote($text, $i, $byte);
            if (null === $close) {
                return null;
            }
            $inner = \substr($text, $i + 1, $close - $i - 1);
            $current .= '"' === $byte ? (string) \preg_replace('/\\\\(.)/s', replacement: '$1', subject: $inner) : $inner;
            $i = $close;
        }
        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /** Where the quote opened at $open closes, a backslash escaping it inside double quotes only. */
    private static function closingQuote(string $text, int $open, string $quote): ?int
    {
        $length = \strlen($text);
        for ($i = $open + 1; $i < $length; ++$i) {
            if ('"' === $quote && '\\' === $text[$i]) {
                ++$i;

                continue;
            }
            if ($quote === $text[$i]) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     *
     * @return non-empty-list<string>|null
     */
    private static function resolve(array $tokens): ?array
    {
        $paths = [];
        foreach ($tokens as $token) {
            $path = self::fromUrl($token);
            // A dangling symlink is a name in a folder too, and is dropped as one.
            if (!\str_starts_with($path, '/') || (!\file_exists($path) && !\is_link($path))) {
                return null;
            }
            $paths[] = $path;
        }

        return [] === $paths ? null : $paths;
    }

    /**
     * A `file:` URL's path, percent-decoded; anything else as it came.
     *
     * The authority is dropped whatever it names: `file:///a` has none,
     * `file://localhost/a` is the local host by definition, and a macOS
     * pasteboard writes the machine's own hostname there. A URL naming some
     * OTHER host would still resolve to a local path or fail to — either way
     * the existence check after this decides.
     */
    private static function fromUrl(string $word): string
    {
        if (!\str_starts_with($word, 'file://')) {
            return $word;
        }
        $rest = \substr($word, offset: 7);
        $slash = \strpos($rest, needle: '/');

        return false === $slash ? '' : \rawurldecode(\substr($rest, $slash));
    }
}
