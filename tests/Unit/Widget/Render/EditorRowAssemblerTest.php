<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\FoldModel;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintMode;
use Porta\Editor\InlayLines;
use Porta\Editor\LineNumberMode;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorRowAssembler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class EditorRowAssemblerTest extends TestCase
{
    private function assembler(
        Document $document,
        ?Styles $styles = null,
        bool $vcsGutter = false,
        bool $indentGuides = false,
        LineNumberMode $lineNumbers = LineNumberMode::Absolute,
        ?DecorationStore $store = null,
        Translator $translator = new Translator('en'),
    ): array {
        $foldModel = new FoldModel($document);
        $store ??= new DecorationStore(static function (): void {});
        $assembler = new EditorRowAssembler(
            $document,
            $foldModel,
            $store,
            new InlayLines($store, new EditorDisplayOptions(static function (): void {})),
            $lineNumbers,
            $indentGuides,
            $vcsGutter,
            diagnosticMessages: DiagnosticMessageMode::None,
            focused: true,
            showCaret: true,
            styles: $styles,
            translator: $translator,
        );

        return [$assembler, $foldModel, $store];
    }

    private function noSelection(): EditorDecorations
    {
        return new EditorDecorations(false, null, [], [], null);
    }

    public function testAnInjectedThemeIsHonoredInsteadOfTheDarkDefault(): void
    {
        $doc = new Document("a\n");
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 3, contentWidth: 5, stripeCol: 0);

        [$default] = $this->assembler($doc);
        $rows = $default->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a'], stripe: [''], contentRows: 1, vScroll: 100, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);
        $darkPlaceholder = $rows[0];

        $light = new Styles(LightTheme::definition());
        [$injected] = $this->assembler($doc, styles: $light);
        $rows = $injected->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a'], stripe: [''], contentRows: 1, vScroll: 100, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);
        $lightPlaceholder = $rows[0];

        $this->assertNotSame($darkPlaceholder, $lightPlaceholder, 'an injected theme must actually be used, not silently replaced by the dark default');
        $this->assertSame(AnsiUtils::stripAnsiCodes($darkPlaceholder), AnsiUtils::stripAnsiCodes($lightPlaceholder), 'only the styling should differ, not the plain content');
    }

    public function testStickyHeaderRowPlacesTheRenderedRowBeforeItsStripeCell(): void
    {
        $doc = new Document("hello\n");
        [$assembler] = $this->assembler($doc);
        $geo = new EditorGeometry(numberWidth: 2, gutterWidth: 4, contentWidth: 5, stripeCol: 0);

        $rows = $assembler->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'x', 'line' => 0]],
            highlighted: ['hello'],
            stripe: ['<STRIPE>'],
            contentRows: 0,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        $plain = AnsiUtils::stripAnsiCodes($rows[0]);
        $this->assertStringEndsWith('<STRIPE>', $plain, 'the stripe cell must trail the rendered sticky row, not precede it');
        $this->assertSame('hello', \substr($plain, offset: \strpos($plain, needle: 'hello'), length: 5));
    }

    public function testStickyRowGutterConcatenatesVcsMarkerLabelAndTrailingSpaceInOrder(): void
    {
        $doc = new Document("hello\n");
        [$assembler, , $store] = $this->assembler($doc, vcsGutter: true, lineNumbers: LineNumberMode::Absolute);
        $store->setVcsMarks([0 => ChangeMarkKind::Modified]);
        // gutterWidth (20) deliberately doesn't match the gutter's own true
        // rendered width (6): only geo->gutterWidth feeds applyFullWidth's pad
        // target, so a gutterWidth-contentWidth mutant pads to a visibly
        // different (smaller) total than the real gutterWidth+contentWidth.
        $geo = new EditorGeometry(numberWidth: 3, gutterWidth: 20, contentWidth: 5, stripeCol: 0);

        $rows = $assembler->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'x', 'line' => 0]],
            highlighted: ['hello'],
            stripe: [''],
            contentRows: 0,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        $plain = AnsiUtils::stripAnsiCodes($rows[0]);
        // vcs marker, then the right-padded "1" label, then the marker column's
        // space, the fold column (blank — a sticky header of a document with no
        // regions), and the trailing space, then the content.
        $this->assertStringStartsWith(Glyphs::VCS_MODIFIED.'  1   hello', $plain);
        $this->assertSame(25, AnsiUtils::visibleWidth($rows[0]), 'the full row pads out to gutterWidth + contentWidth');
    }

    public function testIndentGuidesAreAppliedToTheStickyRowWhenEnabled(): void
    {
        $doc = new Document("    x\n");

        [$withGuides] = $this->assembler($doc, indentGuides: true);
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 2, contentWidth: 10, stripeCol: 0);
        $rows = $withGuides->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'x', 'line' => 0]],
            highlighted: ['    x'],
            stripe: [''],
            contentRows: 0,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );
        $this->assertStringContainsString(Glyphs::INDENT_GUIDE, AnsiUtils::stripAnsiCodes($rows[0]));

        [$without] = $this->assembler($doc, indentGuides: false);
        $rows = $without->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'x', 'line' => 0]],
            highlighted: ['    x'],
            stripe: [''],
            contentRows: 0,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );
        $this->assertStringNotContainsString(Glyphs::INDENT_GUIDE, AnsiUtils::stripAnsiCodes($rows[0]));
    }

    public function testGutterCellShowsTheLogpointGlyphWhenOnlyALogpointIsSet(): void
    {
        $doc = new Document("a\nb\n");
        $store = new DecorationStore(static function (): void {});
        $store->setBreakpointLines([], [], [1]); // line 1: logpoint only
        [$assembler] = $this->assembler($doc, store: $store);
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 5, stripeCol: 0);

        $rows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a', 'b'], stripe: ['', ''], contentRows: 2, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        $this->assertStringContainsString(Glyphs::LOGPOINT, AnsiUtils::stripAnsiCodes($rows[1]));
    }

    public function testGutterCellShowsTheConditionalBreakpointGlyphWhenOnlyOneIsSet(): void
    {
        $doc = new Document("a\nb\n");
        $store = new DecorationStore(static function (): void {});
        $store->setBreakpointLines([], [1], []); // line 1: conditional breakpoint only
        [$assembler] = $this->assembler($doc, store: $store);
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 5, stripeCol: 0);

        $rows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a', 'b'], stripe: ['', ''], contentRows: 2, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        $this->assertStringContainsString(Glyphs::BREAKPOINT_CONDITIONAL, AnsiUtils::stripAnsiCodes($rows[1]));
    }

    public function testCollapsedFoldMarkerReportsTheExactHiddenLineCountAndOrder(): void
    {
        $doc = new Document("a\nb\nc\nd\ne\n");
        [$assembler, $foldModel] = $this->assembler($doc);
        $doc->setCursor(1, 0);
        $foldModel->setRegions([[1, 3]], $doc->version); // fold covering lines 1-3, 2 lines hidden past the start
        $foldModel->toggle(); // caret sits on line 1, collapses that region
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 20, stripeCol: 0);

        $rows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a', 'b'], stripe: ['', ''], contentRows: 2, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        // foldEnd(3) - lineIndex(1) = 2 hidden lines; a foldEnd+lineIndex mutant
        // would misreport 4 instead, since start line (1) is nonzero here.
        $plain = AnsiUtils::stripAnsiCodes($rows[1]);
        $this->assertStringContainsString('b ⋯ 2 lines', $plain);
    }

    /** The marker speaks the UI's language, and counts a single hidden line as one. */
    public function testCollapsedFoldMarkerIsTranslatedAndPluralised(): void
    {
        $doc = new Document("a\nb\nc\nd\n");
        [$assembler, $foldModel] = $this->assembler($doc, translator: new Translator('fr'));
        $doc->setCursor(0, 0);
        $foldModel->setRegions([[0, 1], [2, 3]], $doc->version);
        $foldModel->toggle();
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 20, stripeCol: 0);

        $rows = $assembler->assemble(
            $geo,
            $this->noSelection(),
            sticky: [],
            highlighted: ['a', 'b', 'c', 'd'],
            stripe: ['', ''],
            contentRows: 1,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        $this->assertStringContainsString('a ⋯ 1 ligne', AnsiUtils::stripAnsiCodes($rows[0]));
        $this->assertStringNotContainsString('lignes', AnsiUtils::stripAnsiCodes($rows[0]));
    }

    public function testCaretPastEndOfLinePadsExactlyOneColumnForItsCell(): void
    {
        // A caret past the end of a short line makes LineSpans report a padTo
        // one column beyond the line's own display width (displayColAt clamps
        // the caret's column to the line's width, so this gap is structurally
        // never more than 1 — str_repeat(' ', N) and a literal ' ' are
        // equivalent here; this just exercises the padding branch at all).
        $doc = new Document("ab\n");
        [$assembler] = $this->assembler($doc);
        $decorations = new EditorDecorations(false, null, [Caret::point(0, 5)], [], null);
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 20, stripeCol: 0);

        $rows = $assembler->assemble($geo, $decorations, sticky: [], highlighted: ['ab'], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        // Padded to col 6 (5 + 1, for the caret's own cell) from a 2-char line:
        // 4 padding spaces before the reverse-video caret cell closes it off.
        $this->assertStringContainsString('ab    ', AnsiUtils::stripAnsiCodes($rows[0]));
    }

    public function testADenselyOccurrenceDecoratedProseLineStaysLinearInSize(): void
    {
        // The regression this round fixed: the old sequential span fold was
        // O(3^N) in escape bytes — 8 same-word spans on one line produced
        // ~120KB, 12 ran out of memory. Feed 12 occurrence spans directly
        // (bypassing the renderer's per-line cap) through a full assemble.
        $prose = \str_repeat('the quick brown fox jumps over the lazy dog ', times: 3);
        $doc = new Document($prose."\n");
        [$assembler] = $this->assembler($doc);
        $occurrences = [];
        for ($i = 0; $i < 12; ++$i) {
            $occurrences[] = [0, $i * 10, ($i * 10) + 3, false];
        }
        $decorations = new EditorDecorations(false, null, [], $occurrences, null);
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 3, contentWidth: 130, stripeCol: 0);

        $rows = $assembler->assemble($geo, $decorations, sticky: [], highlighted: [$prose], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        $this->assertLessThan(8_192, \strlen($rows[0]), 'span decoration must stay linear in span count');
        $this->assertStringContainsString('the quick brown fox', AnsiUtils::stripAnsiCodes($rows[0]));
    }

    public function testSearchMatchBackgroundsSurviveAnInteriorReset(): void
    {
        $doc = new Document("abcdef\n");
        [$assembler, , $store] = $this->assembler($doc);
        $store->setSearchMatches([0 => [['start' => 1, 'end' => 5, 'current' => false]]]);
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 3, contentWidth: 10, stripeCol: 0);

        // The highlighted line carries a raw reset INSIDE the match range;
        // the match background must be re-applied after it.
        $highlighted = 'ab'."\x1b[0m".'cdef';
        $rows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: [$highlighted], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 0);

        $plain = AnsiUtils::stripAnsiCodes($rows[0]);
        $this->assertStringContainsString('abcdef', $plain);
        $this->assertMatchesRegularExpression('/\x1b\[0m\x1b\[[0-9;]*4[08]/', $rows[0], 'a background code re-opens right after the interior reset');
    }

    /**
     * Scrolled past a hint, the window's first screen column shows an EARLIER
     * character than its number says — the hint moved the text right. The
     * spans are clipped in document columns, so they have to be clipped by
     * the character the window starts at: clipped by the screen column, a
     * match on the leftmost visible text was cut away.
     */
    public function testAMatchJustInsideAWindowScrolledPastAHintKeepsItsShade(): void
    {
        $doc = new Document("abcdefghij\n");
        [$assembler, , $store] = $this->assembler($doc);
        $store->setInlayHints([0 => [new InlayHint(0, 'XXXX')]]);
        $store->setSearchMatches([0 => [['start' => 4, 'end' => 6, 'current' => false]]]);
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 0, contentWidth: 8, stripeCol: 0);

        // Screen column 6 is the c: four hint cells, then a, b.
        $rows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['abcdefghij'], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 6);

        $this->assertStringStartsWith('cdefghij', AnsiUtils::stripAnsiCodes($rows[0]));
        $this->assertStringContainsString("\x1b[48;", \substr($rows[0], offset: 0, length: (int) \strpos($rows[0], needle: 'ef')), 'the match on e and f is still shaded');
    }

    public function testASpanLeftOfTheScrolledWindowIsClippedAway(): void
    {
        $doc = new Document(\str_repeat('x', times: 40)."\n");
        [$assembler] = $this->assembler($doc);
        // Occurrence entirely inside [0,3) — invisible at hScroll 10.
        $decorations = new EditorDecorations(false, null, [], [[0, 0, 3, false]], null);
        $geo = new EditorGeometry(numberWidth: 0, gutterWidth: 3, contentWidth: 10, stripeCol: 0);
        $line = \str_repeat('x', times: 40);

        $clippedRows = $assembler->assemble($geo, $decorations, sticky: [], highlighted: [$line], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 10);
        $plainRows = $assembler->assemble($geo, $this->noSelection(), sticky: [], highlighted: [$line], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 10);

        $this->assertSame($plainRows[0], $clippedRows[0], 'an off-window span leaves the visible slice untouched');

        // The same occurrence straddling the window edge still opens.
        $straddling = new EditorDecorations(false, null, [], [[0, 8, 12, false]], null);
        $straddledRows = $assembler->assemble($geo, $straddling, sticky: [], highlighted: [$line], stripe: [''], contentRows: 1, vScroll: 0, cursorLine: -1, cursorDisplayCol: 0, hScroll: 10);
        $this->assertNotSame($plainRows[0], $straddledRows[0], 'a window-straddling span still decorates the visible part');
    }

    /**
     * Without colour the caret line is marked in the gutter — `›` in the free
     * marker column, the number bold — and the row itself is not underlined
     * end to end; with colour, the band stays and the column stays free.
     */
    public function testWithoutColourTheGutterMarksTheCaretLine(): void
    {
        $doc = new Document("a\nb\n");
        $doc->setCursor(1, 0);
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 5, stripeCol: 0);
        [$mono] = $this->assembler($doc, styles: new Styles(MonochromeTheme::definition()));
        $rows = $mono->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a', 'b'], stripe: ['', ''], contentRows: 2, vScroll: 0, cursorLine: 1, cursorDisplayCol: 0, hScroll: 0);

        $this->assertStringStartsWith('2'.Glyphs::CARET_LINE, AnsiUtils::stripAnsiCodes($rows[1]));
        $this->assertStringStartsWith("\e[1m2", $rows[1], 'the number bold');
        $this->assertStringNotContainsString("\e[4m", $rows[1], 'no underline across the line');
        $this->assertStringNotContainsString(Glyphs::CARET_LINE, AnsiUtils::stripAnsiCodes($rows[0]));

        [$colour] = $this->assembler($doc);
        $rows = $colour->assemble($geo, $this->noSelection(), sticky: [], highlighted: ['a', 'b'], stripe: ['', ''], contentRows: 2, vScroll: 0, cursorLine: 1, cursorDisplayCol: 0, hScroll: 0);
        $this->assertStringNotContainsString(Glyphs::CARET_LINE, AnsiUtils::stripAnsiCodes($rows[1]), 'the band says it');
        $this->assertStringContainsString("\e[1m", AnsiUtils::sliceByColumn($rows[1], 0, 2), 'and the number is bold here too');
    }

    /**
     * The colours in force where $text is first drawn in $row — what the
     * reader sees, whatever order the codes before it came in.
     *
     * @return array{?string, ?string} the foreground and background SGR params, null for the default
     */
    private static function penAt(string $row, string $text): array
    {
        [$fg, $bg] = [null, null];
        $parts = \preg_split('/(\e\[[0-9;:]*m)/', $row, flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
        foreach (false === $parts ? [] : $parts as $part) {
            if (1 === \preg_match('/^\e\[([0-9;:]*)m$/', $part, $m)) {
                $params = $m[1];
                [$fg, $bg] = match (true) {
                    '' === $params, '0' === $params => [null, null],
                    '39' === $params => [null, $bg],
                    '49' === $params => [$fg, null],
                    \str_starts_with($params, '38;') => [$params, $bg],
                    \str_starts_with($params, '48;') => [$fg, $params],
                    default => [$fg, $bg],
                };
                continue;
            }
            if (\str_contains($part, $text)) {
                return [$fg, $bg];
            }
        }
        self::fail("{$text} is not drawn");
    }

    /** @return array{?string, ?string} the pen a role draws in */
    private static function pen(Styles $styles, Role $role): array
    {
        return self::penAt($styles->of($role)->apply('X'), 'X');
    }

    /** @return list<string> each row's gutter cells, $gutterWidth wide */
    private function gutters(DecorationStore $store, Document $doc, ?Styles $styles = null, ?EditorDisplayOptions $display = null, int $rows = 3): array
    {
        $assembler = new EditorRowAssembler(
            $doc,
            new FoldModel($doc),
            $store,
            new InlayLines($store, $display ?? new EditorDisplayOptions(static function (): void {})),
            LineNumberMode::Absolute,
            false,
            false,
            DiagnosticMessageMode::None,
            true,
            true,
            $styles,
        );
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 5, contentWidth: 5, stripeCol: 0);
        $highlighted = \array_map($doc->getLine(...), \range(0, $doc->getLineCount() - 1));
        $out = $assembler->assemble(
            $geo,
            $this->noSelection(),
            sticky: [],
            highlighted: $highlighted,
            stripe: \array_fill(0, $rows, value: ''),
            contentRows: $rows,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        return \array_map(static fn (string $row): string => AnsiUtils::sliceByColumn($row, 0, 5), $out);
    }

    /** A problem's line number says it by colour alone: the worst severity's tint, never a glyph. */
    public function testAProblemTintsItsLineNumberByItsWorstSeverity(): void
    {
        $doc = new Document("a\nb\nc\nd\n");
        $store = new DecorationStore(static function (): void {});
        $store->setDiagnostics([
            0 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0], ['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]],
            1 => [['severity' => DiagnosticSeverity::Information, 'message' => 'i', 'col' => 0]],
            2 => [['severity' => DiagnosticSeverity::Hint, 'message' => 'h', 'col' => 0]],
        ]);
        $styles = Styles::dark();
        $gutters = $this->gutters($store, $doc, $styles, rows: 4);

        $this->assertSame(self::pen($styles, Role::GutterError), self::penAt($gutters[0], '1'), 'an error over a warning on the same line');
        $this->assertSame(self::pen($styles, Role::GutterNotice), self::penAt($gutters[1], '2'), 'a notice');
        $this->assertSame([self::pen($styles, Role::Gutter)[0], self::pen($styles, Role::GutterBg)[1]], self::penAt($gutters[2], '3'), 'a hint marks nothing');
        foreach ($gutters as $gutter) {
            $this->assertMatchesRegularExpression('/^ *\d+ *$/', AnsiUtils::stripAnsiCodes($gutter), 'the number alone: no glyph beside it');
            $this->assertMatchesRegularExpression('/^(\e\[[0-9;]*m)*\e\[48;5;(0|1|11)m/', $gutter, 'every row on the gutter\'s shade or its tint, from its first cell');
        }
    }

    public function testAWarningAloneIsWarningTinted(): void
    {
        $doc = new Document("a\n");
        $store = new DecorationStore(static function (): void {});
        $store->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0]]]);
        $styles = Styles::dark();

        $this->assertSame(self::pen($styles, Role::GutterWarning), self::penAt($this->gutters($store, $doc, $styles, rows: 1)[0], '1'));
    }

    /** While hints show as cards, a line that has some is faintly tinted; a problem outranks it, and in the line itself they need no tint. */
    public function testALineWithHintsAsCardsIsFaintlyTinted(): void
    {
        $doc = new Document("f(1)\ng(2)\n");
        $store = new DecorationStore(static function (): void {});
        $store->setInlayHints([0 => [new InlayHint(2, 'n:')], 1 => [new InlayHint(2, 'm:')]]);
        $store->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]]);
        $display = new EditorDisplayOptions(static function (): void {});
        $styles = Styles::dark();

        $display->setInlayHints(InlayHintMode::Tooltip);
        $cards = $this->gutters($store, $doc, $styles, $display, rows: 2);
        $this->assertSame(self::pen($styles, Role::GutterInlay), self::penAt($cards[0], '1'));
        $this->assertSame(self::pen($styles, Role::GutterError), self::penAt($cards[1], '2'), 'the problem outranks the hint');

        $display->setInlayHints(InlayHintMode::Inline);
        $this->assertSame(
            [self::pen($styles, Role::Gutter)[0], self::pen($styles, Role::GutterBg)[1]],
            self::penAt($this->gutters($store, $doc, $styles, $display, rows: 2)[0], '1'),
            'hints in the line: no tint',
        );
    }

    /**
     * A tinted line's whole gutter is the tint — its git strip, number,
     * marker and fold column — and a mark in it keeps its glyph; a wrapped
     * line's next rows are on the gutter's own shade.
     */
    public function testTheTintCoversTheLinesWholeGutter(): void
    {
        $doc = new Document("a\nb\n");
        $store = new DecorationStore(static function (): void {});
        $store->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]]);
        $store->setBreakpointLines([0]);
        $styles = Styles::dark();
        [$tinted, $plain] = $this->gutters($store, $doc, $styles, rows: 2);

        $this->assertStringStartsWith("\e[38;5;0m\e[48;5;1m", $tinted, 'from its first cell');
        $this->assertStringContainsString($styles->of(Role::Breakpoint)->apply(Glyphs::BREAKPOINT), $tinted, 'the mark keeps its glyph');
        $this->assertStringNotContainsString("\e[48;5;0m", $tinted, 'and no cell of it falls back to the gutter\'s shade');
        $this->assertStringStartsWith("\e[48;5;0m", $plain, 'the next line is on the gutter\'s own shade');
    }

    /** Past the end and on a wrapped line's next rows, the gutter is still the gutter. */
    public function testEveryRowIsOnTheGuttersShade(): void
    {
        $doc = new Document("a\n");
        $gutters = $this->gutters(new DecorationStore(static function (): void {}), $doc, Styles::dark(), rows: 3);

        $this->assertStringStartsWith("\e[48;5;0m", $gutters[2]);
        $this->assertStringContainsString('~', AnsiUtils::stripAnsiCodes($gutters[2]));
    }

    /** Without colour, an error is reversed and a warning underlined — still no glyph. */
    public function testMonochromeReversesAnErrorAndUnderlinesAWarning(): void
    {
        $doc = new Document("a\nb\n");
        $store = new DecorationStore(static function (): void {});
        $store->setDiagnostics([0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]], 1 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'w', 'col' => 0]]]);
        $gutters = $this->gutters($store, $doc, new Styles(MonochromeTheme::definition()), rows: 2);

        $this->assertStringContainsString("\e[7m1", $gutters[0]);
        $this->assertStringContainsString("\e[4m2", $gutters[1]);
    }

    /** Without colour the sticky band is ruled off from the code under it. */
    public function testWithoutColourTheStickyBandsLastRowIsUnderlined(): void
    {
        $doc = new Document("class A\n{\n  x\n");
        $geo = new EditorGeometry(numberWidth: 1, gutterWidth: 3, contentWidth: 10, stripeCol: 0);
        [$mono] = $this->assembler($doc, styles: new Styles(MonochromeTheme::definition()));
        $rows = $mono->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'A', 'line' => 0], ['label' => '{', 'line' => 1]],
            highlighted: ['class A', '{', '  x'],
            stripe: ['', '', ''],
            contentRows: 1,
            vScroll: 2,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        $this->assertStringNotContainsString("\e[4m", $rows[0]);
        $this->assertStringContainsString("\e[4m", $rows[1], 'the last pinned row');

        [$colour] = $this->assembler($doc);
        $rows = $colour->assemble(
            $geo,
            $this->noSelection(),
            sticky: [['label' => 'A', 'line' => 0]],
            highlighted: ['class A', '{', '  x'],
            stripe: ['', '', ''],
            contentRows: 1,
            vScroll: 2,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );
        $this->assertStringNotContainsString("\e[4m", $rows[0], 'the band colour says it');
    }
}
