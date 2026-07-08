<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ColorPalette;
use Porta\Ui\MonochromePalette;
use Porta\Ui\Role;
use Porta\Ui\SwitchablePalette;

final class SwitchablePaletteTest extends TestCase
{
    public function testDelegatesEveryCallToTheCurrentPalette(): void
    {
        $palette = new SwitchablePalette(ColorPalette::dark());

        $this->assertSame(ColorPalette::dark()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
        $this->assertSame("\x1b[39m", $palette->fgReset());
        $this->assertSame("\x1b[0m", $palette->reset());
        $this->assertTrue($palette->hasColor());
    }

    public function testSwitchToRecolorsWithoutReplacingTheInstance(): void
    {
        $palette = new SwitchablePalette(ColorPalette::dark());

        $palette->switchTo(ColorPalette::light());
        $this->assertSame(ColorPalette::light()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));
        $this->assertNotSame(ColorPalette::dark()->of(Role::HeaderBar), $palette->of(Role::HeaderBar));

        $palette->switchTo(new MonochromePalette());
        $this->assertSame('', $palette->of(Role::HeaderBar));
        $this->assertFalse($palette->hasColor());
    }
}
