<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\Overlay\State\SearchModalState;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Ui\CaretVisibility;
use Porta\Ui\TextField;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\PickerLayout;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A picker's box must seat the rows it draws.
 *
 * Both layouts size themselves from what VARIES — the title, the query, the
 * item labels — and both then draw a filler row that varies with none of it.
 * A row nothing measures is a row that truncates, which is the defect this
 * modal family keeps producing; here it is worse than usual, because the
 * filler is cut with `ellipsis: ''` and so gives no sign it was cut at all.
 */
final class PickerLayoutTest extends TestCase
{
    private function styles(): Styles
    {
        return new Styles(DarkTheme::definition());
    }

    /**
     * Every translation of the list overlay's "no matches", in a box sized
     * from a three-letter title and two short rows — the shape a Run or a
     * Branches list actually has.
     *
     * @return iterable<string, array{string}>
     */
    public static function fillerMessages(): iterable
    {
        yield 'en' => ['no matches'];
        yield 'de' => ['keine Treffer'];
        yield 'fr' => ['aucune correspondance'];
        yield 'es' => ['sin coincidencias'];
        yield 'he' => ['אין התאמות'];
    }

    #[DataProvider('fillerMessages')]
    public function testTheListFillerIsNotCutByABoxSizedWithoutIt(string $message): void
    {
        $filter = new TextField();
        foreach (\str_split('zzz') as $character) {
            $filter->insert($character);
        }

        $out = PickerLayout::layoutList(
            [0, 0, 24, 100],
            [
                'title' => 'Run',
                'items' => [
                    ['label' => 'test', 'hint' => '', 'value' => 't'],
                    ['label' => 'dev', 'hint' => '', 'value' => 'd'],
                ],
                'selected' => 0,
                'hovered' => null,
                'filter' => $filter,
                'onPick' => static fn (): null => null,
            ],
            $this->styles(),
            closeHovered: false,
            emptyMessage: $message,
        );

        $this->assertContains(
            $message,
            \array_map(
                static fn (string $row): string => \trim(AnsiUtils::stripAnsiCodes($row), characters: ' │'),
                $out['rows'],
            ),
            'the filler row is drawn whole',
        );
    }

    /**
     * A filter longer than its box shows what you just typed, not what you
     * typed first.
     *
     * The row used to be truncated whole, which puts the ellipsis at the END:
     * past the box width the row stopped changing as you typed, showing the
     * opening characters of a filter you could no longer read. The box is sized
     * from the UNFILTERED item labels and never grows, so this is reachable by
     * typing a branch name into a list of short ones — which is exactly when
     * anyone types a long filter.
     *
     * The sibling query row in {@see PickerLayout::layoutSearch()} has always
     * scrolled, via a cursor marker this row deliberately does not carry.
     */
    public function testALongFilterShowsItsTailRatherThanItsHead(): void
    {
        $typed = 'feature/a-very-long-branch-name';
        $filter = new TextField();
        foreach (\mb_str_split($typed) as $character) {
            $filter->insert($character);
        }

        $row = $this->filterRow($filter);

        // Whatever the box has room for must come from the END of the typed
        // text — the box is narrow, so the assertion asks for the last few
        // characters rather than a fixed count that would pin the width.
        $this->assertStringContainsString(\mb_substr($typed, start: -6), $row, 'the end of the filter, where the caret is');
        $this->assertStringNotContainsString('feature/', $row, 'not the start, which is what an end-truncate keeps');
        $this->assertMatchesRegularExpression('/┃ .+ ┃/u', $row, 'and the box around it does not scroll away');
    }

    /** A filter that fits is drawn from its start — the scroll only engages past the width. */
    public function testAFilterThatFitsIsUnchanged(): void
    {
        $filter = new TextField();
        foreach (\mb_str_split('dev') as $character) {
            $filter->insert($character);
        }

        $this->assertMatchesRegularExpression('/┃ dev +┃/u', $this->filterRow($filter));
    }

