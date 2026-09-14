<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The identifier the caret is standing in.
 *
 * One rule in one place, because three features ask the same question and a
 * second spelling of it would answer differently: add-caret-at-next-occurrence
 * and select-all-occurrences look for what to match, and the find bar looks for
 * what to put in an empty field.
 *
 * `$` counts on the LEFT only, so the caret inside `$name` finds `$name` rather
 * than `name` — a PHP variable is its sigil and searching without it also finds
 * the property, the parameter and the string. On the right there is no sigil to
 * pick up: `$a$b` is not an identifier in any language here.
 */
final class CaretWord
{
    /** The identifier around the caret, or '' when it is not standing in one. */
    public static function at(Document $document): string
    {
        $line = $document->getLine($document->getCursorLine());
        $col = $document->getCursorCol();
        $matches = [];

        $left = 1 === \preg_match('/[\w$]+$/u', \substr($line, offset: 0, length: $col), $matches) ? $matches[0] : '';
        // The sigil joins on the right only when nothing precedes it — that is
        // the caret standing just before `$name`, which is inside the variable.
        // Allowing it unconditionally would read `$a$b` as one identifier.
        $pattern = '' === $left ? '/^\$?\w+/u' : '/^\w+/u';
        $right = 1 === \preg_match($pattern, \substr($line, $col), $matches) ? $matches[0] : '';

        return $left.$right;
    }
}
