<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * Whether the TERMINAL runs the bidi algorithm on what it is given.
 *
 * Almost none do: a terminal draws the cells it receives, so Porta orders them
 * visually itself. cmux is the exception found in the field — it shapes each row
 * through CoreText (`CTTypesetterCreateWithAttributedStringAndOptions`,
 * `CTLineGetGlyphRuns`, `CTRunGetStatus`), and a CTTypesetter applies UAX#9
 * unconditionally. A row Porta has already ordered is then ordered a SECOND
 * time and comes out wrong.
 *
 * The signature that settled it, from a report rather than a capture: on a
 * selected row the blue background stayed in the nav column while the GLYPHS
 * drawn on it belonged to the form's label. A terminal paints backgrounds from
 * its cell grid and shapes text as a run, so only the text moves; Porta cannot
 * produce that, having composed both into the same cells. Rows whose label was
 * Latin (`elixir`, `go`, `ruby`) were unaffected, exactly as UAX#9 predicts —
 * a Latin run breaks the right-to-left one and leaves nothing to merge across
 * the separator.
 *
 * Detection is by name and deliberately narrow. Ghostty's own engine has NO
 * bidi, so keying off `TERM_PROGRAM=ghostty` would wrongly disable Porta's
 * reorder for everyone using Ghostty directly; cmux is identified by its own
 * bundle id. `PORTA_TERMINAL_BIDI` overrides in both directions for anything
 * not yet known about.
 */
final readonly class TerminalBidi
{
    /** @param array<string, string|false> $env */
    public static function isApplied(array $env): bool
    {
        $override = $env['PORTA_TERMINAL_BIDI'] ?? false;
        if (\is_string($override) && '' !== $override) {
            return '0' !== $override && 'false' !== $override;
        }

        return \is_string($env['CMUX_BUNDLE_ID'] ?? false);
    }
}
