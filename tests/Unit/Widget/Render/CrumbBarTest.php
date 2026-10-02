<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\CrumbBar;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CrumbBarTest extends TestCase
{
    public function testATrailIsPillsJoinedByBareSeparatorsWithItsSuffixAtTheEnd(): void
    {
        $bar = CrumbBar::render([['label' => 'a'], ['label' => 'bc']], 20, Styles::dark(), null, 'XY');

        $this->assertSame(' a › bc '.\str_repeat(' ', times: 10).'XY', AnsiUtils::stripAnsiCodes($bar['line']));
        $this->assertSame([0 => ['start' => 0, 'width' => 3], 1 => ['start' => 4, 'width' => 4]], $bar['zones']);
        $this->assertSame([], $bar['tips']);
    }

    public function testNoCrumbsLeaveTheStripBareButForTheSuffix(): void
    {
        $bar = CrumbBar::render([], 6, Styles::dark(), null, 'XY');

        $this->assertSame('    XY', AnsiUtils::stripAnsiCodes($bar['line']));
        $this->assertSame([], $bar['zones']);
    }

    /** A suffix wider than the row would make the row wider than its pane, which throws out of the render loop. */
    public function testASuffixWiderThanTheRowIsCutToIt(): void
    {
        $bar = CrumbBar::render([['label' => 'page']], 5, Styles::dark(), null, 'a long hint');

        $this->assertSame(5, AnsiUtils::visibleWidth($bar['line']));
    }

    public function testAnEmphasisedCrumbKeepsItsBrightForegroundOnlyAtRest(): void
    {
        $crumbs = [['label' => 'file', 'emphasis' => true], ['label' => 'scope']];

        $rest = CrumbBar::render($crumbs, 30, Styles::dark(), null)['line'];
        $hovered = CrumbBar::render($crumbs, 30, Styles::dark(), 2)['line'];

        $this->assertStringContainsString("\x1b[38;5;255m\x1b[48;5;235m file ", $rest, 'Emphasis on the strip');
        $this->assertStringContainsString("\x1b[38;5;246m\x1b[48;5;240m \x1b[4mfile", $hovered, 'hovered, it is shaded like any crumb');
    }

    public function testTheNaturalWidthIsEveryPillAndTheSeparatorsBetween(): void
    {
        $this->assertSame(0, CrumbBar::naturalWidth([]));
        $this->assertSame(3, CrumbBar::naturalWidth([['label' => 'a']]));
        $this->assertSame(8, CrumbBar::naturalWidth([['label' => 'a'], ['label' => 'bc']]), ' a › bc ');
    }

    public function testAtFindsThePillUnderAColumn(): void
    {
        $zones = [2 => ['start' => 4, 'width' => 3]];

        $this->assertNull(CrumbBar::at($zones, 3));
        $this->assertSame(2, CrumbBar::at($zones, 4));
        $this->assertSame(2, CrumbBar::at($zones, 6));
        $this->assertNull(CrumbBar::at($zones, 7));
    }
}