    /**
     * A Run-shaped list overlay: a three-letter title over two short rows,
     * which is the box narrow enough for a filler or a filter to overrun.
     *
     * @return array{rows: list<string>, top: int, col: int, width: int, box: \Porta\Overlay\Region\ModalBox}
     */
    private function layout(TextField $filter, string $emptyMessage = 'no matches'): array
    {
        return PickerLayout::layoutList(
            [0, 0, 24, 100],
            [
                'title' => 'Run',
                'items' => [
                    ['label' => 'test', 'hint' => '', 'value' => 't'],
                    ['label' => 'dev', 'hint' => '', 'value' => 'd'],
                ],
                'selected' => 0,
                'hovered' => null,
                'filter' => $filter,
                'onPick' => static fn (): null => null,
            ],
            $this->styles(),
            closeHovered: false,
            emptyMessage: $emptyMessage,
        );
    }

    /**
     * The filter and the list are two zones of one box: a tee between them,
     * and the list's click band starts below it — a click on the tee picks
     * nothing.
     */
    public function testATeeSeparatesTheFilterFromTheList(): void
    {
        $out = $this->layout(new TextField());
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $out['rows']);
        $tee = 1 + ModalFrame::CHROME_ROWS_TOP - 1 + FieldBox::ROWS; // title bar, padding, the filter field

