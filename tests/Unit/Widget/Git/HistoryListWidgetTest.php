<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\LogEntry;
use Porta\I18n\Translator;
use Porta\Widget\Git\HistoryListWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class HistoryListWidgetTest extends TestCase
{
    private function widget(): HistoryListWidget
    {
        $widget = new HistoryListWidget(new Translator('en'));
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
        $widget->handleInput("\r"); // into the file list
        $widget->handleInput("\x1b[B"); // second file

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $this->assertSame('full2', $widget->selectedCommit()?->sha);
    }

    public function testHoverTintsCommitAndFileRowsWithoutSelecting(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(44, 16)); // commitRowCount = 6, Files header row 7
        $fired = 0;
        $widget->onSelectCommit(function () use (&$fired): void {
            ++$fired;
        });
        $widget->onSelectFile(function () use (&$fired): void {
            ++$fired;
        });

        $this->assertTrue($widget->hoverRow(2), 'second commit row tints');
        $this->assertFalse($widget->hoverRow(2), 'same row: no repaint');
        $rendered = $widget->render(new RenderContext(44, 16));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2]);
        $this->assertSame('full1', $widget->selectedCommit()?->sha, 'selection untouched');

        $this->assertTrue($widget->hoverRow(9), 'second file row tints');
        $rendered = $widget->render(new RenderContext(44, 16));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[9]);
        $this->assertStringNotContainsString("\x1b[48;5;236m", $rendered[2], 'the commit tint moved away');

        $this->assertTrue($widget->hoverRow(7), 'the Files header clears both tints');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertTrue($widget->hoverRow(1));
        $this->assertTrue($widget->clearHover());
        $this->assertSame(0, $fired, 'hover fires no selection callbacks');

        $widget->hoverRow(1);
        $widget->setCommits([new LogEntry('x', 'ccc333', 'Cara', 1700000200, 'Reset')]);
        $this->assertFalse($widget->clearHover(), 'setCommits already dropped the tint');

        $widget->setFiles([['status' => 'M', 'path' => 'z.txt']]);
        $widget->render(new RenderContext(44, 16));
        $widget->hoverRow(8);
        $widget->setFiles([['status' => 'M', 'path' => 'z.txt']]);
        $this->assertFalse($widget->clearHover(), 'setFiles already dropped the file tint');
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

        $widget->handleClick(2); // second commit row
        // With 16 rows: commits occupy rows 1..6, files header row 7, files from row 8.
        $widget->handleClick(9); // second file row

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $widget->handleClick(0); // header: ignored
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

        $hooked = new HistoryListWidget(new Translator('en'));
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

    public function testLoadMoreFiresOnceAtTheBottomAndAppendPreservesSelection(): void
    {
        $widget = new HistoryListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('s1', 'aaa111', 'A', 1700000300, 'one'),
            new LogEntry('s2', 'bbb222', 'B', 1700000200, 'two'),
            new LogEntry('s3', 'ccc333', 'C', 1700000100, 'three'),
        ]);
        $loads = 0;
        $widget->setHasMore(true);
        $widget->onLoadMore(function () use (&$loads): void {
            ++$loads;
        });

        $widget->handleInput("\x1b[B");
        $this->assertSame(0, $loads, 'mid-list navigation never loads');
        $widget->handleInput("\x1b[B"); // bottom row
        $this->assertSame(1, $loads);
        $widget->handleInput("\x1b[A");
        $widget->handleInput("\x1b[B"); // bottom again, latch still armed
        $this->assertSame(1, $loads, 'one in-flight load at a time');

        // The append lands: dedupe by sha, selection preserved, latch cleared.
        $widget->appendCommits([
            new LogEntry('s3', 'ccc333', 'C', 1700000100, 'three'), // --skip drift duplicate
            new LogEntry('s4', 'ddd444', 'D', 1700000000, 'four'),
        ]);
        $this->assertSame(4, $widget->commitCount());
        $this->assertSame('three', $widget->selectedCommit()?->subject, 'the selection survived the append');

        $widget->handleInput("\x1b[B"); // the new bottom re-arms
        $this->assertSame(2, $loads);

        // No older page: bottom navigation stays quiet.
        $widget->setHasMore(false);
        $widget->handleInput("\x1b[A");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $this->assertSame(2, $loads);
    }

    public function testRemoteKeysRouteToTheHook(): void
    {
        $widget = $this->widget();
        $widget->handleInput('f'); // no hook attached: a safe no-op

        $operations = [];
        $widget->onRemote(function (string $operation) use (&$operations): void {
            $operations[] = $operation;
        });
        $widget->handleInput('p');
        $widget->handleInput('P');
        $this->assertSame(['pull', 'push'], $operations);
    }
}
