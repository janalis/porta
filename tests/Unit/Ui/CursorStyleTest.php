<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Ui\CursorStyle;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class CursorStyleTest extends TestCase
{
    public function testDefaultsToTheBarAndSwitchesLive(): void
    {
        $style = new CursorStyle();
        $this->assertSame(CursorShape::Bar, Probe::get($style, 'shape'));
        $this->assertSame(AnsiUtils::cursorMarker(CursorShape::Bar), $style->marker());

        $style->switchTo(CursorShape::Block);
        $this->assertSame(CursorShape::Block, Probe::get($style, 'shape'));
        $this->assertSame(AnsiUtils::cursorMarker(CursorShape::Block), $style->marker(), 'the marker follows the switch');
    }

    public function testShapeFromSettingMapsEveryOptionAndFallsBackToBar(): void
    {
        $this->assertSame(CursorShape::Bar, CursorStyle::shapeFromSetting('bar'));
        $this->assertSame(CursorShape::Block, CursorStyle::shapeFromSetting('block'));
        $this->assertSame(CursorShape::Underline, CursorStyle::shapeFromSetting('underline'));
        $this->assertSame(CursorShape::Bar, CursorStyle::shapeFromSetting('wedge'), 'unknown → bar');
    }
}
