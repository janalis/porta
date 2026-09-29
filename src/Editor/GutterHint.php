<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\I18n\Translator;
use Porta\Ui\Glyphs;

/**
 * What a gutter mark means and what a click on it does, in words — the
 * status-bar hint the pointer gets over a mark, which otherwise says it by
 * glyph and colour alone. Null for a cell that carries no mark.
 */
final class GutterHint
{
    // @mago-expect lint:no-boolean-flag-parameter -- whether a click shows the change is the gutter's own state (a plugin provides it or not), not a mode of this method.
    public static function for(DecorationStore $store, int $line, GutterPart $part, bool $clickShowsChange, Translator $translator): ?string
    {
        if (GutterPart::Change === $part) {
            $kind = $store->vcsMarks[$line] ?? null;
            if (null === $kind) {
                return null;
            }
            $label = match ($kind) {
                ChangeMarkKind::Added => $translator->trans('Added'),
                ChangeMarkKind::Modified => $translator->trans('Modified'),
                ChangeMarkKind::Deleted => $translator->trans('Deleted'),
            };

            return $clickShowsChange ? $translator->trans('%change% · click to see the change', ['%change%' => $label]) : $label;
        }
        $worst = $store->diagnosticAt($line);

        return match (true) {
            $line === $store->debugLine => $translator->trans('Paused here'),
            \in_array($line, $store->logpointLines, strict: true) => $translator->trans('Logpoint · click to remove'),
            \in_array($line, $store->conditionalBreakpointLines, strict: true) => $translator->trans('Conditional breakpoint · click to remove'),
            \in_array($line, $store->breakpointLines, strict: true) => $translator->trans('Breakpoint · click to remove'),
            $store->isBookmarked($line) => $translator->trans('Bookmark'),
            \array_key_exists($line, $store->commentMarks) => Glyphs::COMMENT.' '.$store->commentMarks[$line],
            null !== $worst => $worst['severity']->glyph().' '.$worst['message'],
            default => null,
        };
    }
}
