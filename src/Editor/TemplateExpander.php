<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Finds the live template to expand at the caret. Pure: it reads the line and
 * the catalogue and answers what to replace with what, leaving every document
 * edit to the caller.
 *
 * Two shapes, resolved longest-abbreviation-first so `forek` is never eaten by
 * `fore`:
 *
 * - a plain abbreviation, `fore` → the loop;
 * - a POSTFIX one, `$items.fore` → the loop around `$items`, where the
 *   expression to the left of the dot is substituted into the body before it is
 *   parsed as a snippet, so a `$` inside it can never read as snippet syntax.
 */
final class TemplateExpander
{
    /**
     * The template to expand at ($line, $col), or null when the text there is
     * not one. `consume` is how many bytes before the caret the expansion
     * replaces — the abbreviation alone, or the whole `expression.abbrev`.
     *
     * @param array<string, string> $templates abbreviation => snippet body
     *
     * @return array{consume: int, snippet: string}|null
     */
    public static function at(string $line, int $col, array $templates): ?array
    {
        $before = \substr($line, offset: 0, length: \max(0, $col));
        foreach (self::longestFirst($templates) as $abbreviation => $body) {
            $match = \str_starts_with($abbreviation, '.')
                ? self::postfix($before, $abbreviation, $body)
                : self::plain($before, $abbreviation, $body);
            if (null !== $match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * A plain abbreviation must be a whole word: `fore` expands, the `fore` in
     * `beforek` does not, and neither does one glued to an identifier.
     *
     * @return array{consume: int, snippet: string}|null
     */
    private static function plain(string $before, string $abbreviation, string $body): ?array
    {
        if (!\str_ends_with($before, $abbreviation)) {
            return null;
        }
        $preceding = \substr($before, offset: 0, length: \strlen($before) - \strlen($abbreviation));
        if ('' !== $preceding && 1 === \preg_match('/[\w$]$/', $preceding)) {
            return null;
        }

        return ['consume' => \strlen($abbreviation), 'snippet' => $body];
    }

    /**
     * A postfix abbreviation takes the expression immediately left of its dot:
     * a run of identifier characters, `$`, `->`, `::`, `.` and balanced
     * trailing `()`/`[]`, which covers `$items`, `$this->rows` and `foo()`
     * without trying to be a parser.
     *
     * @return array{consume: int, snippet: string}|null
     */
    private static function postfix(string $before, string $abbreviation, string $body): ?array
    {
        if (!\str_ends_with($before, $abbreviation)) {
            return null;
        }
        $expression = \substr($before, offset: 0, length: \strlen($before) - \strlen($abbreviation));
        $matches = [];
        if (1 !== \preg_match('/(\$?[\w\\\\]+(?:(?:->|::|\.)\w+|\([^()]*\)|\[[^\[\]]*\])*)$/', $expression, $matches)) {
            return null;
        }
        $receiver = $matches[1];

        return [
            'consume' => \strlen($receiver) + \strlen($abbreviation),
            'snippet' => \str_replace(TemplateCatalog::EXPRESSION, $receiver, $body),
        ];
    }

    /**
     * Longest abbreviation first, so `forek` wins over `fore` and `.ifn` over
     * `.if`. A shorter one winning would make the longer unreachable.
     *
     * @param array<string, string> $templates
     *
     * @return array<string, string>
     */
    private static function longestFirst(array $templates): array
    {
        \uksort($templates, static function (string $a, string $b): int {
            $byLength = \strlen($b) <=> \strlen($a);

            // Alphabetical within a length, so the order never depends on how
            // the catalogue happens to be written.
            return 0 === $byLength ? \strcmp($a, $b) : $byLength;
        });

        return $templates;
    }
}
