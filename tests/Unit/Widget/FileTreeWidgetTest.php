<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class FileTreeWidgetTest extends TestCase
{
    private function plain(array $lines): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    public function testListsDirsFirstThenFiles(): void
    {
        $project = new TempProject([
            'a.php' => "<?php\n",
            'sub/b.php' => "<?php\n",
        ]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);

        $plain = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertStringContainsString('sub/', $plain);
        $this->assertStringContainsString('a.php', $plain);
        $this->assertLessThan(strpos($plain, 'a.php'), strpos($plain, 'sub/'), 'directories sort first');
    }

    public function testHoverHighlightsRowUnderPointer(): void
    {
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'sub/c.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context); // rows: 0 header, 1 sub/ (selected), 2 a.php, 3 b.php

        // Row 2 → a.php (a file, not selected): hover highlights it.
        $this->assertTrue($tree->hoverRow(2));
        $this->assertFalse($tree->hoverRow(2), 'same row → no change');
        $this->assertStringContainsString("\x1b[48;5;236m", $tree->render($context)[2], 'hovered file row gets the hover background');

        // Move the selection off sub/, then hover the sub/ directory row (not selected).
        $tree->handleInput("\x1b[B"); // selected → a.php
        $this->assertTrue($tree->hoverRow(1));
        $dirLine = $tree->render($context)[1];
        $this->assertStringContainsString("\x1b[48;5;236m", $dirLine);
        $this->assertStringContainsString("\x1b[38;5;110m", $dirLine, 'a hovered directory keeps its blue foreground');

        // The header row and an out-of-range row clear the hover.
        $this->assertTrue($tree->hoverRow(0), 'header row clears the hover');
        $tree->hoverRow(2);
        $this->assertTrue($tree->hoverRow(99), 'out-of-range row clears the hover');
    }

    public function testHoverOnSelectedRowKeepsSelectionStyle(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context); // selected = 0 (a.php at row 1)

        $this->assertTrue($tree->hoverRow(1)); // hover the selected row
        $line = $tree->render($context)[1];
        $this->assertStringContainsString("\x1b[48;5;31m", $line, 'the selected style wins over hover');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $line, 'no hover background on the selected row');
    }

    public function testSetWidthResizesRenderedRowsAndClamps(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);

        $tree->setWidth(50);
        $this->assertSame(50, $tree->getWidth());
        foreach ($tree->render(new RenderContext(80, 6)) as $line) {
            $this->assertSame(50, AnsiUtils::visibleWidth($line), 'rows render at the new width');
        }

        $tree->setWidth(1); // clamped up to the 12-column floor
        $this->assertSame(12, $tree->getWidth());
    }

    public function testClearHover(): void
    {
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->render(new RenderContext(30, 10));

        $tree->hoverRow(2);
        $this->assertTrue($tree->clearHover());
        $this->assertFalse($tree->clearHover(), 'already clear → no change');
    }

    public function testExpandCollapseAndOpen(): void
    {
        $project = new TempProject([
            'a.php' => "<?php\n",
            'sub/b.php' => "<?php\n",
        ]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $opened = null;
        $tree->onOpenFile(function (string $path) use (&$opened): void {
            $opened = $path;
        });
        $context = new RenderContext(30, 10);

        $tree->handleInput("\r"); // expand sub/
        $this->assertStringContainsString('b.php', $this->plain($tree->render($context)));

        $tree->handleInput("\x1b[B"); // down to b.php
        $tree->handleInput("\r"); // open it
        $this->assertStringEndsWith('b.php', (string) $opened);

        $tree->handleInput("\x1b[D"); // left: jump to parent
        $tree->handleInput("\x1b[D"); // left again: collapse
        $this->assertStringNotContainsString('b.php', $this->plain($tree->render($context)));

        $tree->handleInput("\x1b[C"); // right: expand
        $this->assertStringContainsString('b.php', $this->plain($tree->render($context)));
    }

    public function testRefreshIfChangedDetectsAddedAndRemovedEntries(): void
    {
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->handleInput("\r"); // expand sub/ so it is a scanned directory too
        $tree->render($context);

        $this->assertFalse($tree->refreshIfChanged(), 'nothing changed on disk');

        $project->write('c.php', ''); // new file in the root (a scanned dir)
        $this->assertTrue($tree->refreshIfChanged(), 'a new root entry is detected');
        $this->assertStringContainsString('c.php', $this->plain($tree->render($context)));
        $this->assertStringContainsString('b.php', $this->plain($tree->render($context)), 'sub/ stayed expanded across the refresh');

        $this->assertFalse($tree->refreshIfChanged(), 'no further change');
        $project->write('sub/d.php', ''); // new file inside the expanded sub/
        $this->assertTrue($tree->refreshIfChanged(), 'a change in an expanded subdir is detected');
        $this->assertStringContainsString('d.php', $this->plain($tree->render($context)));
    }

    public function testPollSkipsTheScandirWhenTheDirMtimeIsOldAndUnchanged(): void
    {
        $project = new TempProject(['a.php' => '']);
        $past = time() - 60;
        touch($project->dir, $past);
        $tree = new FileTreeWidget($project->dir); // rebuild records sig + the old mtime

        // A change the mtime does not betray: add an entry, then restore the
        // old dir mtime. Outside the grace window the poll trusts the mtime
        // and skips the scandir, so the change goes (deliberately) unseen.
        $project->write('b.php', '');
        touch($project->dir, $past);
        $this->assertFalse($tree->refreshIfChanged(), 'unchanged old mtime short-circuits the scandir');

        // As soon as the mtime moves, the signature is compared again.
        touch($project->dir);
        $this->assertTrue($tree->refreshIfChanged(), 'a moved mtime re-verifies the entry list');
    }

    public function testPollInsideTheGraceWindowStillComparesEntryLists(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tree = new FileTreeWidget($project->dir); // dir mtime is "now": inside the grace window

        $mtime = (int) filemtime($project->dir);
        $project->write('b.php', '');
        touch($project->dir, $mtime); // same-second change: mtime identical
        $this->assertTrue($tree->refreshIfChanged(), 'fresh dirs are scandir-compared even with an identical mtime');
    }

    public function testIgnoredEntriesHidden(): void
    {
        $project = new TempProject([
            '.git/config' => '',
            'node_modules/x.js' => '',
            '.porta/session.json' => '{}', // the IDE's own metadata dir is hidden…
            '.porta.json' => '{}', // …but the project config file still shows
            'visible.php' => "<?php\n",
        ]);
        $tree = new FileTreeWidget($project->dir);

        $plain = $this->plain($tree->render(new RenderContext(30, 10)));
        $this->assertStringNotContainsString('.git', $plain);
        $this->assertStringNotContainsString('node_modules', $plain);
        $this->assertStringNotContainsString('.porta/', $plain, 'IDE metadata dir hidden');
        $this->assertStringContainsString('.porta.json', $plain, 'project config file shown');
        $this->assertStringContainsString('visible.php', $plain);
    }

    public function testClickRowSelectsOpensAndTogglesDirectories(): void
    {
        $project = new TempProject(['sub/b.php' => "<?php\n", 'a.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $opened = null;
        $tree->onOpenFile(function (string $p) use (&$opened): void {
            $opened = $p;
        });
        $context = new RenderContext(30, 10);
        $tree->render($context);

        // Header row (relRow 0) is a no-op.
        $tree->clickRow(0);
        $this->assertNull($opened);

        // Row 1 = sub/ (a directory) -> toggles it open.
        $tree->clickRow(1);
        $this->assertStringContainsString('b.php', $this->plain($tree->render($context)));

        // Row out of range -> no-op.
        $tree->clickRow(999);
        $this->assertNull($opened);

        // Row 2 is now sub/b.php -> opens it.
        $tree->clickRow(2);
        $this->assertStringEndsWith('b.php', (string) $opened);
    }

    public function testReloadPicksUpNewFiles(): void
    {
        $project = new TempProject(['first.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context);

        $project->write('second.php', '');
        $tree->handleInput('r');

        $this->assertStringContainsString('second.php', $this->plain($tree->render($context)));
    }

    public function testAccessorsAndHooks(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '']);
        $tree = new FileTreeWidget($project->dir, width: 25);

        $this->assertTrue($tree->isVerticallyExpanded());
        $this->assertSame($tree, $tree->expandVertically(false), 'always fills its pane');
        $this->assertSame(25, $tree->getWidth());

        // onInput hook consumes input before default handling
        $tree->setFocused(true);
        $tree->onInput(static fn (string $data): bool => "\x1b[B" === $data);
        $tree->handleInput("\x1b[B"); // consumed: selection unchanged
        $tree->handleInput("\r"); // opens first entry (a.php, not b.php)
        // move up from row 1
        $tree->onInput(null);
        $tree->handleInput("\x1b[B");
        $tree->handleInput("\x1b[A");
        $opened = null;
        $tree->onOpenFile(function (string $p) use (&$opened): void {
            $opened = $p;
        });
        $tree->handleInput("\r");
        $this->assertStringEndsWith('a.php', (string) $opened, 'up-arrow returned to the first file');
    }

    public function testEmptyDirectoryHandlesInput(): void
    {
        $project = new TempProject();
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);

        $tree->handleInput("\x1b[B"); // no entries: early return, no crash
        $this->assertSame([], array_filter(
            array_map(fn ($l) => trim(\Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($l)), array_slice($tree->render(new RenderContext(20, 4)), 1)),
        ));
    }

    public function testNodeAtSelectsTheClickedEntry(): void
    {
        $project = new TempProject(['sub/b.php' => "<?php\n", 'a.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context);

        $this->assertNull($tree->nodeAt(0), 'header row targets nothing');
        $this->assertNull($tree->nodeAt(999), 'a click past the last entry targets nothing');

        $sub = $tree->nodeAt(1);
        $this->assertNotNull($sub);
        $this->assertSame('sub', $sub['name']);
        $this->assertTrue($sub['isDir']);

        // Row 1 is the selected entry; its highlighted line shows the directory.
        $lines = $tree->render($context);
        $this->assertStringContainsString('sub/', AnsiUtils::stripAnsiCodes($lines[1]));
    }

    public function testSetMenuOverlayCompositesWithoutChangingWidth(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context);

        $segment = "\x1b[48;5;236m CONTEXT \x1b[0m"; // 9 visible cells
        $tree->setMenuOverlay([['row' => 1, 'col' => 2, 'width' => 9, 'segment' => $segment]]);
        $lines = $tree->render($context);

        $this->assertStringContainsString('CONTEXT', AnsiUtils::stripAnsiCodes($lines[1]), 'overlay composited onto the row');
        $this->assertSame(30, AnsiUtils::visibleWidth($lines[1]), 'row width unchanged by the overlay');

        $tree->setMenuOverlay([]); // clearing removes it
        $this->assertStringNotContainsString('CONTEXT', $this->plain($tree->render($context)));
    }

    public function testRenderPadsToExactWidthAndScrolls(): void
    {
        $files = [];
        for ($i = 0; $i < 30; ++$i) {
            $files[\sprintf('file-%02d.php', $i)] = '';
        }
        $project = new TempProject($files);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 8);

        foreach (range(1, 20) as $_) {
            $tree->handleInput("\x1b[B");
        }
        $lines = $tree->render($context);

        $this->assertCount(8, $lines);
        foreach ($lines as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString('file-20.php', $this->plain($lines), 'viewport followed the selection');

        $tree->handleInput("\x1b[5~"); // page up: selection moves one page back
        $this->assertStringContainsString('file-12.php', $this->plain($tree->render($context)));
        $tree->handleInput("\x1b[6~"); // page down
        $this->assertStringContainsString('file-20.php', $this->plain($tree->render($context)));
    }
}
