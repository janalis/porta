<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * A keystroke into a read-only {@see Document}: it is handled as any other —
 * a caret move moves, a selection grows, copy copies — and a key that changed
 * the TEXT is refused whole: the text, the caret and the selection are put
 * back as they were. Refusing after the fact rather than sorting keys
 * into "edits" and "the rest" beforehand, because only the document knows
 * which keys changed it: a list of editing verbs would be a second keymap,
 * and wrong the first time a key learned to do something new.
 */
final class ReadOnlyInput
{
    /**
     * Run $input; true when the document is read-only and the input changed
     * its text, which is then — with the caret and the selection — what it
     * was before.
     *
     * @param \Closure(): void $input
     */
    public static function refuse(Document $document, \Closure $input): bool
    {
        if (!$document->readOnly) {
            $input();

            return false;
        }
        $version = $document->version;
        $text = $document->getText();
        $caret = [$document->cursorLine, $document->cursorCol];
        $selection = $document->getSelectionRange();
        $input();
        if ($version === $document->version) {
            return false;
        }
        $document->replaceAll($text);
        // The way back is the user's no more than the way there was: the
        // history a read-only document keeps is the one it was handed.
        $document->readOnly = true;
        $document->setCursor($caret[0], $caret[1]);
        if (null !== $selection) {
            $start = [$selection['startLine'], $selection['startCol']];
            $anchor = $start === $caret ? [$selection['endLine'], $selection['endCol']] : $start;
            $document->setSelectionRange($anchor[0], $anchor[1], $caret[0], $caret[1]);
        }

        return true;
    }
}
