<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\Caret;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Ui\CursorStyle;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Builds the editor viewport's rows for one frame: the pinned sticky-scroll
 * headers, then one gutter+content line per visible document line (or a `~`
 * filler past the end). Split out of CodeEditorWidget — which was the codebase's
 * kitchen-sink widget — so the ~130 lines of row painting live with the other
 * Render/ helpers. Constructed per render over the buffer's {@see DecorationStore}
 * and the frame's display flags; the per-frame geometry and layered decorations
 * are passed to {@see assemble}.
 */
final readonly class EditorRowAssembler
{
    private Styles $styles;

    /** @var array<int, true> */
    private array $breakpointSet;

    /** @var array<int, true> */
    private array $conditionalBreakpointSet;

    private CursorStyle $cursorStyle;

    public function __construct(
        private Document $document,
        private FoldModel $foldModel,
        private DecorationStore $store,
        private bool $relativeLineNumbers,
        private bool $inlineDiagnostics,
        private bool $focused,
        private bool $showCaret,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->breakpointSet = array_fill_keys($store->breakpointLines(), value: true);
        $this->conditionalBreakpointSet = array_fill_keys($store->conditionalBreakpointLines(), value: true);
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
    }

    /**
     * @param list<array{label: string, line: int}> $sticky      pinned headers, top-first
     * @param array<array-key, string>     $highlighted highlighted (tab-expanded) source lines
     * @param list<string>                 $stripe      one right-margin stripe cell per screen row
     *
     * @return list<string>
     */
    public function assemble(
        EditorGeometry $geo,
        EditorDecorations $decorations,
        array $sticky,
        array $highlighted,
        array $stripe,
        int $contentRows,
        int $vScroll,
        int $cursorLine,
        int $cursorDisplayCol,
        int $hScroll,
    ): array {
        $stickyCount = \count($sticky);

        /** @var list<string> $result */
        $result = [];
        // Pinned sticky-scroll headers occupy the top rows; content follows below.
        foreach ($sticky as $i => $header) {
            $result[] = $this->renderStickyRow($header['line'], $geo, $highlighted, $hScroll).$stripe[$i];
        }
        for ($row = 0; $row < $contentRows; ++$row) {
            $lineIndex = $this->foldModel->docLineAtRow($vScroll, $row);

            if (null === $lineIndex) {
                $result[] = $this->styles->of(Role::Placeholder)->apply(str_pad('~', max(0, $geo->gutterWidth))).str_repeat(' ', max(0, $geo->contentWidth)).$stripe[$stickyCount + $row];
                continue;
            }

            $isCursorLine = $lineIndex === $cursorLine;
            $lineDiags = $this->store->diagnostics()[$lineIndex] ?? [];

            $gutter = $this->gutterCell($lineIndex, $isCursorLine ? CaretLine::Current : CaretLine::Other, $lineDiags, $geo->numberWidth);
            $hlLine = $this->decoratedLine(
                $lineIndex,
                $highlighted[$lineIndex] ?? '',
                $decorations->multiActive ? null : $decorations->selection,
                $decorations->carets,
                $decorations->occurrences,
                $decorations->brackets,
                $lineDiags,
                $hScroll,
                $geo->contentWidth,
            );

            $content = EditorRenderer::renderContentLine(
                $hScroll,
                $hlLine,
                $geo->contentWidth,
                $isCursorLine && $this->showCaret && !$decorations->multiActive ? CursorVisibility::Shown : CursorVisibility::Hidden,
                $cursorDisplayCol,
                $this->cursorStyle->marker(),
            );

            // The debugger's stopped line outranks the current-line background.
            if ($lineIndex === $this->store->debugLine()) {
                $content = LineDecorator::applyFullWidth($content, $geo->contentWidth, $this->styles->of(Role::DebugLineBg));
            }
            // Current-line background (skip when a selection is being shown)
            if ($lineIndex !== $this->store->debugLine() && $isCursorLine && $this->focused && null === $decorations->selection) {
                $content = LineDecorator::applyFullWidth($content, $geo->contentWidth, $this->styles->of(Role::RowHighlightBg));
            }

            // Inline diagnostic message in the trailing space (PhpStorm-style):
            // the worst-severity message on the line, colored by severity, with a
            // "+N" count when the line carries more than one diagnostic.
            $hasInlineDiagnostic = $this->inlineDiagnostics && [] !== $lineDiags;
            if ($hasInlineDiagnostic) {
                $content = EditorRenderer::appendInlineDiagnostic($hScroll, $this->document->lineDisplayWidth($lineIndex), $lineDiags, $content, $geo->contentWidth, $this->styles);
            }
            if (!$hasInlineDiagnostic && '' !== ($this->store->lineAnnotations()[$lineIndex] ?? '')) {
                $content = EditorRenderer::appendAnnotation(
                    $hScroll,
                    $this->document->lineDisplayWidth($lineIndex),
                    $this->store->lineAnnotations()[$lineIndex],
                    $content,
                    $geo->contentWidth,
                    $this->styles,
                );
            }

            $result[] = $gutter.$content.$stripe[$stickyCount + $row];
        }

        return $result;
    }

    /**
     * Render one pinned header row: its highlighted source, a dimmed gutter line
     * number, and a distinct band background, over the full editor width.
     *
     * @param array<array-key, string> $highlighted
     */
    private function renderStickyRow(int $docLine, EditorGeometry $geo, array $highlighted, int $hScroll): string
    {
        $gutter =
            EditorRenderer::gitCell($this->store->gitMarks()[$docLine] ?? null, $this->styles)
            .$this->styles->of(Role::Gutter)->apply(str_pad((string) ($docLine + 1), $geo->numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT).' ')
            .' ';
        $hl = EditorRenderer::applyIndentGuides($this->document, $highlighted[$docLine] ?? '', $docLine, $this->styles);
        $content = EditorRenderer::renderContentLine($hScroll, $hl, $geo->contentWidth, CursorVisibility::Hidden, 0, $this->cursorStyle->marker());

        return LineDecorator::applyFullWidth($gutter.$content, $geo->gutterWidth + $geo->contentWidth, $this->styles->of(Role::HeaderBar));
    }

    /**
     * The gutter cell for one document line: git strip, line number colored by
     * the line's worst diagnostic (or highlighted on the caret line), marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int}> $lineDiags
     */
    private function gutterCell(int $lineIndex, CaretLine $isCursorLine, array $lineDiags, int $numberWidth): string
    {
        $numStyle = match (true) {
            [] !== $lineDiags => $this->styles->of(DiagnosticStyle::role(DiagnosticStyle::worst($lineDiags))),
            CaretLine::Current === $isCursorLine => $this->styles->of(Role::CurrentLineNumber),
            default => $this->styles->of(Role::Gutter),
        };
        // The marker column, one glyph by priority: the debugger's stopped line,
        // then the breakpoint dot, then the worst diagnostic's severity glyph —
        // so each state also reads without color.
        $marker = match (true) {
            $lineIndex === $this->store->debugLine() => $this->styles->of(Role::Breakpoint)->apply('▶'),
            ($this->conditionalBreakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply('◆'),
            ($this->breakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply('●'),
            [] !== $lineDiags => $numStyle->apply(DiagnosticStyle::glyph(DiagnosticStyle::worst($lineDiags))),
            default => ' ',
        };
        // Relative line numbers: distance from the cursor line, absolute on
        // the cursor line itself (PhpStorm/Vim "relativenumber" style).
        $numberLabel = (string) ($lineIndex + 1);
        if ($this->relativeLineNumbers && CaretLine::Other === $isCursorLine) {
            $numberLabel = (string) abs($lineIndex - $this->document->getCursorLine());
        }

        return (
            EditorRenderer::gitCell($this->store->gitMarks()[$lineIndex] ?? null, $this->styles)
            .$numStyle->apply(str_pad($numberLabel, $numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT))
            .$marker
            .' '
        );
    }

    /**
     * One document line highlighted and fully decorated: indent guides, the
     * layered selection/caret/search/occurrence/bracket/diagnostic spans,
     * inlay hints, and the collapsed-fold marker.
     *
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $selection null with multiple carets: every caret draws uniformly through the records
     * @param list<Caret>    $carets
     * @param list<array{0: int, 1: int, 2: int}>                                  $occurrences
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null       $brackets
     * @param list<array{col: int, endCol?: int, message: string, severity: int}>  $lineDiags
     */
    private function decoratedLine(
        int $lineIndex,
        string $highlighted,
        ?array $selection,
        array $carets,
        array $occurrences,
        ?array $brackets,
        array $lineDiags,
        int $hScroll,
        int $contentWidth,
    ): string {
        $doc = $this->document;
        $hlLine = EditorRenderer::applyIndentGuides($doc, $highlighted, $lineIndex, $this->styles);

        // Gather decoration spans in layering order, then apply them all at
        // once. Each span is [startCol, endCol, openSgr, closeSgr, bgReapply].
        ['spans' => $spans, 'padTo' => $padTo] = LineSpans::forLine(
            $doc,
            $lineIndex,
            $selection,
            $carets,
            $this->store->searchMatches()[$lineIndex] ?? [],
            $occurrences,
            $brackets,
            $lineDiags,
            $this->styles,
        );
        if ($padTo > 0) {
            // Pad so an end-of-line caret has a cell to invert (the window
            // bound keeps padding within what's visible).
            $need = min($padTo, $hScroll + $contentWidth);
            $width = AnsiUtils::visibleWidth($hlLine);
            if ($need > $width) {
                $hlLine .= str_repeat(' ', max(0, $need - $width));
            }
        }
        foreach ($spans as [$spanStart, $spanEnd, $openSgr, $closeSgr, $reapply]) {
            $hlLine = LineDecorator::apply($hlLine, $spanStart, $spanEnd, $openSgr, $closeSgr, $reapply);
        }

        // Inlay hints: dim virtual text inserted after all decorations so
        // their SGR spans stay attached to the real characters.
        $hlLine = EditorRenderer::applyInlayHints($doc, $lineIndex, $this->store->inlayHintsAt($lineIndex), $hlLine, $this->styles);

        // Collapsed-region marker on the fold-start line.
        if (null !== ($foldEnd = $this->foldModel->foldEndAt($lineIndex))) {
            $hidden = $foldEnd - $lineIndex;
            $hlLine .= $this->styles->of(Role::Placeholder)->apply(' ⋯ '.$hidden.' lines');
        }

        return $hlLine;
    }
}
