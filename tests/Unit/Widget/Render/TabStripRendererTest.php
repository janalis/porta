<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\StripEdge;
use Porta\Widget\Render\TabStripRenderer;
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
     * the lid, heavy all round once its pane has the keys. Read with every
     * SGR stripped, so it holds under the monochrome theme and --no-ansi.
     */
    public function testAFolderStripDrawsEachStateAsAShape(): void
    {
        $tabs = [['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]];

        $unfocused = TabStripRenderer::render($tabs, 40, Styles::dark(), rows: 2);
        $focused = TabStripRenderer::render($tabs, 40, Styles::dark(), rows: 2, paneFocused: true);

        $this->assertSame(['┍━━━━━━━┑╭───────╮', '│ one × ││ two × │'], \array_map(AnsiUtils::stripAnsiCodes(...), $unfocused['lines']));
        $this->assertSame(['┏━━━━━━━┓╭───────╮', '┃ one × ┃│ two × │'], \array_map(AnsiUtils::stripAnsiCodes(...), $focused['lines']));
        $this->assertSame(1, $unfocused['labelRow']);
    }

    /** A strip under its pane opens downward: the labels first, then the tabs' bottoms. */
    public function testABottomFolderStripIsMirrored(): void
    {
        $strip = TabStripRenderer::render([['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]], 40, Styles::dark(), rows: 2, edge: StripEdge::Bottom, paneFocused: true);

        $this->assertSame(['┃ one × ┃│ two × │', '┗━━━━━━━┛╰───────╯'], \array_map(AnsiUtils::stripAnsiCodes(...), $strip['lines']));
        $this->assertSame(0, $strip['labelRow']);
        $unfocused = TabStripRenderer::render([['label' => 'one', 'active' => true]], 40, Styles::dark(), rows: 2, edge: StripEdge::Bottom);
        $this->assertSame('┕━━━━━━━┙', AnsiUtils::stripAnsiCodes($unfocused['lines'][1]));
    }

    /** The zones count the rails: a folder tab is one column wider, and its × one column further in. */
    public function testAFolderTabsZonesIncludeItsRails(): void
    {
        $strip = TabStripRenderer::render([['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]], 40, Styles::dark(), rows: 2);

        $this->assertSame([0, 9], \array_column($strip['hitMap'], 'start'));
        $this->assertSame([9, 9], \array_column($strip['hitMap'], 'width'));
        $this->assertSame(6, $strip['hitMap'][0]['closeStart'], 'the rail, then " one "');
        $this->assertSame(18, $strip['used']);
    }

    /** On the lid above a ×, the pointer is on the tab, not the ×: the × lights only where a click on it closes. */
    public function testOnlyTheLabelRowLightsTheClose(): void
    {
        $tabs = [['label' => 'one', 'active' => false], ['label' => 'two', 'active' => true]];

        $onLabel = TabStripRenderer::render($tabs, 40, Styles::dark(), 6, rows: 2, hoverRow: 1);
        $onLid = TabStripRenderer::render($tabs, 40, Styles::dark(), 6, rows: 2, hoverRow: 0);

        $this->assertMatchesRegularExpression('/\x1b\[4m(?:\x1b\[[0-9;]*m)*×/', $onLabel['lines'][1]);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[4m(?:\x1b\[[0-9;]*m)*×/', $onLid['lines'][1]);
        $this->assertStringContainsString("\x1b[4mone", $onLid['lines'][1], 'the tab is hovered all the same');
    }

    /** An overflowing folder strip pages from either of its rows. */
    public function testAFolderStripsIndicatorsSpanBothRows(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => "tab{$i}", 'active' => 7 === $i];
        }

        $strip = TabStripRenderer::render($tabs, 30, Styles::dark(), rows: 2);

        $this->assertSame(
            [[0, 0], [1, 0]],
            \array_map(static fn (array $zone): array => [$zone['row'], $zone['col']], \array_filter($strip['indicatorZones'], static fn (array $zone): bool => -1 === $zone['step']))
                |> \array_values(...),
        );
        foreach ($strip['lines'] as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
    }

    /** Folder tabs from 30 terminal rows; a compact strip below, where every row is a line of code. */
    public function testTheStripIsCompactBelowThirtyRows(): void
    {
        $this->assertSame(1, TabStripRenderer::rowsFor(29));
        $this->assertSame(2, TabStripRenderer::rowsFor(30));
    }
}
