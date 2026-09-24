<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\FuzzyMatcher;

/**
 * A picker label with the characters its query matched marked: bold and
 * underlined, as a browser's find marks a hit. Attributes, not a colour, so
 * the marks read in the monochrome theme and under `--no-ansi` — and they are
 * the answer to "why is this row here?" that a fuzzy match otherwise hides.
 *
 * Raw SGR toggles rather than a {@see \Symfony\Component\Tui\Style\Style}:
 * `Style::apply()` closes with a reset that would drop the row's own
 * background and weight mid-label. Each mark switches exactly its two
 * attributes off again, and bold only when the row was not already bold.
 */
final class MatchMarks
{
    private const string ON = "\x1b[1m\x1b[4m";
    private const string UNDERLINE_OFF = "\x1b[24m";
    private const string BOLD_OFF = "\x1b[22m";

    /**
     * @param string $shown the label as drawn — plain text, possibly cut with an ellipsis
     * @param string $label the whole label the query was matched against
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether the row is already bold is the row's weight, which a mark must hand back, not a second mode.
    public static function apply(string $shown, string $label, string $query, bool $rowBold): string
    {
        $positions = \array_flip(FuzzyMatcher::positions($label, $query));
        if ([] === $positions) {
            return $shown;
        }
        $whole = \mb_str_split($label);
        $out = '';
        foreach (\mb_str_split($shown) as $i => $char) {
            // A position past the cut is not drawn: the ellipsis standing in
            // for it is not the character that matched.
            $marked = \array_key_exists($i, $positions) && ($whole[$i] ?? null) === $char;
            $out .= $marked ? self::ON.$char.self::UNDERLINE_OFF.($rowBold ? '' : self::BOLD_OFF) : $char;
        }

        return $out;
    }
}