        $this->assertMatchesRegularExpression('/^├─+┤$/u', $plain[$tee]);
        $this->assertStringContainsString('test', $plain[$tee + 1], 'the first item right under it');
        $this->assertSame($tee + 1, $out['box']->headerRows, 'the list band starts past the tee');
    }

    public function testATeeSeparatesTheQueryFromTheResults(): void
    {
        $state = new SearchModalState('Go to File', new TextField(), static function (): void {}, static function (): void {}, static function (): void {}, 'none');
        $out = PickerLayout::layoutSearch([0, 0, 24, 100], $state, $this->styles(), closeHovered: false, caret: CaretVisibility::Hidden, fieldRows: 1);
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $out['rows']);

        $this->assertMatchesRegularExpression('/^├─+┤$/u', $plain[3], 'title bar, padding, the one-row query, then the tee');
        $this->assertSame(4, $out['box']->headerRows);
    }

    /** A category past the first opens a section of its own: its tee takes the one row its header did. */
    public function testALaterCategoryIsATeeOfItsOwnInTheHeadersRow(): void
    {
        $noop = static function (): void {};
        $state = new SearchModalState('Search Everywhere', new TextField(), $noop, $noop, $noop, 'none');
        $state->rows = [
            SearchRow::header('Classes'),
            SearchRow::hit(new SearchHit(SearchCategory::Classes, 'Greeter', '', $noop)),
            SearchRow::header('Files'),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'a.php', '', $noop)),
        ];
        $out = PickerLayout::layoutSearch([0, 0, 24, 100], $state, $this->styles(), closeHovered: false, caret: CaretVisibility::Hidden, fieldRows: 1);
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $out['rows']);

        $this->assertMatchesRegularExpression('/^├─ Classes ─+┤$/u', $plain[3], 'title bar, padding, the query, then its tee, named');
        $this->assertStringContainsString('Greeter', $plain[4]);
        $this->assertMatchesRegularExpression('/^├─ Files ─+┤$/u', $plain[5]);
        $this->assertStringContainsString('a.php', $plain[6]);
        $this->assertSame(3, $out['box']->headerRows, 'the named tee is the first row of the list: row 4 maps to the second, the hit');
    }

    /** The list overlay's filter row, plain. */
    private function filterRow(TextField $filter): string
    {
        $out = $this->layout($filter);

        // The glyph is the box's legend; the typed text is on the row below.
        foreach ($out['rows'] as $i => $row) {
            if (\str_contains(AnsiUtils::stripAnsiCodes($row), '≡')) {
                return AnsiUtils::stripAnsiCodes($out['rows'][$i + 1]);
            }
        }

        self::fail('the picker drew no filter row');
    }

    /**
     * The box does not resize as the filter stops matching.
     *
     * The width is taken over the UNFILTERED items on purpose: a box sized from
     * what survives the filter shrinks and re-centres on every keystroke,
     * walking out from under the eye reading it. Counting the filler row only
     * when it is about to be drawn puts that resize straight back — measured at
     * 15 columns, then 27 on the keystroke that emptied the list — which is why
     * it is measured whether or not it will be shown.
     */
    public function testTheBoxKeepsItsWidthWhenTheFilterStopsMatching(): void
    {
        $widths = [];
        foreach (['', 'd', 'zz'] as $typed) {
            $filter = new TextField();
            foreach (\mb_str_split($typed) as $character) {
                $filter->insert($character);
            }
            $widths[] = $this->layout($filter, 'aucune correspondance')['width'];
        }

        $this->assertSame([$widths[0], $widths[0], $widths[0]], $widths, 'one width, whatever the filter matches');
    }

    /**
     * The search overlay's own filler. Its 24-column floor happens to seat
     * every translation of "no results" today, which is luck and not a rule —
     * so the box is asked to seat a longer one.
     */
    public function testTheSearchFillerIsNotCutByABoxSizedWithoutIt(): void
    {
        $message = 'aucun résultat pour cette recherche';
        $state = new SearchModalState(
            'Go to File',
            new TextField(),
            static function (): void {},
            static function (): void {},
            static function (): void {},
            $message,
        );

        $out = PickerLayout::layoutSearch([0, 0, 24, 100], $state, $this->styles(), closeHovered: false, caret: CaretVisibility::Hidden);

        $this->assertContains(
            $message,
            \array_map(
                static fn (string $row): string => \trim(AnsiUtils::stripAnsiCodes($row), characters: ' │'),
                $out['rows'],
            ),
            'the filler row is drawn whole',
        );
    }

    /**
     * Every picker row is exactly as wide as the box says it is.
     *
     * The overlay half of {@see \Porta\Tests\Unit\Widget\NarrowPaneRenderTest}.
     * That sweep walks WIDGETS down to one column; a picker is not one — it is
     * composited through the overlay host — so nothing had ever graded its
     * geometry at a narrow bound. `PickerLayout` carries the same
     * `max(1, $width - …)` idiom that produced six real overflows elsewhere
     * today, which is why it is worth saying out loud that here it holds.
     *
     * **What holds it is {@see ModalFrame::wrapSections()}, not that
     * arithmetic.** Every content row is clipped to the inner width in one
     * place, so the row assembly above it can be off by a column and the box
     * still comes out square — mutating those inner bounds does not fail this
     * test, and that is the finding, not a gap in it. It is the ceiling the six
     * widget rows were each missing: they assembled a row and nobody clipped it.
     *
     * From eight columns, which is the honest floor: below it the frame's own
     * corners and padding (four columns) no longer fit and `$width` goes
     * negative, because `min($width, $columns - MARGIN, MAX_INNER)` has no
     * lower bound. That is unreachable in normal use — `clampSidebarWidth()`
     * keeps the main pane at twenty columns — and it bleeds rather than throws,
     * so it is recorded rather than guarded.
     */
    #[DataProvider('boundsWidths')]
    public function testEveryPickerRowIsExactlyTheWidthTheBoxDeclares(int $columns): void
    {
        $filter = new TextField();
        $filter->insert('dev');
        $list = PickerLayout::layoutList(
            [0, 0, 24, $columns],
            [
                'title' => 'Run',
                'items' => [['label' => 'test suite', 'hint' => 'Ctrl+T', 'value' => 't']],
                'selected' => 0,
                'hovered' => null,
                'filter' => $filter,
                'onPick' => static fn (): null => null,
            ],
            $this->styles(),
            closeHovered: false,
            emptyMessage: 'no matches',
        );

        $query = new TextField();
        $query->insert('abc');
        $state = new SearchModalState('Go to File', $query, static function (): void {}, static function (): void {}, static function (): void {}, 'no results');
        // Real hit rows, and long ones: the ` label · detail ` assembler is the
        // piece that bounds the detail first and gives the label the rest, and
        // an empty results list renders the filler instead and grades none of
        // it. A mutant that loosened the detail's bound by one column survived
        // this test until these rows were here.
        $state->rows = [
            SearchRow::header('Files'),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'AVeryLongClassNameIndeed.php', 'src/Deeply/Nested/Namespace/Path', static function (): void {})),
            SearchRow::hit(new SearchHit(SearchCategory::Files, 'b.php', '', static function (): void {})),
        ];
        $search = PickerLayout::layoutSearch(
            [0, 0, 24, $columns],
            $state,
            $this->styles(),
            closeHovered: false,
            caret: CaretVisibility::Hidden,
        );

        foreach (['list' => $list, 'search' => $search] as $name => $out) {
            foreach ($out['rows'] as $row => $text) {
                $this->assertSame(
                    $out['width'],
                    AnsiUtils::visibleWidth($text),
                    \sprintf('%s row %d at %d columns', $name, $row, $columns),
                );
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function boundsWidths(): iterable
    {
        foreach ([8, 9, 12, 20, 24, 40, 60, 100, 200] as $columns) {
            yield $columns.' columns' => [$columns];
        }
    }

    /** @param list<SearchRow> $rows */
    private function searchLayout(array $rows, int $columns = 100): array
    {
        $state = new SearchModalState('Find Action', new TextField(), static fn (): null => null, static fn (): null => null, static fn (): null => null);
        $state->rows = $rows;
        $state->widest = 200; // as wide as the bounds allow

        return PickerLayout::layoutSearch([0, 0, 24, $columns], $state, $this->styles(), closeHovered: false, caret: CaretVisibility::Hidden, placeholder: 'Type to search…');
    }

    /**
     * A command whose context is absent is drawn as its menu item is greyed:
     * dim and struck through, attributes that survive --no-ansi. Its hint
     * (the key) is left alone.
     */
    public function testADisabledHitIsDimAndStruckThrough(): void
    {
        $noop = static fn (): null => null;
        $rows = $this->searchLayout([
            SearchRow::header('Actions'),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Step Over', 'F8', $noop, enabled: false)),
            SearchRow::hit(new SearchHit(SearchCategory::Actions, 'Save', 'Ctrl+S', $noop)),
        ])['rows'];
        $row = static fn (string $label): string => \array_first(\array_filter($rows, static fn (string $r): bool => \str_contains(AnsiUtils::stripAnsiCodes($r), $label)));

        $this->assertMatchesRegularExpression('/\e\[9m(\e\[[0-9;]*m)*Step Over/', $row('Step Over'), 'struck through');
        $this->assertMatchesRegularExpression('/\e\[2m(\e\[[0-9;]*m)*Step Over/', $row('Step Over'), 'and dim');
        $this->assertStringNotContainsString("\e[9m", $row('Save'));
        $this->assertStringContainsString('Type to search…', AnsiUtils::stripAnsiCodes(\implode("\n", $rows)), 'the empty query says what typing does');
    }

    /**
     * The label keeps at least half a narrow row; a long directory gives way
     * first, cut in the middle so both of its ends still say where it is.
     */
    public function testALongDetailIsCutInTheMiddleAndTheLabelKeepsHalf(): void
    {
        $dir = 'src/Some/Deeply/Nested/Namespace/Of/Things';
        $rows = $this->searchLayout([SearchRow::header('Files'), SearchRow::hit(new SearchHit(SearchCategory::Files, 'ExampleController.php', $dir, static fn (): null => null))], columns: 60)['rows'];
        $plain = \array_first(\array_filter(\array_map(AnsiUtils::stripAnsiCodes(...), $rows), static fn (string $r): bool => \str_contains($r, 'Example')));

        $this->assertStringContainsString('ExampleController.php', $plain, 'the label whole');
        $this->assertStringContainsString('src/', $plain, 'the head of the path');
        $this->assertStringContainsString('Things', $plain, 'and its tail');
        $this->assertStringContainsString('…', $plain);
    }

    /**
     * The shapes a picker's rows carry without colour: the selected row's ▌
     * in its first column, a category as a `─ Files ─` legend, the query's
     * matched characters marked, and the grow row led by an ellipsis.
     */
    public function testSearchRowsCarryTheirStateAsShapes(): void
    {
        $noop = static fn (): null => null;
        $state = new SearchModalState('Search', new TextField('re'), $noop, $noop, $noop);
        $state->rows = [
            SearchRow::header('Classes'),
            SearchRow::hit(new SearchHit(SearchCategory::Classes, 'Greeter', 'src/Greeter.php:4', $noop)),
            SearchRow::hit(new SearchHit(SearchCategory::Classes, 'Grey', '', $noop)),
            SearchRow::hit(new SearchHit(SearchCategory::Classes, 'Restart', '', $noop, enabled: false)),
            SearchRow::hit(new SearchHit(SearchCategory::Classes, '3 more results', '', $noop, keepsOpen: true)),
        ];
        $state->selected = 1;
        $state->widest = 60;
        $rows = PickerLayout::layoutSearch([0, 0, 24, 100], $state, $this->styles(), closeHovered: false, caret: CaretVisibility::Hidden)['rows'];
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $rows);
        $line = static fn (string $needle): int => \array_key_first(\array_filter($plain, static fn (string $r): bool => \str_contains($r, $needle)));

        $this->assertMatchesRegularExpression('/^├─ Classes ─+┤$/u', $plain[$line('Classes')], 'the category is a legend set into the tee that opens it');
        $this->assertSame($line('Classes') + 1, $line('Greeter'), 'the list opening on it, it names the query\'s own tee');
        $this->assertStringStartsWith('│ ▌Greeter', $plain[$line('Greeter')], 'the selected row, barred');
        $this->assertStringStartsWith('│  Grey', $plain[$line('Grey')], 'the others, not');
        $this->assertStringContainsString("\e[1m\e[4mr\e[24m\e[22m\e[1m\e[4me", $rows[$line('Grey')], 'the matched characters marked');
        $this->assertStringContainsString('… 3 more results', $plain[$line('more results')]);
        $this->assertStringNotContainsString("\e[4m", $rows[$line('more results')], 'the grow row is an action, never a match');
        $this->assertStringNotContainsString("\e[1m\e[4m", $rows[$line('Restart')], 'a greyed command is not offered as a match');
    }

    /** A clipped list says so: ▼ in the frame's bottom padding, and where the selection is in the bottom border. */
    public function testAClippedListShowsItsCuesAndPosition(): void
    {
        $items = \array_map(static fn (int $i): array => ['label' => "item {$i}", 'hint' => '', 'value' => (string) $i], \range(0, end: 29));
        $out = PickerLayout::layoutList(
            [0, 0, 24, 100],
            ['title' => 'Many', 'items' => $items, 'selected' => 0, 'hovered' => null, 'filter' => new TextField(), 'onPick' => static fn (): null => null],
            $this->styles(),
            closeHovered: false,
        );
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $out['rows']);

        $this->assertStringNotContainsString('▲', $plain[1], 'nothing above the first row');
        $this->assertStringContainsString('▼', $plain[\count($plain) - 2], 'more below');
        $this->assertMatchesRegularExpression('/╰─+ 1\/30 ─╯/u', \array_last($plain));

        $out = PickerLayout::layoutList(
            [0, 0, 24, 100],
            ['title' => 'Many', 'items' => $items, 'selected' => 29, 'hovered' => null, 'filter' => new TextField(), 'onPick' => static fn (): null => null],
            $this->styles(),
            closeHovered: false,
        );
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $out['rows']);
        $this->assertStringContainsString('▲', $plain[1]);
        $this->assertStringNotContainsString('▼', $plain[\count($plain) - 2]);
        $this->assertMatchesRegularExpression('/╰─+ 30\/30 ─╯/u', \array_last($plain));

        $few = PickerLayout::layoutList(
            [0, 0, 24, 100],
            ['title' => 'Few', 'items' => \array_slice($items, offset: 0, length: 3), 'selected' => 0, 'hovered' => null, 'filter' => new TextField(), 'onPick' => static fn (): null => null],
            $this->styles(),
            closeHovered: false,
        );
        $this->assertMatchesRegularExpression('/╰─+╯/u', AnsiUtils::stripAnsiCodes(\array_last($few['rows'])), 'a list that fits has no count');

        $narrow = PickerLayout::layoutList(
            [0, 0, 24, 9],
            ['title' => 'M', 'items' => $items, 'selected' => 12, 'hovered' => null, 'filter' => new TextField(), 'onPick' => static fn (): null => null],
            $this->styles(),
            closeHovered: false,
        );
        $this->assertSame(
            [$narrow['width']],
            \array_values(\array_unique(\array_map(AnsiUtils::visibleWidth(...), $narrow['rows']))),
            'a count that does not fit is left out, not drawn past the corner',
        );
    }

    /** A disabled list item is dim and struck through; its neighbours are not. */
    public function testADisabledListItemIsStruckThrough(): void
    {
        $out = PickerLayout::layoutList(
            [0, 0, 24, 100],
            [
                'title' => 'Fixes',
                'items' => [
                    ['label' => 'Blocked', 'hint' => 'why', 'value' => '0', 'disabled' => true],
                    ['label' => 'Fine', 'hint' => '', 'value' => '1'],
                ],
                'selected' => 1,
                'hovered' => null,
                'filter' => new TextField(),
                'onPick' => static fn (): null => null,
            ],
            $this->styles(),
            closeHovered: false,
        );
        $row = static fn (string $label): string => \array_first(\array_filter($out['rows'], static fn (string $r): bool => \str_contains(AnsiUtils::stripAnsiCodes($r), $label)));

        $this->assertMatchesRegularExpression('/\e\[9m(\e\[[0-9;]*m)*Blocked/', $row('Blocked'));
        $this->assertStringNotContainsString("\e[9m", $row('Fine'));
    }

    /**
     * Mirrored, the gap between a row's label and its hint is as wide as it
     * is unmirrored. The right-to-left mark that ends the label's run takes no
     * column, so the gap's space is written beside it: a mark standing in for
     * that space narrows every mirrored gap by one.
     */
    public function testAMirroredRowKeepsItsGapBetweenLabelAndHint(): void
    {
        $gaps = [];
        foreach ([BaseDirection::Ltr, BaseDirection::Rtl] as $direction) {
            $out = PickerLayout::layoutList(
                [0, 0, 24, 100],
                [
                    'title' => 'Fixes',
                    'items' => [['label' => 'Fine', 'hint' => 'why', 'value' => '0']],
                    'selected' => 0,
                    'hovered' => null,
                    'filter' => new TextField(),
                    'onPick' => static fn (): null => null,
                ],
                $this->styles(),
                closeHovered: false,
                direction: $direction,
            );
            $row = \array_first(\array_filter($out['rows'], static fn (string $r): bool => \str_contains(AnsiUtils::stripAnsiCodes($r), 'Fine')));
            $this->assertNotNull($row);
            $shown = AnsiUtils::stripAnsiCodes(BaseDirection::Rtl === $direction ? Bidi::reorder($row, BaseDirection::Rtl) : $row);
            $gaps[$direction->name] = 1 === \preg_match('/Fine( +)why|why( +)Fine/', $shown, $match) ? \strlen($match[1].($match[2] ?? '')) : -1;
        }

        $this->assertGreaterThan(0, $gaps['Ltr']);
        $this->assertSame($gaps['Ltr'], $gaps['Rtl']);
    }

    /** A note is dim like a disabled item, but not struck: a problem struck through would read as fixed. */
    public function testANoteIsDimButNotStruckThrough(): void
    {
        $out = PickerLayout::layoutList(
            [0, 0, 24, 100],
            [
                'title' => 'Quick Fixes',
                'items' => [
                    ['label' => '✗ boom', 'hint' => 'phpstan', 'value' => '', 'note' => true],
                    ['label' => 'Fix it', 'hint' => '', 'value' => '0'],
                ],
                'selected' => 1,
                'hovered' => null,
                'filter' => new TextField(),
                'onPick' => static fn (): null => null,
            ],
            $this->styles(),
            closeHovered: false,
        );
        $row = static fn (string $label): string => \array_first(\array_filter($out['rows'], static fn (string $r): bool => \str_contains(AnsiUtils::stripAnsiCodes($r), $label)));

        $this->assertMatchesRegularExpression('/\e\[2m(\e\[[0-9;]*m)*✗ boom/', $row('boom'), 'dim');
        $this->assertStringNotContainsString("\e[9m", $row('boom'), 'not struck');
        $this->assertStringNotContainsString("\e[2m", $row('Fix it'), 'its neighbour is neither');
    }
}
