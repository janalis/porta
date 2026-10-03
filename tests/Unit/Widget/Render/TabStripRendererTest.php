<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Density;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\StripEdge;
use Porta\Widget\Render\TabStripRenderer;
use Porta\Widget\Render\TabStripTail;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TabStripRendererTest extends TestCase
{
    /** @param list<array{label: string, active: bool, family?: string}> $tabs */
    private static function strip(array $tabs, int $columns = 60, ?int $hoverCol = null): array
    {
        return TabStripRenderer::render($tabs, $columns, Styles::dark(), $hoverCol);
    }

    public function testRendersTheUnifiedFamilyWithHitZones(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true],
            ['label' => 'two', 'active' => false],
            ['label' => 'three', 'active' => false],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($strip['lines'][0]);
        $this->assertSame(' one × │ two × │ three × │', $plain);
        $this->assertStringContainsString("\x1b[48;5;238m", $strip['lines'][0], 'active tab on TabActiveBg');
        $this->assertStringContainsString("\x1b[1m", $strip['lines'][0], 'active tab bold');
        $this->assertStringContainsString("\x1b[48;5;235m", $strip['lines'][0], 'plain inactive tab on Toolbar');

        $this->assertSame([0, 1, 2], \array_column($strip['hitMap'], 'index'));
        $first = $strip['hitMap'][0];
        $this->assertSame(0, $first['start']);
        $this->assertSame(5, $first['closeStart'], 'the × sits after " one "');
        $this->assertSame(2, $first['closeWidth']);
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['lines'][0]));
    }

    public function testAColumnUnderAnInactiveTabTintsThatTabAndNoOther(): void
    {
        $tabs = [
            ['label' => 'one', 'active' => true],
            ['label' => 'two', 'active' => false],
            ['label' => 'three', 'active' => false],
        ];
        // The 'three' body starts at its hit zone; a column inside it tints it.
        $bare = self::strip($tabs);
        $third = $bare['hitMap'][2];
        $strip = self::strip($tabs, hoverCol: $third['start'] + 1);

        $this->assertStringContainsString("\x1b[48;5;240m", $strip['lines'][0], 'the tab under the pointer is on the bar-chrome hover shade');
    }

    public function testAColumnOnTheActiveTabLeavesItActiveNotHovered(): void
    {
        $tabs = [['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]];
        $strip = self::strip($tabs, hoverCol: 1); // inside the active 'one'

        $this->assertStringContainsString("\x1b[48;5;238m", $strip['lines'][0], 'active still wins under the pointer');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $strip['lines'][0], 'no hover shade on the active tab');
    }

    public function testOverflowDropsLeadingTabsToKeepTheActiveOneVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => \sprintf('tab-%02d', $i), 'active' => 6 === $i];
        }
        $strip = self::strip($tabs, columns: 30);

        $plain = AnsiUtils::stripAnsiCodes($strip['lines'][0]);
        $this->assertStringContainsString('tab-06', $plain, 'the active tab is always on the strip');
        $this->assertStringNotContainsString('tab-00', $plain, 'leading tabs drop first');
        $this->assertSame(6, $strip['hitMap'][0]['index'] + \count($strip['hitMap']) - 1, 'the hit map covers through the active tab');
    }

    public function testOverflowRendersClickableScrollIndicatorsAndFillsTheWidth(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => \sprintf('tab-%02d', $i), 'active' => 6 === $i];
        }
        $strip = self::strip($tabs, columns: 30);

        $plain = AnsiUtils::stripAnsiCodes($strip['lines'][0]);
        $this->assertStringContainsString(Glyphs::SCROLL_PREV, $plain, 'a ‹ marks the dropped leading tabs');
        $this->assertStringContainsString(Glyphs::SCROLL_NEXT, $plain, 'a › marks the hidden trailing tabs');
        $this->assertSame(30, AnsiUtils::visibleWidth($strip['lines'][0]), 'the overflowing strip fills the pane');

        $steps = \array_column($strip['indicatorZones'], 'step');
        $this->assertContains(-1, $steps, 'the ‹ pages left');
        $this->assertContains(1, $steps, 'the › pages right');
        // the ‹ sits at col 0, the › at the last column
        $cols = \array_column($strip['indicatorZones'], 'col');
        $this->assertContains(0, $cols);
        $this->assertContains(29, $cols);
    }

    public function testANonOverflowingStripHasNoIndicators(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true],
            ['label' => 'two', 'active' => false],
        ], columns: 60);

        $this->assertSame([], $strip['indicatorZones'], 'a strip that fits shows no ‹/›');
    }

    public function testAFamilyBoundaryIsDrawnHeavierThanASiblingDivider(): void
    {
        // A drag reorders within a family and refuses to cross, so the strip
        // must not claim every gap is the same kind of gap.
        $strip = self::strip([
            ['label' => 'sh', 'active' => false, 'family' => 'Terminal'],
            ['label' => 'node', 'active' => true, 'family' => 'Terminal'],
            ['label' => 'Problems', 'active' => false, 'family' => 'Results'],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($strip['lines'][0]);
        $this->assertSame(' sh × │ node × ┃ Problems × │', $plain, 'heavy only where the family changes');
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['lines'][0]), 'the heavier glyph is still one column');
    }

    public function testAStripWithNoFamiliesIsAllSiblingDividers(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true],
            ['label' => 'two', 'active' => false],
        ]);

        $this->assertStringNotContainsString(Glyphs::FAMILY_SEPARATOR, AnsiUtils::stripAnsiCodes($strip['lines'][0]));
    }

    /**
     * Folder tabs: each tab its own outline, the lid above the labels, and
     * the shape is the state — the rest rounded, the shown tab heavy across
     * the lid, heavy all round once its pane has the keys. They stand on a
     * baseline that opens under the shown tab alone, and runs on past the
     * last one. Read with every SGR stripped, so it holds under the
     * monochrome theme and --no-ansi.
     */
    /**
     * Roomy, a tab is a pill: its label row shaded as a tree row is, standing
     * on a baseline that holds its lower half, the line running on beside it;
     * its upper half is the frame's, in the rule over the strip. The shown tab
     * wears the list selection — grey, blue where its pane has the keys — the
     * hovered one the rows' hover tint, the rest nothing.
     */
    public function testARoomyStripDrawsEachTabAsAPill(): void
    {
        $tabs = [['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]];

        $unfocused = TabStripRenderer::render($tabs, 40, Styles::dark(), rows: 2);
        $focused = TabStripRenderer::render($tabs, 40, Styles::dark(), rows: 2, paneFocused: true);
        $hovered = TabStripRenderer::render($tabs, 40, Styles::dark(), hoverCol: 10, rows: 2);

        $this->assertSame([' one ×  two × ', '▀▀▀▀▀▀▀───────'], \array_map(AnsiUtils::stripAnsiCodes(...), $unfocused['lines']));
        $this->assertStringContainsString("\x1b[38;5;237m▀▀▀▀▀▀▀", $unfocused['lines'][1], 'the shown tab: the grey of a list cursor without the keys');
        $this->assertStringContainsString("\x1b[48;5;237m", $unfocused['lines'][0]);
        $this->assertStringContainsString("\x1b[38;5;31m▀▀▀▀▀▀▀", $focused['lines'][1], 'and the blue of one with them');
        $this->assertStringContainsString("\x1b[48;5;31m", $focused['lines'][0]);
        $this->assertStringNotContainsString('48;5;235', $focused['lines'][0], 'a resting tab sits on nothing, not the toolbar');
        $this->assertSame('▀▀▀▀▀▀▀▀▀▀▀▀▀▀', AnsiUtils::stripAnsiCodes($hovered['lines'][1]), 'the hovered tab is a pill too, beside the shown one with no gap: one run of shade');
        $this->assertStringContainsString("\x1b[38;5;236m▀▀▀▀▀▀▀", $hovered['lines'][1], 'in the rows\' hover tint');
        $this->assertSame(0, $unfocused['labelRow']);
        $this->assertSame(1, $unfocused['footRow']);

        $edged = TabStripRenderer::render($tabs, 40, Styles::dark(), rows: 3);
        $this->assertSame(
            ['▄▄▄▄▄▄▄───────', ' one ×  two × ', '▀▀▀▀▀▀▀───────'],
            \array_map(AnsiUtils::stripAnsiCodes(...), $edged['lines']),
            'a strip that is its own top edge holds its upper halves in that rule',
        );
        $this->assertSame([1, 2], [$edged['labelRow'], $edged['footRow']]);

        $compact = TabStripRenderer::render($tabs, 40, Styles::dark(), edge: StripEdge::Bottom);
        $this->assertNull($compact['footRow'], 'a compact strip has no baseline to run on');
        $this->assertSame(' one × │ two × │     ', AnsiUtils::stripAnsiCodes(TabStripTail::pad($compact, 21, Styles::dark())[0]), 'so its tail is blank, at either edge');
        $this->assertStringContainsString("\x1b[48;5;235m", TabStripTail::pad($compact, 21, Styles::dark())[0], 'over the toolbar shade it has always had');
        $padded = TabStripTail::pad($unfocused, 22, Styles::dark());
        $this->assertSame('▀▀▀▀▀▀▀───────────────', AnsiUtils::stripAnsiCodes($padded[1]), 'the baseline runs on past the last tab');
        $this->assertSame(' one ×  two ×         ', AnsiUtils::stripAnsiCodes($padded[0]), 'the label row pads with blanks');
        $this->assertStringNotContainsString('48;5;235', $padded[0], 'on nothing');
    }

    /** At a bottom edge the rows run the other way: the baseline over the labels, holding their upper halves. */
    public function testABottomRoomyStripIsMirrored(): void
    {
        $strip = TabStripRenderer::render([['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]], 40, Styles::dark(), rows: 2, edge: StripEdge::Bottom, paneFocused: true);

        $this->assertSame(['▄▄▄▄▄▄▄───────', ' one ×  two × '], \array_map(AnsiUtils::stripAnsiCodes(...), $strip['lines']));
        $this->assertSame(1, $strip['labelRow']);
        $this->assertSame(0, $strip['footRow']);
        $edged = TabStripRenderer::render([['label' => 'one', 'active' => true]], 40, Styles::dark(), rows: 3, edge: StripEdge::Bottom);
        $this->assertSame(2, $edged['footRow'], 'a strip that is its own top edge keeps its baseline under the labels, at either edge');
    }

    /** The zones count the rails: a folder tab is one column wider, and its × one column further in. */
    /** Pills stand side by side: a tab's zone is its text, with no margin or divider of its own. */
    public function testAPillsZoneIsItsText(): void
    {
        $strip = TabStripRenderer::render([['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]], 40, Styles::dark(), rows: 3);

        $this->assertSame([0, 7], \array_column($strip['hitMap'], 'start'));
        $this->assertSame([7, 7], \array_column($strip['hitMap'], 'width'));
        $this->assertSame(5, $strip['hitMap'][0]['closeStart'], '" one ", then the ×');
        $this->assertSame(14, $strip['used']);
    }

    /** On the lid above a ×, the pointer is on the tab, not the ×: the × lights only where a click on it closes. */
    public function testOnlyTheLabelRowLightsTheClose(): void
    {
        $tabs = [['label' => 'one', 'active' => false], ['label' => 'two', 'active' => true]];

        $onLabel = TabStripRenderer::render($tabs, 40, Styles::dark(), 6, rows: 3, hoverRow: 1);
        $onLid = TabStripRenderer::render($tabs, 40, Styles::dark(), 6, rows: 3, hoverRow: 0);

        $this->assertMatchesRegularExpression('/\x1b\[4m(?:\x1b\[[0-9;]*m)*×/', $onLabel['lines'][1]);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[4m(?:\x1b\[[0-9;]*m)*×/', $onLid['lines'][1]);
        $this->assertStringContainsString("\x1b[4mone", $onLid['lines'][1], 'the tab is hovered all the same');
    }

    /** An overflowing folder strip pages from any of its rows, and its baseline runs under the indicators. */
    public function testARoomyStripsIndicatorsSpanEveryRow(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => "tab{$i}", 'active' => 7 === $i];
        }

        $strip = TabStripRenderer::render($tabs, 30, Styles::dark(), rows: 3);

        $this->assertSame(
            [[0, 0], [1, 0], [2, 0]],
            \array_map(static fn (array $zone): array => [$zone['row'], $zone['col']], \array_filter($strip['indicatorZones'], static fn (array $zone): bool => -1 === $zone['step']))
                |> \array_values(...),
        );
        foreach ($strip['lines'] as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
        $foot = AnsiUtils::stripAnsiCodes($strip['lines'][2]);
        $this->assertSame(\mb_strpos($foot, needle: '▀'), \mb_strpos(AnsiUtils::stripAnsiCodes($strip['lines'][0]), needle: '▄'), 'past ‹ the two halves of the shown tab line up');
        $compact = TabStripRenderer::render($tabs, 30, Styles::dark());
        $this->assertSame([0], \array_values(\array_unique(\array_column($compact['indicatorZones'], 'row'))), 'a compact strip\'s ‹ and › are on its one row');
        $this->assertStringStartsWith('────', $foot, 'under ‹ the baseline runs on');
        $this->assertStringEndsWith('▀▀▀▀▀▀▀▀─────', $foot, 'and on to the last column, under ›');
    }

    public function testTheStripIsCompactBelowThirtyRows(): void
    {
        $this->assertSame(1, TabStripRenderer::rowsFor(Density::Compact));
        $this->assertSame(2, TabStripRenderer::rowsFor(Density::Roomy), 'the labels and the baseline: the upper halves are the frame\'s');
        $this->assertSame(1, TabStripRenderer::edgedRowsFor(Density::Compact));
        $this->assertSame(3, TabStripRenderer::edgedRowsFor(Density::Roomy), 'and a strip that is its own top edge draws that rule too');
    }
}
