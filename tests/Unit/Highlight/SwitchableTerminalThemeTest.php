<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\SwitchableTerminalTheme;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final class SwitchableTerminalThemeTest extends TestCase
{
    public function testDelegatesToTheCurrentTheme(): void
    {
        $theme = new SwitchableTerminalTheme(new DarkTerminalTheme());

        $this->assertSame(new DarkTerminalTheme()->before(TokenTypeEnum::KEYWORD), $theme->before(TokenTypeEnum::KEYWORD));
        $this->assertSame("\x1b[39m", $theme->after(TokenTypeEnum::KEYWORD));
        $this->assertSame('plain', $theme->escape('plain'));
    }

    public function testSwitchToChangesTokenColors(): void
    {
        $theme = new SwitchableTerminalTheme(new DarkTerminalTheme());

        $theme->switchTo(new LightTerminalTheme());
        $this->assertSame(new LightTerminalTheme()->before(TokenTypeEnum::KEYWORD), $theme->before(TokenTypeEnum::KEYWORD));
        $this->assertNotSame(new DarkTerminalTheme()->before(TokenTypeEnum::KEYWORD), $theme->before(TokenTypeEnum::KEYWORD));
    }
}
