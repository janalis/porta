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
        $panel->onInputForward(static function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });
        $panel->handleInput('x');
        $this->assertSame('', $forwarded);

        $panel->close(); // closing a closed panel is a no-op
        $this->assertFalse($panel->isOpen());
    }

    public function testOpenRendersBufferTail(): void
    {
        $buffer = new TerminalBuffer();
        $buffer->feed("line-a\nline-b\nline-c\n");
        $panel = new TerminalPanelWidget();
        $panel->setFocused(true);
        $panel->open('bash', $buffer);

        $lines = $panel->render(new RenderContext(40, 6));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('line-c', $plain);
        foreach ($lines as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testFocusedPanelPlacesTheHardwareCursorAtTheBufferColumn(): void
    {
        $buffer = new TerminalBuffer();
        $buffer->feed("one\nprompt$ ");
        $panel = new TerminalPanelWidget();
        $panel->open('bash', $buffer);

        $panel->setFocused(true);
        $lines = $panel->render(new RenderContext(40, 6));
        $joined = implode('', $lines);
        $this->assertSame(1, substr_count($joined, AnsiUtils::CURSOR_MARKER_PREFIX), 'exactly one marker on the live tail');
        $markerLine = null;
        foreach ($lines as $line) {
            if (!str_contains($line, AnsiUtils::CURSOR_MARKER_PREFIX)) {
                continue;
            }

            $markerLine = $line;
        }
        $this->assertNotNull($markerLine);
        $this->assertStringContainsString('prompt$', AnsiUtils::stripAnsiCodes($markerLine), 'the marker sits on the last body line');
        $pos = strpos($markerLine, AnsiUtils::CURSOR_MARKER_PREFIX);
        $this->assertSame(8, AnsiUtils::visibleWidth(substr($markerLine, offset: 0, length: (int) $pos)), 'at the buffer cursor column');
        foreach ($lines as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line), 'the zero-width marker keeps the width contract');
        }

        $panel->setFocused(false);
        $this->assertStringNotContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, implode('', $panel->render(new RenderContext(40, 6))), 'unfocused: no marker');

        $panel->setFocused(true);
        $buffer->feed(str_repeat("history\n", times: 30)); // enough scrollback to page up into
        $panel->handleInput("\x1b[5~"); // PageUp: scrolled back
        $this->assertStringNotContainsString(AnsiUtils::CURSOR_MARKER_PREFIX, implode('', $panel->render(new RenderContext(40, 6))), 'scrolled back: no marker');
    }

    public function testCursorColumnIsClampedToThePanelWidth(): void
    {
        $buffer = new TerminalBuffer();
        $buffer->feed(str_repeat('x', times: 60));
        $panel = new TerminalPanelWidget();
        $panel->setFocused(true);
        $panel->open('bash', $buffer);

        $lines = $panel->render(new RenderContext(20, 6));
        $joined = implode('', $lines);
        $this->assertSame(1, substr_count($joined, AnsiUtils::CURSOR_MARKER_PREFIX), 'a cursor past the width clamps to the last column');
        foreach ($lines as $line) {
            $this->assertSame(20, AnsiUtils::visibleWidth($line));
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
        $this->assertSame('[scrolled +19]', $panel->statusText(), 'the dock strip status carries the offset');

        $panel->scroll(-1000); // far down → clamped to 0
        $this->assertSame('', $panel->statusText());
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
        $this->assertSame('[scrolled +21]', $panel->statusText());

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
        $panel->onInputForward(static function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });

        // Printable input is forwarded to the process.
        $panel->handleInput('ls');
        $this->assertSame('ls', $forwarded);

        // Page-up scrolls back; the status carries the offset.
        $panel->handleInput("\x1b[5~");
        $this->assertStringContainsString('scrolled', $panel->statusText());

        // Page-down scrolls forward again (clamped at 0).
        $panel->handleInput("\x1b[6~");
        $panel->handleInput("\x1b[6~");
        $this->assertSame('', $panel->statusText());
    }

    public function testHideKeyClosesAndNotifies(): void
    {
        $panel = new TerminalPanelWidget();
        $closed = false;
        $panel->onClose(static function () use (&$closed): void {
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
        $panel->onInputForward(static function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });
        $panel->handleInput('Z'); // consumed by hook, not forwarded
        $this->assertSame('', $forwarded);
    }

    public function testSessionKeysCycleAndCloseWithoutForwarding(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->setFocused(true);
        $panel->open('sh', new TerminalBuffer());

        $forwarded = '';
        $cycled = [];
        $closed = null;
        $panel->onInputForward(static function (string $d) use (&$forwarded): void {
            $forwarded .= $d;
        });
        $panel->onSessionCycled(static function (int $d) use (&$cycled): void {
            $cycled[] = $d;
        });
        $panel->onSessionClosed(static function (int $i) use (&$closed): void {
            $closed = $i;
        });

        $panel->handleInput("\x1b[6^"); // ctrl+page_down → next
        $panel->handleInput("\x1b[5^"); // ctrl+page_up → prev
        $panel->handleInput("\x1b[119;6u"); // ctrl+shift+w → close active
        $this->assertSame([1, -1], $cycled);
        $this->assertSame(-1, $closed, 'the active session (-1) closes');
        $this->assertSame('', $forwarded, 'none of the session keys reached the process');
    }
}
