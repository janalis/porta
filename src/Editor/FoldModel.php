<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Code-folding state and the visible-line ↔ screen-row mapping it implies.
 *
 * A fold hides the interior lines of a `{ … }` block; the fold-start line stays
 * visible. Everything the editor needs to render and navigate around folds —
 * which document line sits at a screen row, which screen row shows a document
 * line, snapping into visible lines — lives here so the widget stays out of the
 * business of the collapsed-line model.
 */
final class FoldModel
{
    /** @var array<int, int> foldStart line => foldEnd line (interior hidden) */
    private array $folds = [];

    /** @var list<array{0: int, 1: int}>|null externally supplied (LSP) regions, valid for one document version */
    private ?array $regions = null;

    /** The document version the external regions were computed against. */
    private int $regionsVersion = -1;

    /** @var array<int, true> the server regions' start lines, for the gutter's per-line question */
    private array $regionStarts = [];

    /** The {@see regionsVersion} {@see regionStarts} was built from. */
    private int $regionStartsVersion = -2;

    public function __construct(
        private readonly Document $document,
    ) {}

    /**
     * The gutter's fold marker for one line: null when no region starts there,
     * else whether that region is currently collapsed.
     *
     * Deliberately per-LINE and never a whole-document scan. The gutter asks on
     * every rendered row of every frame, and running the brace scan there cost
     * ~4 ms per keystroke on a 12,000-line file — a third of the frame — because
     * an edit invalidates it every time. With no server regions the question
     * "does a region start on this line" is answered from the line itself: it
     * does when the line leaves a brace open, which is the same thing
     * {@see braceFoldRegions} records, decided in one pass over one line.
     */
    public function foldMarkAt(int $line): ?bool
    {
        if (!$this->startsRegion($line)) {
            return null;
        }

        return \array_key_exists($line, $this->folds);
    }

    /** Whether a foldable region starts at $line. */
    private function startsRegion(int $line): bool
    {
        if (null !== $this->regions && $this->regionsVersion === $this->document->getVersion()) {
            return ($this->regionStarts()[$line] ?? null) !== null;
        }

        return self::leavesABraceOpen($this->document->getLine($line));
    }

    /**
     * The server's regions as a start-line set, rebuilt only when the regions
     * themselves change.
     *
     * @return array<int, true>
     */
    private function regionStarts(): array
    {
        if ($this->regionStartsVersion !== $this->regionsVersion) {
            $this->regionStartsVersion = $this->regionsVersion;
            $this->regionStarts = [];
            foreach ($this->regions ?? [] as [$start, $end]) {
                $this->regionStarts[$start] = true;
            }
        }

        return $this->regionStarts;
    }

    /**
     * Whether $text opens a brace it does not close again on the same line —
     * counted so `} else {` reads as a start (it opens one after closing one)
     * while `function f() { return 1; }` does not.
     */
    private static function leavesABraceOpen(string $text): bool
    {
        $open = 0;
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            if ('{' === $text[$i]) {
                ++$open;

                continue;
            }
            if ('}' === $text[$i]) {
                $open = \max(0, $open - 1);
            }
        }

