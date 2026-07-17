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
    /** @param list<array{label: string, active: bool, hovered: bool, family?: string}> $tabs */
    private static function strip(array $tabs, int $columns = 60): array
    {
        return TabStripRenderer::render($tabs, $columns, Styles::dark());
    }

    public function testRendersTheUnifiedFamilyWithHitZones(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true, 'hovered' => false],
            ['label' => 'two', 'active' => false, 'hovered' => false],
            ['label' => 'three', 'active' => false, 'hovered' => true],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertSame(' one × │ two × │ three × │', $plain);
        $this->assertStringContainsString("\x1b[48;5;238m", $strip['line'], 'active tab on TabActiveBg');
        $this->assertStringContainsString("\x1b[1m", $strip['line'], 'active tab bold');
        $this->assertStringContainsString("\x1b[48;5;240m", $strip['line'], 'hovered inactive tab on the bar-chrome hover shade');
        $this->assertStringContainsString("\x1b[48;5;235m", $strip['line'], 'plain inactive tab on Toolbar');

        $this->assertSame([0, 1, 2], array_column($strip['hitMap'], 'index'));
        $first = $strip['hitMap'][0];
        $this->assertSame(0, $first['start']);
        $this->assertSame(5, $first['closeStart'], 'the × sits after " one "');
        $this->assertSame(2, $first['closeWidth']);
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['line']));
    }

    public function testOverflowDropsLeadingTabsToKeepTheActiveOneVisible(): void
    {
        $tabs = [];
        for ($i = 0; $i < 8; ++$i) {
            $tabs[] = ['label' => \sprintf('tab-%02d', $i), 'active' => 6 === $i, 'hovered' => false];
        }
        $strip = self::strip($tabs, columns: 30);

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertStringContainsString('tab-06', $plain, 'the active tab is always on the strip');
        $this->assertStringNotContainsString('tab-00', $plain, 'leading tabs drop first');
        $this->assertSame(6, $strip['hitMap'][0]['index'] + \count($strip['hitMap']) - 1, 'the hit map covers through the active tab');
    }

    public function testAFamilyBoundaryIsDrawnHeavierThanASiblingDivider(): void
    {
        // A drag reorders within a family and refuses to cross, so the strip
        // must not claim every gap is the same kind of gap.
        $strip = self::strip([
            ['label' => 'sh', 'active' => false, 'hovered' => false, 'family' => 'Terminal'],
            ['label' => 'node', 'active' => true, 'hovered' => false, 'family' => 'Terminal'],
            ['label' => 'Problems', 'active' => false, 'hovered' => false, 'family' => 'Results'],
        ]);

        $plain = AnsiUtils::stripAnsiCodes($strip['line']);
        $this->assertSame(' sh × │ node × ┃ Problems × │', $plain, 'heavy only where the family changes');
        $this->assertSame($strip['used'], AnsiUtils::visibleWidth($strip['line']), 'the heavier glyph is still one column');
    }

    public function testAStripWithNoFamiliesIsAllSiblingDividers(): void
    {
        $strip = self::strip([
            ['label' => 'one', 'active' => true, 'hovered' => false],
            ['label' => 'two', 'active' => false, 'hovered' => false],
        ]);

        $this->assertStringNotContainsString(Glyphs::FAMILY_SEPARATOR, AnsiUtils::stripAnsiCodes($strip['line']));
    }
}
