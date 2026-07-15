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
        $panel->onInputForward(static function (string $d) use (&$forwarded): void {
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

    public function testTabStripRendersSessionsWithActiveStylingAndCloseZones(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('ignored', new TerminalBuffer());
        $panel->setSessionTabsProvider(static fn (): array => [
            ['title' => 'sh 1', 'running' => true, 'active' => false],
            ['title' => 'build', 'running' => false, 'active' => true],
        ]);

        $strip = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(60, 6))[0]);
        $this->assertStringContainsString('sh 1', $strip);
        $this->assertStringContainsString('build', $strip);
        $this->assertStringContainsString('○', $strip, 'the exited session shows a stopped glyph');
        $this->assertStringContainsString('×', $strip, 'each tab has a close affordance');

        // A click on "build"'s label selects it; a click on its × closes it.
        $closed = null;
        $selected = null;
        $panel->onSessionSelected(static function (int $i) use (&$selected): void {
            $selected = $i;
        });
        $panel->onSessionClosed(static function (int $i) use (&$closed): void {
            $closed = $i;
        });
        // The strip is "│ sh 1 × │ build ○ × │" — column into the "build" word.
        $buildCol = mb_strpos($strip, needle: 'build');
        $panel->handleTabClick((int) $buildCol);
        $this->assertSame(1, $selected, 'clicking the label selected the tab');

        $closeCol = mb_strpos($strip, needle: '×', offset: (int) $buildCol);
        $panel->handleTabClick((int) $closeCol);
        $this->assertSame(1, $closed, 'clicking the × closed the tab');

        // A click in the empty tail hits nothing.
        $selected = null;
        $panel->handleTabClick(59);
        $this->assertNull($selected);
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

    public function testTabStripDropsLeadingTabsToKeepTheActiveVisible(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('ignored', new TerminalBuffer());
        // Ten short tabs with the middle one active: leading tabs drop (so the
        // active fits) and trailing tabs overflow the width (the render breaks).
        $panel->setSessionTabsProvider(static fn (): array => array_map(
            static fn (int $i): array => ['title' => 't'.$i, 'running' => true, 'active' => 5 === $i],
            range(0, end: 9),
        ));

        $strip = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(20, 6))[0]);
        $this->assertStringContainsString('t5', $strip, 'the active tab is visible');
        $this->assertStringNotContainsString('t0', $strip, 'leading tabs were dropped');
        $this->assertStringNotContainsString('t9', $strip, 'trailing tabs beyond the width were cut');
        $this->assertSame(20, AnsiUtils::visibleWidth($panel->render(new RenderContext(20, 6))[0]), 'the strip honours the width');
    }

    public function testBeforeRenderRepaintsWhenASessionIsRenamed(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('sh', new TerminalBuffer());
        $title = 'build';
        $panel->setSessionTabsProvider(static function () use (&$title): array {
            return [['title' => $title, 'running' => true, 'active' => true]];
        });

        $panel->beforeRender(); // establishes the baseline fingerprint
        $rev = $panel->getRenderRevision();
        $panel->beforeRender(); // unchanged: no invalidation
        $this->assertSame($rev, $panel->getRenderRevision());

        $title = 'build — exited (0)';
        $panel->beforeRender();
        $this->assertGreaterThan($rev, $panel->getRenderRevision(), 'the rename invalidated the strip');
    }

    public function testWithoutAProviderASingleSyntheticTabRenders(): void
    {
        $panel = new TerminalPanelWidget();
        $panel->open('lonely', new TerminalBuffer());
        $panel->beforeRender(); // no provider: a no-op
        $strip = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(40, 6))[0]);
        $this->assertStringContainsString('lonely', $strip);
        // Clicking the synthetic tab still routes (selects index 0).
        $selected = null;
        $panel->onSessionSelected(static function (int $i) use (&$selected): void {
            $selected = $i;
        });
        $panel->handleTabClick((int) mb_strpos($strip, needle: 'lonely'));
        $this->assertSame(0, $selected);
    }
}
