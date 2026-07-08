<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The single home for bracket/quote auto-pairing rules: inserting the closer
 * when an opener is typed, typing over a closer, wrapping a selection, and the
 * empty-pair backspace. Operates on a {@see Document} passed per call, so it is
 * stateless and unaffected by buffer switches. {@see Document::insertNewLine()}
 * shares {@see NEWLINE_PAIRS} so the smart-Enter rules live here too.
 */
final class AutoPairService
{
    /** Opener → closer for typed auto-pairing (brackets and quotes). */
    public const array PAIRS = ['(' => ')', '[' => ']', '{' => '}', '"' => '"', "'" => "'", '`' => '`'];

    /** The subset smart-Enter expands across two lines (brackets only, no quotes). */
    public const array NEWLINE_PAIRS = ['{' => '}', '(' => ')', '[' => ']'];

    /**
     * Handle a typed character for auto-pairing. Returns true when it consumed
     * the keystroke (wrapped a selection, typed over a closer, or inserted a
     * pair), false to let normal insertion proceed.
     */
    public function handle(Document $doc, string $keys): bool
    {
        // Note: with multiple carets, MultiCaret::handleInput intercepts typing
        // before this is reached, so no extra-caret guard is needed here.
        $isOpen = isset(self::PAIRS[$keys]);

        // Wrap a selection in the pair.
        if ($isOpen && null !== ($range = $doc->getSelectionRange())) {
            $this->wrapSelection($doc, $keys, self::PAIRS[$keys], $range);

            return true;
        }

        $line = $doc->getLine($doc->getCursorLine());
        $nextChar = $line[$doc->getCursorCol()] ?? '';

        // Type over an existing closer/quote instead of inserting a new one.
        if ($keys === $nextChar && \in_array($keys, self::PAIRS, true)) {
            $doc->moveRight();

            return true;
        }

        if (!$isOpen) {
            return false;
        }

        // Quote heuristic: don't pair a quote right after a word char (e.g. it's)
        // or when the same quote already sits under the cursor.
        $close = self::PAIRS[$keys];
        if ($keys === $close) {
            $prevChar = $doc->getCursorCol() > 0 ? $line[$doc->getCursorCol() - 1] ?? '' : '';
            if (1 === preg_match('/[\w]/', $prevChar)) {
                return false;
            }
        }
        // Don't pair when the next char is an identifier char (would be noise).
        if ('' !== $nextChar && 1 === preg_match('/[\w]/', $nextChar)) {
            return false;
        }

        $doc->insert($keys.$close);
        $doc->moveLeft();

        return true;
    }

    /**
     * Wrap the given selection with $open/$close and re-select the wrapped text.
     *
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int} $range
     */
    public function wrapSelection(Document $doc, string $open, string $close, array $range): void
    {
        $doc->beginCompound();
        $doc->setCursor($range['endLine'], $range['endCol']);
        $doc->insert($close);
        $doc->setCursor($range['startLine'], $range['startCol']);
        $doc->insert($open);
        $doc->endCompound();

        // Re-select the wrapped text (shifted by the opener on the start line).
        $openLen = \strlen($open);
        $doc->setCursor($range['startLine'], $range['startCol'] + $openLen);
        $doc->beginSelect();
        $endCol = $range['startLine'] === $range['endLine'] ? $range['endCol'] + $openLen : $range['endCol'];
        $doc->setCursor($range['endLine'], $endCol, keepSelection: true);
    }

    /** True when the cursor sits between an empty auto-pair, e.g. "(|)". */
    public function isBetweenEmptyPair(Document $doc): bool
    {
        $line = $doc->getLine($doc->getCursorLine());
        $col = $doc->getCursorCol();
        if (0 === $col || $col >= \strlen($line)) {
            return false;
        }
        $before = $line[$col - 1];
        $after = $line[$col];

        return isset(self::PAIRS[$before]) && self::PAIRS[$before] === $after;
    }
}