        return $open > 0;
    }

    /** End line of the fold starting at $line, or null when $line is not a fold start. */
    public function foldEndAt(int $line): ?int
    {
        return $this->folds[$line] ?? null;
    }

    /** The next visible line at or after $line, skipping any fold interior. */
    public function nextVisibleLine(int $line): int
    {
        return \array_key_exists($line, $this->folds) ? $this->folds[$line] + 1 : $line + 1;
    }

    /** Snap a line to the nearest visible line at or above it (fold starts are visible). */
    public function snapVisible(int $line): int
    {
        $guard = 0;
        do {
            $moved = false;
            foreach ($this->folds as $start => $end) {
                if ($start >= $line || $line > $end) {
                    continue;
                }

                $line = $start;
                $moved = true;
                break;
            }
        } while ($moved && ++$guard < 1000);

        return $line;
    }

    /** Document line rendered at screen $row given $vScroll, or null past the end. */
    public function docLineAtRow(int $vScroll, int $row): ?int
    {
        $line = $vScroll;
        $count = $this->document->getLineCount();
        for ($r = 0; $line < $count; ++$r) {
            if ($r === $row) {
                return $line;
            }
            $line = $this->nextVisibleLine($line);
        }

        return null;
    }

    /**
     * Screen row of a visible document line given $vScroll, or -1 if it is above
     * the viewport. Callers pass the (always-visible) caret line.
     */
    public function screenRowFor(int $vScroll, int $docLine): int
    {
        if ($docLine < $vScroll) {
            return -1;
        }
        $line = $vScroll;
        $row = 0;
        while ($line < $docLine) {
            $line = $this->nextVisibleLine($line);
            ++$row;
        }

        return $row;
    }

    /** The visible line $steps rows above $docLine (used to pin the caret to the bottom row). */
    public function visibleLineAbove(int $docLine, int $steps): int
    {
        $line = $docLine;
        for ($i = 0; $i < $steps && $line > 0; ++$i) {
            $line = $this->snapVisible($line - 1);
        }

        return $line;
    }

    /** Auto-expand any fold whose interior contains the caret so it stays visible. */
    public function revealCaretLine(): void
    {
        $caret = $this->document->getCursorLine();
        foreach ($this->folds as $start => $end) {
            if ($start >= $caret || $caret > $end) {
                continue;
            }

            unset($this->folds[$start]);
        }
    }

    /**
     * Supply richer (LSP) fold regions, valid for the current document version.
     * Edits invalidate them implicitly — {@see foldRegions} falls back to the
     * brace scan as soon as the version moves on.
     *
     * @param list<array{0: int, 1: int}> $regions [startLine, endLine]
     */
    public function setRegions(array $regions, int $version): void
    {
        $this->regions = $regions;
        $this->regionsVersion = $version;
    }

    /** Collapse every foldable region; the caret snaps to a visible fold start. */
    public function foldAll(): void
    {
        foreach ($this->foldRegions() as [$start, $end]) {
            $this->folds[$start] = $end;
        }
        $line = $this->snapVisible($this->document->getCursorLine());
        $this->document->setCursor($line, \min($this->document->getCursorCol(), \strlen($this->document->getLine($line))));
    }

    public function unfoldAll(): void
    {
        $this->folds = [];
    }

    /** Collapse the block at the caret, or expand it if the caret is on a fold start. */
    public function toggle(): void
    {
        $line = $this->document->getCursorLine();
        if (\array_key_exists($line, $this->folds)) {
            unset($this->folds[$line]);

            return;
        }
        if (null !== ($region = $this->foldableRegionAt($line))) {
            $this->folds[$region[0]] = $region[1];
            // Keep the caret on the (visible) fold-start line.
            $this->document->setCursor($region[0], \min($this->document->getCursorCol(), \strlen($this->document->getLine($region[0]))));
        }
    }

    /**
     * The innermost foldable region containing $line, or null.
     *
     * @return array{0: int, 1: int}|null [startLine, endLine]
     */
    private function foldableRegionAt(int $line): ?array
    {
        $best = null;
        foreach ($this->foldRegions() as [$start, $end]) {
            if (!($start <= $line && $line <= $end && (null === $best || $start > $best[0]))) {
                continue;
            }

            $best = [$start, $end];
        }

        return $best;
    }

    /**
     * All foldable regions: the externally supplied set while it matches the
     * document version, the naive brace scan otherwise.
     *
     * @return list<array{0: int, 1: int}> [startLine, endLine]
     */
    private function foldRegions(): array
    {
        if (null !== $this->regions && $this->regionsVersion === $this->document->getVersion()) {
            return $this->regions;
        }

        return $this->braceFoldRegions();
    }

    /**
     * Multi-line `{ … }` regions in the document, by naive brace matching.
     *
     * @return list<array{0: int, 1: int}> [startLine, endLine]
     */
    private function braceFoldRegions(): array
    {
        /** @var list<int> $stack */
        $stack = [];
        /** @var list<array{0: int, 1: int}> $regions */
        $regions = [];
        foreach ($this->document->getLines() as $lineIndex => $text) {
            $len = \strlen($text);
            for ($c = 0; $c < $len; ++$c) {
                if ('{' === $text[$c]) {
                    $stack[] = (int) $lineIndex;

                    continue;
                }
                if ('}' === $text[$c] && [] !== $stack) {
                    $start = \array_pop($stack);
                    if ($lineIndex > $start) {
                        $regions[] = [$start, (int) $lineIndex];
                    }
                }
            }
        }

        return $regions;
    }
}
