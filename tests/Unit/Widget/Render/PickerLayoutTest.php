<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Overlay\State\SearchModalState;
use Porta\Ui\CaretVisibility;
use Porta\Ui\TextField;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
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
        foreach (str_split('zzz') as $character) {
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
            array_map(
                static fn (string $row): string => trim(AnsiUtils::stripAnsiCodes($row), characters: ' │'),
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
        foreach (mb_str_split($typed) as $character) {
            $filter->insert($character);
        }

        $row = $this->filterRow($filter);

        // Whatever the box has room for must come from the END of the typed
        // text — the box is narrow, so the assertion asks for the last few
        // characters rather than a fixed count that would pin the width.
        $this->assertStringContainsString(mb_substr($typed, start: -6), $row, 'the end of the filter, where the caret is');
        $this->assertStringNotContainsString('feature/', $row, 'not the start, which is what an end-truncate keeps');
        $this->assertStringContainsString('≡', $row, 'and the glyph identifying the row does not scroll away');
    }

    /** A filter that fits is drawn exactly as before — the scroll only engages past the width. */
    public function testAFilterThatFitsIsUnchanged(): void
    {
        $filter = new TextField();
        foreach (mb_str_split('dev') as $character) {
            $filter->insert($character);
        }

        $this->assertStringContainsString('≡ dev', $this->filterRow($filter));
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

    /** The list overlay's filter row, plain. */
    private function filterRow(TextField $filter): string
    {
        $out = $this->layout($filter);

        foreach ($out['rows'] as $row) {
            $plain = AnsiUtils::stripAnsiCodes($row);
            if (str_contains($plain, '≡')) {
                return $plain;
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
            foreach (mb_str_split($typed) as $character) {
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
            array_map(
                static fn (string $row): string => trim(AnsiUtils::stripAnsiCodes($row), characters: ' │'),
                $out['rows'],
            ),
            'the filler row is drawn whole',
        );
    }
}
