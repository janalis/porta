<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The editor's structural selection & merge-conflict machinery: the expand/shrink
 * "selection ladder" (grow the selection to the next enclosing syntactic range and
 * walk back down), the surround-with menu, and merge-conflict navigation +
 * resolution. Built per document (like {@see EditorCompletion}/{@see MultiCaret});
 * the heavy algorithms live in the stateless {@see StructuralSelection} and
 * {@see ConflictMarkers} helpers, while effects and the shared list-overlay opener
 * flow through injected closures.
 */
final class EditorStructural
{
    /**
     * Stack of prior byte-offset ranges [start, end] pushed by "expand
     * selection" so "shrink selection" can walk back down the hierarchy.
     *
     * @var list<array{0: int, 1: int}>
     */
    private array $expandStack = [];
    /** @var array{0: int, 1: int}|null the offsets expand/shrink last applied */
    private ?array $expandAnchor = null;

    /**
     * @param \Closure(): void $invalidate   request a redraw
     * @param \Closure(): void $notifyChange announce a buffer edit
     * @param \Closure(string, list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, callable(string): void): void $openListOverlay open a modal list menu
     */
    public function __construct(
        private readonly Document $document,
        private readonly AutoPairService $autoPair,
        private readonly \Closure $invalidate,
        private readonly \Closure $notifyChange,
        private readonly \Closure $openListOverlay,
    ) {}

    /**
     * Grow the selection to the next enclosing syntactic range, pushing the
     * previous range so {@see shrinkSelection()} can walk back.
     */
    public function expandSelection(): void
    {
        [$start, $end] = $this->currentOffsets();
        // A caret movement or manual selection since the last expand resets
        // the shrink history so a fresh ladder starts here.
        if ([$start, $end] !== $this->expandAnchor) {
            $this->expandStack = [];
        }

        $next = StructuralSelection::expand($this->document->getText(), $start, $end);
        if (null === $next) {
            return;
        }

        $this->expandStack[] = [$start, $end];
        $this->applyOffsetSelection($next[0], $next[1]);
        $this->expandAnchor = $next;
        ($this->invalidate)();
    }

    /**
     * Shrink back to the previous range recorded by {@see expandSelection()}.
     */
    public function shrinkSelection(): void
    {
        [$start, $end] = $this->currentOffsets();
        if ([$start, $end] !== $this->expandAnchor || [] === $this->expandStack) {
            return;
        }

        $prev = \array_pop($this->expandStack);
        $this->applyOffsetSelection($prev[0], $prev[1]);
        $this->expandAnchor = $prev;
        ($this->invalidate)();
    }

    /**
     * Current selection (or caret) as byte offsets into the joined text.
     *
     * @return array{int, int}
     */
    private function currentOffsets(): array
    {
        $doc = $this->document;
        $range = $doc->getSelectionRange();
        if (null === $range) {
            $off = $this->offsetOf($doc->cursorLine, $doc->cursorCol);

            return [$off, $off];
        }

        return [
            $this->offsetOf($range['startLine'], $range['startCol']),
            $this->offsetOf($range['endLine'], $range['endCol']),
        ];
    }

    private function offsetOf(int $line, int $col): int
    {
        return $this->document->offsetAt($line, $col);
    }

    /** @return array{0: int, 1: int} the (line, col) for a byte offset */
    private function positionOf(int $offset): array
    {
        $pos = $this->document->positionAt($offset);

        return [$pos['line'], $pos['col']];
    }

    private function applyOffsetSelection(int $start, int $end): void
    {
        $doc = $this->document;
        if ($start === $end) {
            [$l, $c] = $this->positionOf($start);
            $doc->setCursor($l, $c);

            return;
        }
        [$sl, $sc] = $this->positionOf($start);
        [$el, $ec] = $this->positionOf($end);
        $doc->setSelectionRange($sl, $sc, $el, $ec);
    }

