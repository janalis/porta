<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\DiffPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DiffPanelWidgetTest extends TestCase
{
    /** @return list<array{kind: string, text: string}> */
    private function rows(): array
    {
        return [
            ['kind' => 'meta', 'text' => 'diff --git a/x b/x'],
            ['kind' => 'hunk', 'text' => '@@ -1 +1 @@'],
            ['kind' => 'context', 'text' => ' unchanged'],
            ['kind' => 'del', 'text' => '-gone'],
            ['kind' => 'add', 'text' => '+fresh'],
        ];
    }

    public function testClosedRendersNothingAndIgnoresInput(): void
    {
        $panel = new DiffPanelWidget();
        $this->assertSame([], $panel->render(new RenderContext(80, 14)));
        $panel->handleInput("\x1b[5~"); // scroll while closed: no-op
        $this->assertFalse($panel->isOpen());
        $panel->close(); // closing a closed panel is a no-op
        $this->assertFalse($panel->isOpen());
    }

    public function testOpenRendersColouredRowsAtExactWidth(): void
    {
        $panel = new DiffPanelWidget();
        $panel->setFocused(true);
        $panel->open('Diff: x (vs HEAD)', $this->rows());
        $this->assertTrue($panel->isOpen());
        $this->assertSame('Diff: x (vs HEAD)', $panel->getTitle());

        $lines = $panel->render(new RenderContext(40, 8));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('Diff: x (vs HEAD)', $plain);
        $this->assertStringContainsString('+fresh', $plain);
        $this->assertStringContainsString('-gone', $plain);

        // Additions are green, deletions red (colours applied per row).
        $joined = implode('', $lines);
        $this->assertStringContainsString("\x1b[38;5;114m", $joined, 'green additions');
        $this->assertStringContainsString("\x1b[38;5;203m", $joined, 'red deletions');

        foreach ($lines as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testSetVisibleRowsChangesRenderedHeight(): void
    {
        $panel = new DiffPanelWidget();
        $panel->open('Diff', $this->rows());

        $this->assertCount(14, $panel->render(new RenderContext(40, 30)), 'default 14 rows');
        $panel->setVisibleRows(20);
        $this->assertCount(20, $panel->render(new RenderContext(40, 30)), 'resized to 20 rows');
        $panel->setVisibleRows(1); // clamped to the floor of 2
        $this->assertCount(2, $panel->render(new RenderContext(40, 30)));
    }

    public function testScrollingClampsAndShowsOffset(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; ++$i) {
            $rows[] = ['kind' => 'context', 'text' => " row {$i}"];
        }
        $panel = new DiffPanelWidget();
        $panel->setFocused(true);
        $panel->open('Diff', $rows);

        $panel->handleInput("\x1b[5~"); // page up scrolls back
        $header = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 8))[0]);
        $this->assertStringContainsString('scrolled', $header);

        $panel->handleInput("\x1b[6~"); // page down
        $panel->handleInput("\x1b[6~"); // clamped at 0
        $header = AnsiUtils::stripAnsiCodes($panel->render(new RenderContext(50, 8))[0]);
        $this->assertStringNotContainsString('scrolled', $header);
    }

    public function testHideKeyClosesAndNotifies(): void
    {
        $panel = new DiffPanelWidget();
        $closed = false;
        $panel->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $panel->open('Diff', $this->rows());

        $panel->handleInput("\x1b"); // escape hides
        $this->assertTrue($closed);
        $this->assertFalse($panel->isOpen());
    }

    public function testOnInputHookConsumes(): void
    {
        $panel = new DiffPanelWidget();
        $panel->open('Diff', $this->rows());
        $panel->onInput(static fn (string $d): bool => 'Z' === $d);
        $panel->handleInput('Z'); // consumed by the hook, panel stays open
        $this->assertTrue($panel->isOpen());
    }
}
