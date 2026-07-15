<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Ui\ColorPalette;
use Porta\Ui\CursorStyle;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Builds the editor viewport's rows for one frame: the pinned sticky-scroll
 * headers, then one gutter+content line per visible document line (or a `~`
 * filler past the end). Split out of CodeEditorWidget — which was the codebase's
 * kitchen-sink widget — so the ~130 lines of row painting live with the other
 * Render/ helpers. Constructed per render from the frame's decoration state
 * (git marks, diagnostics, search matches, inlay hints and the display flags);
 * the per-frame geometry and layered decorations are passed to {@see assemble}.
 */
final readonly class EditorRowAssembler
{
    private Palette $palette;

    /**
     * @param array<int, string>                                                          $gitMarks      0-based line => 'added'|'modified'|'deleted'
     * @param array<int, list<array{col: int, endCol?: int, message: string, severity: int}>> $diagnostics per-line diagnostics
     * @param array<int, list<array{start: int, end: int, current: bool}>>                 $searchMatches per-line search matches (byte cols)
     * @param array<int, list<array{col: int, text: string}>>                              $inlayHints    per-line inlay hints (byte col => dim label)
     */
    /** @var array<int, true> */
    private array $breakpointSet;

    /** @var array<int, true> */
    private array $conditionalBreakpointSet;

    private CursorStyle $cursorStyle;

    /**
     * @param array<int, string>                                                              $gitMarks        0-based line => 'added'|'modified'|'deleted'
     * @param array<int, list<array{col: int, endCol?: int, message: string, severity: int}>> $diagnostics     per-line diagnostics
     * @param array<int, list<array{start: int, end: int, current: bool}>>                    $searchMatches   per-line search matches (byte cols)
     * @param array<int, list<array{col: int, text: string}>>                                 $inlayHints      per-line inlay hints (byte col => dim label)
     * @param list<int>                                                                       $breakpointLines 0-based lines carrying a breakpoint
     * @param list<int>                                                                       $conditionalBreakpointLines the subset carrying a condition
     */
    public function __construct(
        private Document $document,
        private FoldModel $foldModel,
        private array $gitMarks,
        private array $diagnostics,
        private array $searchMatches,
        private array $inlayHints,
        private bool $relativeLineNumbers,
        private bool $inlineDiagnostics,
        private bool $focused,
        private bool $showCaret,
        ?Palette $palette = null,
        array $breakpointLines = [],
        private ?int $debugLine = null,
        ?CursorStyle $cursorStyle = null,
        array $conditionalBreakpointLines = [],
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->breakpointSet = array_fill_keys($breakpointLines, true);
        $this->conditionalBreakpointSet = array_fill_keys($conditionalBreakpointLines, true);
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
                $result[] = $this->palette->of(Role::Placeholder).str_pad('~', $geo->gutterWidth)."\x1b[0m".str_repeat(' ', $geo->contentWidth).$stripe[$stickyCount + $row];
                continue;
            }

            $isCursorLine = $lineIndex === $cursorLine;
            $lineDiags = $this->diagnostics[$lineIndex] ?? [];

            $gutter = $this->gutterCell($lineIndex, $isCursorLine, $lineDiags, $geo->numberWidth);
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
                $isCursorLine && $this->showCaret && !$decorations->multiActive,
                $cursorDisplayCol,
                $this->cursorStyle->marker(),
            );

            // The debugger's stopped line outranks the current-line background.
            if ($lineIndex === $this->debugLine) {
                $content = LineDecorator::applyFullWidth($content, $geo->contentWidth, $this->palette->of(Role::DebugLineBg));
            } elseif ($isCursorLine && $this->focused && null === $decorations->selection) {
                // Current-line background (skip when a selection is being shown)
                $content = LineDecorator::applyFullWidth($content, $geo->contentWidth, $this->palette->of(Role::RowHighlightBg));
            }

            // Inline diagnostic message in the trailing space (PhpStorm-style):
            // the worst-severity message on the line, colored by severity, with a
            // "+N" count when the line carries more than one diagnostic.
            if ($this->inlineDiagnostics && [] !== $lineDiags) {
                $content = EditorRenderer::appendInlineDiagnostic($hScroll, $this->document->lineDisplayWidth($lineIndex), $lineDiags, $content, $geo->contentWidth, $this->palette);
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
            EditorRenderer::gitCell($this->gitMarks[$docLine] ?? null, $this->palette)
            .$this->palette->of(Role::Gutter)
            .str_pad((string) ($docLine + 1), $geo->numberWidth, ' ', \STR_PAD_LEFT)
            .' '
            ."\x1b[0m ";
        $hl = EditorRenderer::applyIndentGuides($this->document, $highlighted[$docLine] ?? '', $docLine, $this->palette);
        $content = EditorRenderer::renderContentLine($hScroll, $hl, $geo->contentWidth, false, 0, $this->cursorStyle->marker());

        return LineDecorator::applyFullWidth($gutter.$content, $geo->gutterWidth + $geo->contentWidth, $this->palette->of(Role::HeaderBar));
    }

    /**
     * The gutter cell for one document line: git strip, line number colored by
     * the line's worst diagnostic (or highlighted on the caret line), marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int}> $lineDiags
     */
    private function gutterCell(int $lineIndex, bool $isCursorLine, array $lineDiags, int $numberWidth): string
    {
        $numColor = match (true) {
            [] !== $lineDiags => $this->palette->of(DiagnosticStyle::role(DiagnosticStyle::worst($lineDiags))),
            $isCursorLine => $this->palette->of(Role::CurrentLineNumber),
            default => $this->palette->of(Role::Gutter),
        };
        // The marker column, one glyph by priority: the debugger's stopped line,
        // then the breakpoint dot, then the worst diagnostic's severity glyph —
        // so each state also reads without color.
        $marker = match (true) {
            $lineIndex === $this->debugLine => $this->palette->of(Role::Breakpoint).'▶'."\x1b[39m",
            isset($this->conditionalBreakpointSet[$lineIndex]) => $this->palette->of(Role::Breakpoint).'◆'."\x1b[39m",
            isset($this->breakpointSet[$lineIndex]) => $this->palette->of(Role::Breakpoint).'●'."\x1b[39m",
            [] !== $lineDiags => $numColor.DiagnosticStyle::glyph(DiagnosticStyle::worst($lineDiags))."\x1b[39m",
            default => ' ',
        };
        // Relative line numbers: distance from the cursor line, absolute on
        // the cursor line itself (PhpStorm/Vim "relativenumber" style).
        if ($this->relativeLineNumbers && !$isCursorLine) {
            $numberLabel = (string) abs($lineIndex - $this->document->getCursorLine());
        } else {
            $numberLabel = (string) ($lineIndex + 1);
        }

        return EditorRenderer::gitCell($this->gitMarks[$lineIndex] ?? null, $this->palette).$numColor.str_pad($numberLabel, $numberWidth, ' ', \STR_PAD_LEFT).$marker."\x1b[0m ";
    }

    /**
     * One document line highlighted and fully decorated: indent guides, the
     * layered selection/caret/search/occurrence/bracket/diagnostic spans,
     * inlay hints, and the collapsed-fold marker.
     *
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $selection null with multiple carets: every caret draws uniformly through the records
     * @param list<array{line: int, col: int, anchorLine: int, anchorCol: int}>    $carets
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
        $hlLine = EditorRenderer::applyIndentGuides($doc, $highlighted, $lineIndex, $this->palette);

        // Gather decoration spans in layering order, then apply them all at
        // once. Each span is [startCol, endCol, openSgr, closeSgr, bgReapply].
        ['spans' => $spans, 'padTo' => $padTo] = LineSpans::forLine(
            $doc,
            $lineIndex,
            $selection,
            $carets,
            $this->searchMatches[$lineIndex] ?? [],
            $occurrences,
            $brackets,
            $lineDiags,
            $this->palette,
        );
        if ($padTo > 0) {
            // Pad so an end-of-line caret has a cell to invert (the window
            // bound keeps padding within what's visible).
            $need = min($padTo, $hScroll + $contentWidth);
            $width = AnsiUtils::visibleWidth($hlLine);
            if ($need > $width) {
                $hlLine .= str_repeat(' ', $need - $width);
            }
        }
        foreach ($spans as [$spanStart, $spanEnd, $openSgr, $closeSgr, $reapply]) {
            $hlLine = LineDecorator::apply($hlLine, $spanStart, $spanEnd, $openSgr, $closeSgr, $reapply);
        }

        // Inlay hints: dim virtual text inserted after all decorations so
        // their SGR spans stay attached to the real characters.
        $hlLine = EditorRenderer::applyInlayHints($doc, $lineIndex, $this->inlayHints[$lineIndex] ?? [], $hlLine, $this->palette);

        // Collapsed-region marker on the fold-start line.
        if (null !== ($foldEnd = $this->foldModel->foldEndAt($lineIndex))) {
            $hidden = $foldEnd - $lineIndex;
            $hlLine .= $this->palette->of(Role::Placeholder).' ⋯ '.$hidden." lines\x1b[0m";
        }

        return $hlLine;
    }
}
