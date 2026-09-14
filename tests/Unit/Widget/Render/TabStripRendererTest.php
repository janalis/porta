<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
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

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertSame(' one × │ two × │ three × │', $plain);
        $this->assertStringContainsString("\x1b[48;5;238m", $strip['line'], 'active tab on TabActiveBg');
        $this->assertStringContainsString("\x1b[1m", $strip['line'], 'active tab bold');
        $this->assertStringContainsString("\x1b[48;5;235m", $strip['line'], 'plain inactive tab on Toolbar');

        $this->assertSame([0, 1, 2], \array_column($strip['hitMap'], 'index'));
        $first = $strip['hitMap'][0];
        $this->assertSame(0, $first['start']);
        $this->assertSame(5, $first['closeStart'], 'the × sits after " one "');
        $this->assertSame(2, $first['closeWidth']);
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['line']));
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

        $this->assertStringContainsString("\x1b[48;5;240m", $strip['line'], 'the tab under the pointer is on the bar-chrome hover shade');
    }

    public function testAColumnOnTheActiveTabLeavesItActiveNotHovered(): void
    {
        $tabs = [['label' => 'one', 'active' => true], ['label' => 'two', 'active' => false]];
        $strip = self::strip($tabs, hoverCol: 1); // inside the active 'one'

        $this->assertStringContainsString("\x1b[48;5;238m", $strip['line'], 'active still wins under the pointer');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $strip['line'], 'no hover shade on the active tab');
    }

    public function testOverflowDropsLeadingTabsToKeepTheActiveOneVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => \sprintf('tab-%02d', $i), 'active' => 6 === $i];
        }
        $strip = self::strip($tabs, columns: 30);

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
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

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertStringContainsString(Glyphs::SCROLL_PREV, $plain, 'a ‹ marks the dropped leading tabs');
        $this->assertStringContainsString(Glyphs::SCROLL_NEXT, $plain, 'a › marks the hidden trailing tabs');
        $this->assertSame(30, AnsiUtils::visibleWidth($strip['line']), 'the overflowing strip fills the pane');

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

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertSame(' sh × │ node × ┃ Problems × │', $plain, 'heavy only where the family changes');
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['line']), 'the heavier glyph is still one column');
    }

    public function testAStripWithNoFamiliesIsAllSiblingDividers(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true],
            ['label' => 'two', 'active' => false],
        ]);

        $this->assertStringNotContainsString(Glyphs::FAMILY_SEPARATOR, AnsiUtils::stripAnsiCodes($strip['line']));
    }
}
