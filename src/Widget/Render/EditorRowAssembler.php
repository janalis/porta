<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Editor\GutterPart;
use Porta\Editor\InlayHintMode;
use Porta\Editor\LineNumberMode;
use Porta\Editor\WhitespaceGlyphs;
use Porta\Editor\WrapLayout;
use Porta\Highlight\SemanticTokenStyle;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;
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
        /** Only {@see DiagnosticMessageMode::Inline} puts the message after the code. */
        private DiagnosticMessageMode $diagnosticMessages,
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
        /** Speaks the collapsed-region marker ("⋯ 12 lines") in the UI's language. */
        private Translator $translator = new Translator('en'),
        /** Only {@see InlayHintMode::Inline} splices the hints into the line; a hover card is not painted here. */
        private InlayHintMode $inlayHints = InlayHintMode::Inline,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->breakpointSet = \array_fill_keys($store->breakpointLines, value: true);
        $this->conditionalBreakpointSet = \array_fill_keys($store->conditionalBreakpointLines, value: true);
        $this->logpointSet = \array_fill_keys($store->logpointLines, value: true);
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

        // Pinned sticky-scroll headers occupy the top rows; content follows below.
        $result = $this->stickyRows($sticky, $geo, $highlighted, $stripe, $hScroll);
        // One walking cursor instead of docLineAtRow per row: that helper
        // re-walks from $vScroll on every call, making the loop O(rows²).
        // Same sequence — the row-th visible line at or after $vScroll.
        $lineCount = $this->document->getLineCount();
        $walk = $vScroll;
        // Which row OF $walk this screen row shows. A line occupies one row per
        // code lens above it, one per wrapped segment, then one per block row
        // under it — so this is 0 for most lines and the walk moves on as soon
        // as it runs out.
        // Rows of the top line hidden above the viewport: scrolling INTO a
        // wrapped line taller than the pane starts the walk partway down it.
        $rowInLine = $vScrollSegment;
        for ($row = 0; $row < $contentRows; ++$row) {
            $lineIndex = $walk < $lineCount ? $walk : null;
            $atRow = $rowInLine;
            if (null !== $lineIndex) {
                $more = ($rowInLine + 1) < $this->rowsOf($lineIndex, $geo);
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

            // A lens row above the line, or a block row under it, shows none
            // of the line's text, so it takes no number, no marks and no caret.
            $virtual = $this->virtualRow($lineIndex, $atRow, $geo);
            if (null !== $virtual) {
                $result[] = $virtual.$stripe[$stickyCount + $row];

                continue;
            }
            $rowSegment = $atRow - \count($this->store->codeLensesAt($lineIndex));

            $isCursorLine = $lineIndex === $cursorLine && (null === $this->wrap || $rowSegment === WrapLayout::segmentOf($cursorDisplayCol, $geo->contentWidth));
            $lineDiags = $this->store->diagnostics[$lineIndex] ?? [];

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

            $content = $this->lineBand($content, $lineIndex, $isCursorLine && $this->focused && null === $decorations->selection, $geo->contentWidth);

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
            $debugValue = $this->store->debugValues[$lineIndex] ?? '';
            $hasInlineDiagnostic = '' === $debugValue && DiagnosticMessageMode::Inline === $this->diagnosticMessages && [] !== $lineDiags;
            $trailing = '' !== $debugValue || $hasInlineDiagnostic ? $debugValue : $this->store->lineAnnotations[$lineIndex] ?? '';
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
     * A row's full-width background: the debugger's stopped line, which
     * outranks the rest, else the caret line's band — skipped while a
     * selection shows. A colour band only: without colour it would be an
     * underline under every character of the line being typed, so there the
     * gutter marks the caret line instead ({@see gutterCell()}).
     */
    // @mago-expect lint:no-boolean-flag-parameter -- whether this row is the focused caret line is the row's state, computed by the caller from four facts this method does not see.
    private function lineBand(string $content, int $lineIndex, bool $caretBand, int $width): string
    {
        if ($lineIndex === $this->store->debugLine) {
            return LineDecorator::applyFullWidth($content, $width, $this->styles->of(Role::DebugLineBg));
        }
        if ($caretBand && $this->styles->hasColor()) {
            return LineDecorator::applyFullWidth($content, $width, $this->styles->of(Role::RowHighlightBg));
        }

        return $content;
    }

    /**
     * The pinned sticky-scroll headers, each with its stripe cell. Without
     * colour the band is the code's own shade, so its last row is ruled off by
     * an underline: pinned headers above, the file below.
     *
     * @param list<array{label: string, line: int}> $sticky
     * @param array<array-key, string>              $highlighted
     * @param list<string>                          $stripe
     *
     * @return list<string>
     */
    private function stickyRows(array $sticky, EditorGeometry $geo, array $highlighted, array $stripe, int $hScroll): array
    {
        $rows = [];
        foreach ($sticky as $i => $header) {
            $row = $this->renderStickyRow($header['line'], $geo, $highlighted, $hScroll);
            if ($i === (\count($sticky) - 1) && !$this->styles->hasColor()) {
                $row = LineDecorator::applyFullWidth($row, $geo->gutterWidth + $geo->contentWidth, new Style(underline: true));
            }
            $rows[] = $row.$stripe[$i];
        }

        return $rows;
    }

    /**
     * One code-lens row: the server's own words, dim, where a line number
     * would be. PhpStorm indents these to the code they describe; the gutter
     * already provides that indent, so the text starts at the content column.
     */
    private function lensRow(string $title, EditorGeometry $geo): string
    {
        // sliceToWidth, not sliceWithWidth: a wide glyph across the edge of a
        // translated or CJK title would make the row a column too wide.
        return AnsiText::blank(\max(0, $geo->gutterWidth)).AnsiUtils::sliceToWidth($this->styles->of(Role::Dim)->apply($title), 0, $geo->contentWidth);
    }

    /** The rows a line occupies: its lenses above, its wrapped segments, its block rows under. */
    private function rowsOf(int $line, EditorGeometry $geo): int
    {
        return \count($this->store->codeLensesAt($line)) + $this->segmentsOf($line, $geo) + \count($this->store->lineBlocksAt($line));
    }

    private function segmentsOf(int $line, EditorGeometry $geo): int
    {
        return $this->wrap?->rowsFor($line, $geo->contentWidth) ?? 1;
    }

    /**
     * Row $atRow of $line when it is a lens (the rows before the text) or a
     * block row (the rows after it); null for one of the line's own segments.
     */
    private function virtualRow(int $line, int $atRow, EditorGeometry $geo): ?string
    {
        $lenses = $this->store->codeLensesAt($line);
        $lens = $lenses[$atRow] ?? null;
        if (null !== $lens) {
            return $this->lensRow($lens['title'], $geo);
        }
        $block = $this->store->lineBlocksAt($line)[$atRow - \count($lenses) - $this->segmentsOf($line, $geo)] ?? null;

        return null === $block ? null : $this->blockRow($block, $geo);
    }

    /**
     * One row of a block under a line: the writer's plain text, dim like an
     * inlay, starting at the content column.
     */
    private function blockRow(string $text, EditorGeometry $geo): string
    {
        return (
            AnsiText::blank(\max(0, $geo->gutterWidth)).AnsiText::truncate($this->styles->of(Role::Inlay)->apply(\str_replace("\t", replace: '    ', subject: $text)), $geo->contentWidth, pad: true)
        );
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
     * @param list<array{col: int, endCol?: int, message: string, severity: DiagnosticSeverity, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
     */
    private function gutterCell(int $lineIndex, CaretLine $isCursorLine, array $lineDiags, int $numberWidth): string
    {
        $numStyle = match (true) {
            [] !== $lineDiags => $this->styles->of(DiagnosticStyle::worst($lineDiags)->role()),
            CaretLine::Current === $isCursorLine => $this->styles->of(Role::CurrentLineNumber),
            default => $this->styles->of(Role::Gutter),
        };
        // The caret line's number is bold in every theme — its weight says
        // "here" where its colour cannot.
        if (CaretLine::Current === $isCursorLine) {
            $numStyle = $numStyle->withBold();
        }
        // The marker column, one glyph by priority: the debugger's stopped line,
        // then the breakpoint dot, then the BOOKMARK, then a conversation's
        // COMMENT mark, then the worst diagnostic's severity glyph — so each
        // state also reads without color. The comment outranks the diagnostic
        // for the bookmark's reason below: it has nowhere else in the gutter.
        //
        // The bookmark outranks the diagnostic because it is the only one of
        // the two with nowhere else to show. A diagnostic already colours the
        // line NUMBER beside this cell, paints the error stripe in the right
        // margin, writes its message inline at the end of the line and lists
        // itself in the Problems panel; a bookmark has this cell and nothing
        // else. Ordered the other way, a single HINT — the weakest severity
        // there is — made a bookmark invisible.
        $marker = match (true) {
            $lineIndex === $this->store->debugLine => $this->styles->of(Role::Breakpoint)->apply(Glyphs::STOPPED_LINE),
            ($this->logpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::LOGPOINT),
            ($this->conditionalBreakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::BREAKPOINT_CONDITIONAL),
            ($this->breakpointSet[$lineIndex] ?? null) !== null => $this->styles->of(Role::Breakpoint)->apply(Glyphs::BREAKPOINT),
            $this->store->isBookmarked($lineIndex) => $this->styles->of(Role::TabAccent)->apply(Glyphs::BOOKMARK),
            \array_key_exists($lineIndex, $this->store->commentMarks) => $this->styles->of(Role::Hunk)->apply(Glyphs::COMMENT),
            [] !== $lineDiags => $numStyle->apply(DiagnosticStyle::worst($lineDiags)->glyph()),
            // Without colour the free column points at the caret line.
            CaretLine::Current === $isCursorLine && !$this->styles->hasColor() => $numStyle->apply(Glyphs::CARET_LINE),
            default => ' ',
        };
        // A breakpoint or a bookmark took the column a diagnostic's glyph
        // would have had: the number it coloured is underlined as well, so
        // the finding still reads where there is no colour.
        if ([] !== $lineDiags && !\str_contains(AnsiUtils::stripAnsiCodes($marker), DiagnosticStyle::worst($lineDiags)->glyph())) {
            $numStyle = $numStyle->withUnderline();
        }
        // The number label: empty when hidden (so str_pad to the 0-width column
        // yields nothing, and the marker column survives); relative distance from
        // the caret on non-caret lines in Relative mode (Vim "relativenumber").
        $numberLabel = LineNumberMode::Off === $this->lineNumbers ? '' : (string) ($lineIndex + 1);
        if (LineNumberMode::Relative === $this->lineNumbers && CaretLine::Other === $isCursorLine) {
            $numberLabel = (string) \abs($lineIndex - $this->document->cursorLine);
        }

        // The mark under the pointer underlines, as every clickable thing does.
        $hover = $this->store->gutterHover;
        // Only a mark: a click can take one away under a resting pointer, and
        // an underlined blank reads as a stray underscore.
        $pointed = static fn (GutterPart $part, string $cell): string => null !== $hover && $hover['line'] === $lineIndex && $hover['part'] === $part && '' !== \trim(AnsiUtils::stripAnsiCodes($cell))
            ? new Style(underline: true)->apply($cell)
            : $cell;

        return (
            $pointed(GutterPart::Change, $this->vcsCell($lineIndex))
            .$numStyle->apply(\str_pad($numberLabel, $numberWidth, pad_string: ' ', pad_type: \STR_PAD_LEFT))
            .$pointed(GutterPart::Marker, $marker)
            .$this->foldCell($lineIndex)
            .' '
        );
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
        return $this->vcsGutter ? EditorRenderer::vcsCell($this->store->vcsMarks[$line] ?? null, $this->styles) : '';
    }

    /**
     * One document line highlighted and fully decorated: indent guides, the
     * layered selection/caret/search/occurrence/bracket/diagnostic spans,
     * inlay hints, and the collapsed-fold marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: DiagnosticSeverity, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
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
     * @param list<array{col: int, endCol?: int, message: string, severity: DiagnosticSeverity, code?: int|string, source?: string, tags?: list<int>}> $lineDiags
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
            $this->store->searchMatches[$lineIndex] ?? [],
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
        if (InlayHintMode::Inline === $this->inlayHints) {
            $hlLine = EditorRenderer::applyInlayHints($doc, $lineIndex, $this->store->inlayHintsAt($lineIndex), $hlLine, $this->styles);
        }

        // Collapsed-region marker on the fold-start line.
        if (null !== ($foldEnd = $this->foldModel->foldEndAt($lineIndex))) {
            $hidden = $foldEnd - $lineIndex;
            $hlLine .= $this->styles->of(Role::Placeholder)->apply(' ⋯ '.$this->translator->trans('%count% line|%count% lines', ['%count%' => $hidden]));
        }

        return $hlLine;
    }
}
