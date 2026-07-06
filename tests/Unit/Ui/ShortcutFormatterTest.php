<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ShortcutFormatter;

final class ShortcutFormatterTest extends TestCase
{
    public function testEmptyBinding(): void
    {
        $this->assertSame('', ShortcutFormatter::format(''));
    }

    public function testModifiersAndLetter(): void
    {
        $this->assertSame('Ctrl+S', ShortcutFormatter::format('ctrl+s'));
        $this->assertSame('Ctrl+Shift+N', ShortcutFormatter::format('ctrl+shift+n'));
        $this->assertSame('Ctrl+Alt+Shift+N', ShortcutFormatter::format('ctrl+alt+shift+n'));
    }

    public function testFunctionKeys(): void
    {
        $this->assertSame('F12', ShortcutFormatter::format('f12'));
        $this->assertSame('Shift+F6', ShortcutFormatter::format('shift+f6'));
        $this->assertSame('F1', ShortcutFormatter::format('f1'));
    }

    public function testNamedKeysAndArrows(): void
    {
        $this->assertSame('Alt+Shift+↑', ShortcutFormatter::format('alt+shift+up'));
        $this->assertSame('Ctrl+Alt+←', ShortcutFormatter::format('ctrl+alt+left'));
        $this->assertSame('Esc', ShortcutFormatter::format('escape'));
        $this->assertSame('⏎', ShortcutFormatter::format('enter'));
        $this->assertSame('PgUp', ShortcutFormatter::format('page_up'));
    }

    public function testSymbolsKeptLiteral(): void
    {
        $this->assertSame('Ctrl+/', ShortcutFormatter::format('ctrl+/'));
        $this->assertSame('Ctrl+.', ShortcutFormatter::format('ctrl+.'));
        $this->assertSame('Ctrl+`', ShortcutFormatter::format('ctrl+`'));
    }

    public function testCmdAndPlusKey(): void
    {
        $this->assertSame('Cmd+S', ShortcutFormatter::format('cmd+s'));
        $this->assertSame('+', ShortcutFormatter::format('+'));
    }
}
