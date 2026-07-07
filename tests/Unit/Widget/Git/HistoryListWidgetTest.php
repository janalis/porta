<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\LogEntry;
use Porta\Widget\Git\HistoryListWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class HistoryListWidgetTest extends TestCase
{
    private function widget(): HistoryListWidget
    {
        $widget = new HistoryListWidget();
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('full1', 'aaa111', 'Alice', 1700000100, 'Newest work'),
            new LogEntry('full2', 'bbb222', 'Bob', 1700000000, 'Older work'),
        ]);
        $widget->setFiles([
            ['status' => 'M', 'path' => 'src/a.php'],
            ['status' => 'A', 'path' => 'new.txt'],
        ]);

        return $widget;
    }

    private function plain(HistoryListWidget $widget): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(44, 16))));
    }

    public function testRendersCommitsAndFiles(): void
    {
        $screen = $this->plain($this->widget());

        $this->assertStringContainsString('History (2)', $screen);
        // With 16 rows and 2 files, filler rows pad the files section.
        $this->assertGreaterThan(10, substr_count($screen, "\n"), 'padded to full height');
        $this->assertStringContainsString('aaa111 Newest work  Alice', $screen);
        $this->assertStringContainsString('Files (2)', $screen);
        $this->assertStringContainsString('M src/a.php', $screen);
    }

    public function testCommitNavigationFiresAndEnterSwitchesToFiles(): void
    {
        $widget = $this->widget();
        $events = [];
        $widget->onSelectCommit(function (LogEntry $c) use (&$events): void {
            $events[] = 'commit:'.$c->shortSha;
        });
        $widget->onSelectFile(function (LogEntry $c, string $path) use (&$events): void {
            $events[] = 'file:'.$path;
        });

        $widget->handleInput("\x1b[B"); // second commit
        $widget->handleInput("\r");     // into the file list
        $widget->handleInput("\x1b[B"); // second file

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $this->assertSame('full2', $widget->selectedCommit()?->sha);
    }

    public function testClickRoutesToCommitOrFileRows(): void
    {
        $widget = $this->widget();
        $events = [];
        $widget->onSelectCommit(function (LogEntry $c) use (&$events): void {
            $events[] = 'commit:'.$c->shortSha;
        });
        $widget->onSelectFile(function (LogEntry $c, string $path) use (&$events): void {
            $events[] = 'file:'.$path;
        });
        $widget->render(new RenderContext(44, 16)); // teach it its height

        $widget->handleClick(2);  // second commit row
        // With 16 rows: commits occupy rows 1..6, files header row 7, files from row 8.
        $widget->handleClick(9);  // second file row

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $widget->handleClick(0);  // header: ignored
        $this->assertCount(2, $events);
    }

    public function testShowChangesHookAndHousekeeping(): void
    {
        $widget = $this->widget();
        $events = [];
        $widget->onShowChanges(function () use (&$events): void {
            $events[] = 'changes';
        });
        $widget->onFocusNext(function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(function () use (&$events): void {
            $events[] = 'exit';
        });
        $widget->onInput(static fn (string $d): bool => false);

        $hooked = new HistoryListWidget();
        $hooked->setFocused(true);
        $hooked->onInput(static fn (string $d): bool => true);
        $hooked->handleInput('1'); // swallowed by the hook

        $widget->handleInput('1');
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $this->assertSame(['changes', 'next', 'exit'], $events);

        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
        $widget->setCommits([]); // reset clears files and focus
        $this->assertNull($widget->selectedCommit());
    }
}
