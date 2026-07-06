<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\TerminalBuffer;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class TerminalPanelWidgetTest extends TestCase
{
    public function testClosedRendersNothingAndIgnoresInput(): void
    {
        $panel = new TerminalPanelWidget();
        $this->assertSame([], $panel->render(new RenderContext(80, 12)));

        // Input while closed is a no-op.
        $forwarded = '';
        $panel->onInputForward(function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });
        $panel->handleInput('x');
        $this->assertSame('', $forwarded);

        $panel->close(); // closing a closed panel is a no-op
        $this->assertFalse($panel->isOpen());
    }

    public function testOpenRendersBufferTailWithTitle(): void
    {
        $buffer = new TerminalBuffer();
        $buffer->feed("line-a\nline-b\nline-c\n");
        $panel = new TerminalPanelWidget();
        $panel->setFocused(true);
        $panel->open('bash', $buffer);

        $lines = $panel->render(new RenderContext(40, 6));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('bash', $plain);
        $this->assertStringContainsString('line-c', $plain);
        foreach ($lines as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testTitleAndGetTitle(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('one', new TerminalBuffer());
        $this->assertSame('one', $panel->getTitle());

        $rev = $panel->getRenderRevision();
        $panel->setTitle('one'); // no change -> no invalidation
        $this->assertSame($rev, $panel->getRenderRevision());

        $panel->setTitle('two');
        $this->assertSame('two', $panel->getTitle());
        $this->assertGreaterThan($rev, $panel->getRenderRevision());
    }

    public function testScrollClampsToAvailableHistory(): void
    {
        $buffer = new TerminalBuffer();
        for ($i = 0; $i < 30; ++$i) {
            $buffer->feed("row {$i}\n");
        }
        $panel = new TerminalPanelWidget();
        $panel->open('sh', $buffer);

        $panel->scroll(1000); // far up → clamped to lineCount - VISIBLE_ROWS (31 - 12)
        $plain = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 6))[0]);
        $this->assertStringContainsString('scrolled +19', $plain);

        $panel->scroll(-1000); // far down → clamped to 0
        $plain = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 6))[0]);
        $this->assertStringNotContainsString('scrolled', $plain);
    }

    public function testSetVisibleRowsChangesHeightAndScrollClamp(): void
    {
        $buffer = new TerminalBuffer();
        for ($i = 0; $i < 40; ++$i) {
            $buffer->feed("row {$i}\n");
        }
        $panel = new TerminalPanelWidget();
        $panel->open('sh', $buffer);

        $this->assertCount(12, $panel->render(new RenderContext(50, 30)), 'default 12 rows');
        $panel->setVisibleRows(20);
        $this->assertSame(20, $panel->visibleRows());
        $this->assertCount(20, $panel->render(new RenderContext(50, 30)), 'resized to 20 rows');

        // The scroll clamp uses the new height: lineCount 41 - 20 = 21.
        $panel->scroll(1000);
        $plain = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 30))[0]);
        $this->assertStringContainsString('scrolled +21', $plain);

        $panel->setVisibleRows(1); // clamped to the floor of 2
        $this->assertSame(2, $panel->visibleRows());
    }

    public function testForwardsInputAndHandlesPanelKeys(): void
    {
        $buffer = new TerminalBuffer();
        for ($i = 0; $i < 30; ++$i) {
            $buffer->feed("row {$i}\n");
        }
        $panel = new TerminalPanelWidget();
        $panel->setFocused(true);
        $panel->open('sh', $buffer);

        $forwarded = '';
        $panel->onInputForward(function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });

        // Printable input is forwarded to the process.
        $panel->handleInput('ls');
        $this->assertSame('ls', $forwarded);

        // Page-up scrolls back; the header shows the offset.
        $panel->handleInput("\x1b[5~");
        $plain = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 6))[0]);
        $this->assertStringContainsString('scrolled', $plain);

        // Page-down scrolls forward again (clamped at 0).
        $panel->handleInput("\x1b[6~");
        $panel->handleInput("\x1b[6~");
        $plain = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 6))[0]);
        $this->assertStringNotContainsString('scrolled', $plain);
    }

    public function testHideKeyClosesAndNotifies(): void
    {
        $panel = new TerminalPanelWidget();
        $closed = false;
        $panel->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $panel->open('sh', new TerminalBuffer());

        $panel->handleInput("\x1b"); // escape hides
        $this->assertTrue($closed);
        $this->assertFalse($panel->isOpen());
    }

    public function testOnInputHookConsumes(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('sh', new TerminalBuffer());
        $panel->onInput(static fn (string $d): bool => 'Z' === $d);

        $forwarded = '';
        $panel->onInputForward(function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });
        $panel->handleInput('Z'); // consumed by hook, not forwarded
        $this->assertSame('', $forwarded);
    }
}