    /**
     * Offer a small menu of surrounds (bracket pairs, quotes, PHP snippets)
     * and wrap the current selection with the chosen one.
     */
    public function openSurroundMenu(): void
    {
        if (!$this->document->hasSelection()) {
            return;
        }
        /** @var list<array{label: string, value: string}> $choices */
        $choices = [
            ['label' => '( ) Parentheses', 'value' => '(|)'],
            ['label' => '[ ] Brackets', 'value' => '[|]'],
            ['label' => '{ } Braces', 'value' => '{|}'],
            ['label' => "' ' Single quotes", 'value' => "'|'"],
            ['label' => '" " Double quotes', 'value' => '"|"'],
            ['label' => 'if (…) { … }', 'value' => "if (|) {\n\n}"],
            ['label' => 'foreach (…) { … }', 'value' => "foreach (| as \$item) {\n\n}"],
            ['label' => 'try { … } catch', 'value' => "try {\n|\n} catch (\\Throwable \$e) {\n}"],
        ];
        $items = \array_map(
            static fn (array $c): array => ['label' => $c['label'], 'hint' => '', 'value' => $c['value']],
            $choices,
        );
        ($this->openListOverlay)('Surround With', $items, function (string $template): void {
            [$open, $close] = \explode('|', $template, limit: 2);
            $range = $this->document->getSelectionRange();
            if (null !== $range) {
                $this->autoPair->wrapSelection($this->document, $open, $close, $range);
                ($this->notifyChange)();
            }
        });
    }

    /** Move the caret to the next (+1) or previous (-1) conflict, wrapping. */
    public function gotoConflict(int $dir): void
    {
        /** @var list<string> $lines */
        $lines = $this->document->lines;
        $regions = ConflictMarkers::find($lines);
        if ([] === $regions) {
            return;
        }
        $cursor = $this->document->cursorLine;

        $target = $this->conflictTarget($regions, $cursor, $dir);

        $this->document->clearSelection();
        $this->document->setCursor($target['start'], 0);
        ($this->invalidate)();
    }

    /**
     * The conflict region to jump to: the first region past the caret when
     * moving forward, the last region before it when moving back, wrapping to
     * the far end when the caret sits outside every region in that direction.
     *
     * @param list<array{start: int, base: int|null, sep: int, end: int}> $regions non-empty
     *
     * @return array{start: int, base: int|null, sep: int, end: int}
     */
    private function conflictTarget(array $regions, int $cursor, int $dir): array
    {
        // Walking away from the caret, the first region past it; else wrap round.
        $ahead = $dir > 0 ? $regions : \array_reverse($regions);

        return \array_find($ahead, static fn (array $region): bool => $dir > 0 ? $region['start'] > $cursor : $region['start'] < $cursor) ?? $ahead[0];
    }

    /** Offer keep-ours/theirs/both for the conflict enclosing the caret. */
    public function openResolveConflictMenu(): void
    {
        /** @var list<string> $lines */
        $lines = $this->document->lines;
        $region = ConflictMarkers::regionAt($lines, $this->document->cursorLine);
        if (null === $region) {
            return;
        }
        $items = [
            ['label' => 'Keep Ours (current)', 'hint' => '', 'value' => ConflictSide::Ours->value],
            ['label' => 'Keep Theirs (incoming)', 'hint' => '', 'value' => ConflictSide::Theirs->value],
            ['label' => 'Keep Both', 'hint' => '', 'value' => ConflictSide::Both->value],
        ];
        ($this->openListOverlay)('Resolve Conflict', $items, function (string $which) use ($region): void {
            $this->resolveConflict($region, ConflictSide::from($which));
        });
    }

    /**
     * Replace a conflict region with the chosen side as a single edit.
     *
     * @param array{start: int, base: int|null, sep: int, end: int} $region
     */
    private function resolveConflict(array $region, ConflictSide $which): void
    {
        $doc = $this->document;
        /** @var list<string> $lines */
        $lines = $doc->lines;
        $replacement = \implode("\n", ConflictMarkers::resolution($lines, $region, $which));

        $doc->setCursor($region['start'], 0);
        $doc->beginSelect();
        $doc->setCursor($region['end'], \strlen($lines[$region['end']]), selection: SelectionMode::Keep);
        if ('' === $replacement) {
            $doc->deleteSelection();
            ($this->notifyChange)();

            return;
        }
        $doc->insert($replacement);
        ($this->notifyChange)();
    }
}
