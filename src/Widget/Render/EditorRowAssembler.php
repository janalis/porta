<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Editor\LineNumberMode;
use Porta\Editor\WhitespaceGlyphs;
use Porta\Editor\WrapLayout;
use Porta\Highlight\SemanticTokenStyle;
use Porta\Support\AnsiText;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Tempest\Highlight\TerminalTheme;

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

    /** @var array<int, true> */
    private array $logpointSet;

    public function __construct(
        private Document $document,
        private FoldModel $foldModel,
        private DecorationStore $store,
        private LineNumberMode $lineNumbers,
        private bool $indentGuides,
        private bool $vcsGutter,
        private bool $inlineDiagnostics,
        private bool $focused,
        private bool $showCaret,
        ?Styles $styles = null,
        private CursorStyle $cursorStyle = new CursorStyle(),
        /** Non-null only while soft wrap is on; null renders one row per line as before. */
        private ?WrapLayout $wrap = null,
        /**
         * The live syntax theme, needed to resolve a semantic token's colour at
         * render time so a theme switch recolours immediately. Null leaves the
         * lexer's colours untouched — which is also every path without a server
         * that answers `textDocument/semanticTokens`.
         */
        private ?TerminalTheme $syntaxTheme = null,
        /** @var array<int, true> lines carrying a member separator; [] when the setting is off */
        private array $separators = [],
        /** Mark leading and trailing whitespace in the rendered code. */
        private bool $showWhitespace = false,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->breakpointSet = \array_fill_keys($store->breakpointLines(), value: true);
        $this->conditionalBreakpointSet = \array_fill_keys($store->conditionalBreakpointLines(), value: true);
        $this->logpointSet = \array_fill_keys($store->logpointLines(), value: true);
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
        /** Rows of the TOP line hidden above the viewport; 0 unless soft wrap scrolled into a tall line. */
        int $vScrollSegment = 0,
    ): array {
        $stickyCount = \count($sticky);

        /** @var list<string> $result */
        $result = [];
        // Pinned sticky-scroll headers occupy the top rows; content follows below.
        foreach ($sticky as $i => $header) {
            $result[] = $this->renderStickyRow($header['line'], $geo, $highlighted, $hScroll).$stripe[$i];
        }
        // One walking cursor instead of docLineAtRow per row: that helper
        // re-walks from $vScroll on every call, making the loop O(rows²).
        // Same sequence — the row-th visible line at or after $vScroll.
        $lineCount = $this->document->getLineCount();
        $walk = $vScroll;
        // Which row OF $walk this screen row shows. A line occupies one row per
        // code lens above it, then one per wrapped segment — so this is 0 for
        // most lines and the walk moves on as soon as it runs out.
        // Rows of the top line hidden above the viewport: scrolling INTO a
        // wrapped line taller than the pane starts the walk partway down it.
        $rowInLine = $vScrollSegment;
        for ($row = 0; $row < $contentRows; ++$row) {
            $lineIndex = $walk < $lineCount ? $walk : null;
            $atRow = $rowInLine;
            $lenses = null === $lineIndex ? [] : $this->store->codeLensesAt($lineIndex);
            if (null !== $lineIndex) {
                $total = \count($lenses) + ($this->wrap?->rowsFor($lineIndex, $geo->contentWidth) ?? 1);
                $more = ($rowInLine + 1) < $total;
                $rowInLine = $more ? $rowInLine + 1 : 0;
                if (!$more) {
                    $walk = $this->foldModel->nextVisibleLine($walk);
                }
            }

            if (null === $lineIndex) {
                // The tilde is a GUTTER cell, so it goes when the gutter does.
                // `str_pad` never truncates — `str_pad('~', 0)` is `'~'` — so
                // the old `max(0, …)` looked like a clamp and was not one.
                $placeholder = 0 === $geo->gutterWidth ? '' : $this->styles->of(Role::Placeholder)->apply(\str_pad('~', $geo->gutterWidth));
                $result[] = $placeholder.AnsiText::blank($geo->contentWidth).$stripe[$stickyCount + $row];
                continue;
            }

            // A lens row belongs to the line below it and shows none of its
            // text, so it takes no number, no marks and no caret.
            $lensTitle = $lenses[$atRow] ?? null;
            if (null !== $lensTitle) {
                $result[] = $this->lensRow($lensTitle['title'], $geo).$stripe[$stickyCount + $row];

                continue;
            }
            $rowSegment = $atRow - \count($lenses);

            $isCursorLine = $lineIndex === $cursorLine && (null === $this->wrap || $rowSegment === WrapLayout::segmentOf($cursorDisplayCol, $geo->contentWidth));
            $lineDiags = $this->store->diagnostics()[$lineIndex] ?? [];

            // A continuation row is the same line, so it carries no number and
            // no gutter marks — repeating them would read as another line.
            // A zero width is the pane being too narrow to seat a gutter at
            // all, not a continuation row: `gutterCell()` builds a fixed run of
            // cells and cannot render narrower than itself, so the geometry
            // asks for none rather than for a smaller one.
            $gutter = match (true) {
                0 === $geo->gutterWidth => '',
                $rowSegment > 0 => AnsiText::blank($geo->gutterWidth),
                default => $this->gutterCell($lineIndex, $isCursorLine ? CaretLine::Current : CaretLine::Other, $lineDiags, $geo->numberWidth),
            };
            // Per rendered ROW, not over the whole array: the file may be
            // fifty thousand lines and forty of them are on screen. It needs
            // the raw line, which is the only place tabs still exist.
            $code = $highlighted[$lineIndex] ?? '';
            if ($this->showWhitespace) {
                $code = WhitespaceGlyphs::apply($code, $this->document->getLine($lineIndex));
            }
            $hlLine = $this->decoratedLine(
                $lineIndex,
                $code,
                $decorations,
                $lineDiags,
                // The window THIS ROW shows. Under soft wrap that is the
                // segment's offset, not the widget's hScroll (which wrapping
                // forces to zero) — the padding bound below is about what is
                // visible, and on a wrapped line that differs per row.
                null === $this->wrap ? $hScroll : WrapLayout::offsetOf($rowSegment, $geo->contentWidth),
                $geo->contentWidth,
                $this->separatorFrom($lineIndex, $rowSegment, $geo->contentWidth),
            );

            // The column this ROW starts at: the horizontal scroll normally,
            // the segment's own offset when the line is wrapped. Everything
            // placed after the text has to measure from the same origin.
            $rowOffset = null === $this->wrap ? $hScroll : WrapLayout::offsetOf($rowSegment, $geo->contentWidth);

            $content = EditorRenderer::renderContentLine(
                $rowOffset,
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
            // "+N" count when the line carries more than one diagnostic. The
            // message goes after the *decorated* line's on-screen width — inlay
            // hints and the collapsed-fold marker widen $hlLine past the raw doc
            // line's lineDisplayWidth, and placing the message by the raw width
            // would slice into them (the code/hints get overprinted). Compute the
            // width only when there is something to place after it.
            // One cell, three claimants, in this order: the debugger's value
            // for the line, the inline diagnostic, the plugin annotation.
            //
            // The value wins because it only exists while the debugger is
            // STOPPED — a mode entered on purpose, answering the question being
            // asked right now — and because the other two keep their other
            // surfaces: a diagnostic still has its squiggle, its gutter mark,
            // its stripe cell and the Problems panel; blame has Alt+B.
            $debugValue = $this->store->debugValues()[$lineIndex] ?? '';
            $hasInlineDiagnostic = '' === $debugValue && $this->inlineDiagnostics && [] !== $lineDiags;
            $trailing = '' !== $debugValue || $hasInlineDiagnostic ? $debugValue : $this->store->lineAnnotations()[$lineIndex] ?? '';
            if ($hasInlineDiagnostic || '' !== $trailing) {
                $textDisplayWidth = AnsiUtils::visibleWidth($hlLine);
                // $rowOffset, not $hScroll: on a wrapped line those differ, and
                // measuring the trailing text from the scroll put it past the
                // end of every segment — so a wrapped line showed no blame, no
                // inline message and no debug value at all. With the row's own
                // origin it lands after the text on the LAST segment, which is
                // where the line actually ends.
                $content = $hasInlineDiagnostic
                    ? EditorRenderer::appendInlineDiagnostic($rowOffset, $textDisplayWidth, $lineDiags, $content, $geo->contentWidth, $this->styles)
                    : EditorRenderer::appendAnnotation($rowOffset, $textDisplayWidth, $trailing, $content, $geo->contentWidth, $this->styles, '' === $debugValue ? Role::Inlay : Role::DebugValue);
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
        $stickyLabel = LineNumberMode::Off === $this->lineNumbers ? '' : (string) ($docLine + 1);
        // Same columns as an ordinary row, including the fold marker: a pinned
        // header is a fold start by construction, and a blank there would leave
        // the gutter a column narrower than every line below it.
        // Zero width is a pane too narrow to seat a gutter at all — the same
        // all-or-nothing rule the ordinary rows follow. A pinned header that
        // kept its gutter here would overflow the pane exactly as the ordinary
        // rows did, and sticky scroll is ON by default, so this path is at
        // least as reachable as that one was.
        $gutter = 0 === $geo->gutterWidth
            ? ''
            : $this->vcsCell($docLine).$this->styles->of(Role::Gutter)->apply(\str_pad($stickyLabel, $geo->numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT).' ').$this->foldCell($docLine).' ';
        $raw = $highlighted[$docLine] ?? '';
        $hl = $this->indentGuides ? EditorRenderer::applyIndentGuides($this->document, $raw, $docLine, $this->styles) : $raw;
        $content = EditorRenderer::renderContentLine($hScroll, $hl, $geo->contentWidth, CursorVisibility::Hidden, 0, $this->cursorStyle->marker());

        return LineDecorator::applyFullWidth($gutter.$content, $geo->gutterWidth + $geo->contentWidth, $this->styles->of(Role::HeaderBar));
    }

    /**
     * One code-lens row: the server's own words, dim, where a line number
     * would be. PhpStorm indents these to the code they describe; the gutter
     * already provides that indent, so the text starts at the content column.
     */
    private function lensRow(string $title, EditorGeometry $geo): string
    {
        $text = AnsiUtils::sliceWithWidth($this->styles->of(Role::Dim)->apply($title), 0, $geo->contentWidth)['text'];

        return AnsiText::blank(\max(0, $geo->gutterWidth)).AnsiText::padRight($text, $geo->contentWidth);
    }

    /**
     * The semantic tokens on one line, resolved to SGR against the LIVE theme.
     *
     * Resolved here rather than when the tokens arrive because the theme is
     * swapped in place: baking the colours at decode time would leave the last
     * server answer painted in the previous theme until the next edit.
     *
     * @return list<array{col: int, endCol: int, open: string, close: string}>
     */
    private function semanticSpansFor(int $lineIndex): array
    {
        $theme = $this->syntaxTheme;
        if (null === $theme) {
            return [];
        }

        $spans = [];
        foreach ($this->store->semanticTokensAt($lineIndex) as $token) {
            $open = SemanticTokenStyle::open($token['type'], $token['modifiers'], $theme);
            // A type the palette does not map, carrying no modifier, has
            // nothing to say — leave the lexer's colour rather than emit an
            // empty pair around every identifier on the line.
            if ('' === $open) {
                continue;
            }

            $spans[] = [
                'col' => $token['col'],
                'endCol' => $token['endCol'],
                'open' => $open,
                'close' => SemanticTokenStyle::close($token['type'], $token['modifiers'], $theme),
            ];
        }

        return $spans;
    }

    /**
     * The gutter cell for one document line: git strip, line number colored by
     * the line's worst diagnostic (or highlighted on the caret line), marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
     */
    private function gutterCell(int $lineIndex, CaretLine $isCursorLine, array $lineDiags, int $numberWidth): string
    {
        $numStyle = match (true) {
            [] !== $lineDiags => $this->styles->of(DiagnosticStyle::role(DiagnosticStyle::worst($lineDiags))),
            CaretLine::Current === $isCursorLine => $this->styles->of(Role::CurrentLineNumber),
            default => $this->styles->of(Role::Gutter),
        };
        // The marker column, one glyph by priority: the debugger's stopped line,
        // then the breakpoint dot, then the BOOKMARK, then the worst
        // diagnostic's severity glyph — so each state also reads without color.
        //
        // The bookmark outranks the diagnostic because it is the only one of
        // the two with nowhere else to show. A diagnostic already colours the
        // line NUMBER beside this cell, paints the error stripe in the right
        // margin, writes its message inline at the end of the line and lists
        // itself in the Problems panel; a bookmark has this cell and nothing
        // else. Ordered the other way, a single HINT — the weakest severity
        // there is — made a bookmark invisible.
        $marker = match (true) {
            $lineIndex === $this->store->debugLine() => $this->styles->of(Role::Breakpoint)->apply(Glyphs::STOPPED_LINE),
            ($this->logpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::LOGPOINT),
            ($this->conditionalBreakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::BREAKPOINT_CONDITIONAL),
            ($this->breakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::BREAKPOINT),
            $this->store->isBookmarked($lineIndex) => $this->styles->of(Role::TabAccent)->apply(Glyphs::BOOKMARK),
            [] !== $lineDiags => $numStyle->apply(DiagnosticStyle::glyph(DiagnosticStyle::worst($lineDiags))),
            default => ' ',
        };
        // The number label: empty when hidden (so str_pad to the 0-width column
        // yields nothing, and the marker column survives); relative distance from
        // the caret on non-caret lines in Relative mode (Vim "relativenumber").
        $numberLabel = LineNumberMode::Off === $this->lineNumbers ? '' : (string) ($lineIndex + 1);
        if (LineNumberMode::Relative === $this->lineNumbers && CaretLine::Other === $isCursorLine) {
            $numberLabel = (string) \abs($lineIndex - $this->document->getCursorLine());
        }

        return $this->vcsCell($lineIndex).$numStyle->apply(\str_pad($numberLabel, $numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT)).$marker.$this->foldCell($lineIndex).' ';
    }

    /**
     * The one-column fold marker: a triangle on a line a region starts at,
     * pointing down when it is open and right when it is collapsed, and a blank
     * everywhere else. Dimmed like the line numbers — it is an affordance, not
     * a finding, and the SHAPE is what says open or closed.
     */
    private function foldCell(int $line): string
    {
        $collapsed = $this->foldModel->foldMarkAt($line);

        return null === $collapsed
            ? ' '
            : $this->styles->of(Role::Gutter)->apply($collapsed ? Glyphs::COLLAPSED : Glyphs::EXPANDED);
    }

    /** The one-column git change strip, or '' when the gutter marks are disabled. */
    private function vcsCell(int $line): string
    {
        return $this->vcsGutter ? EditorRenderer::vcsCell($this->store->vcsMarks()[$line] ?? null, $this->styles) : '';
    }

    /**
     * One document line highlighted and fully decorated: indent guides, the
     * layered selection/caret/search/occurrence/bracket/diagnostic spans,
     * inlay hints, and the collapsed-fold marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
     */
    /**
     * The display column a member separator's rule starts at on this ROW, or
     * null when this row carries none.
     *
     * Null rather than a negative sentinel: `-1` and `-2` mean the same thing
     * to every caller, so the sentinel had slack nothing could assert.
     *
     * A soft-wrapped line is one line across several rows, and the rule closes
     * off the member — so it belongs under the last of them, not under each.
     * Spans are in the line's own display columns, which is why the answer is
     * an offset rather than a flag.
     */
    private function separatorFrom(int $lineIndex, int $rowSegment, int $contentWidth): ?int
    {
        if (($this->separators[$lineIndex] ?? null) === null) {
            return null;
        }
        if (null === $this->wrap) {
            return 0;
        }

        return $rowSegment === ($this->wrap->rowsFor($lineIndex, $contentWidth) - 1)
            ? WrapLayout::offsetOf($rowSegment, $contentWidth)
            : null;
    }

    /**
     * Spelled out rather than inferred: the type came from the single call
     * site until this method grew a parameter, and mago then read it as
     * `array<array-key, mixed>`.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
     */
    private function decoratedLine(
        int $lineIndex,
        string $highlighted,
        EditorDecorations $decorations,
        array $lineDiags,
        int $hScroll,
        int $contentWidth,
        ?int $separatorFrom = null,
    ): string {
        $doc = $this->document;
        $hlLine = $this->indentGuides ? EditorRenderer::applyIndentGuides($doc, $highlighted, $lineIndex, $this->styles) : $highlighted;

        // Gather decoration spans in layering order, then apply them all at
        // once. Each span is [startCol, endCol, openSgr, closeSgr, bgReapply].
        ['spans' => $spans, 'padTo' => $padTo] = LineSpans::forLine(
            $doc,
            $lineIndex,
            $decorations,
            $this->store->searchMatches()[$lineIndex] ?? [],
            $lineDiags,
            $this->styles,
            $this->semanticSpansFor($lineIndex),
            null === $separatorFrom ? 0 : $separatorFrom + $contentWidth,
        );
        if ($padTo > 0) {
            // Pad so an end-of-line caret has a cell to invert (the window
            // bound keeps padding within what's visible).
            $need = \min($padTo, $hScroll + $contentWidth);
            $width = AnsiUtils::visibleWidth($hlLine);
            if ($need > $width) {
                $hlLine .= \str_repeat(' ', \max(0, $need - $width));
            }
        }
        // One-pass application over viewport-clipped spans: the sequential
        // fold was exponential in same-word spans per line (prose killer).
        $hlLine = LineDecorator::applyAll($hlLine, LineSpans::clip($spans, $hScroll, $hScroll + $contentWidth));

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
