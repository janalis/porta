<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\ChangeMarkKind;
use Porta\Event\TreeFileActivated;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\PagesThroughRows;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Porta\Tree\FileDecorations;
use Porta\Tree\FileStatuses;
use Porta\Ui\Glyphs;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class FileTreeWidgetTest extends TestCase
{
    use PagesThroughRows;
    use RendersWidget;

    /**
     * The tree's own comment says row 0 is the header and `$rows - 1` entries
     * are visible — and then it paged by the full height, so one file per press
     * scrolled by without ever being drawn.
     */
    public function testPagingDownShowsEveryFile(): void
    {
        $files = [];
        $labels = [];
        for ($i = 0; $i < 40; ++$i) {
            $name = \sprintf('f%02d.php', $i);
            $labels[] = $name;
            $files[$name] = "<?php\n";
        }
        $project = new TempProject($files);
        $tree = new FileTreeWidget($project->dir);

        self::assertPagingShowsEveryRow(
            function () use ($tree): string {
                $tree->beforeRender();

                return $this->plain($tree->render(new RenderContext(30, 9)));
            },
            static fn () => $tree->handleInput("\x1b[6~"),
            $labels,
        );
    }

    public function testAFileRowIsMarkedWithItsWorstDiagnosticAndItsUnsavedChanges(): void
    {
        $project = new TempProject(['clean.php' => "<?php\n", 'broken.php' => "<?php\n", 'edited.php' => "<?php\n"]);
        $buffers = new BufferManager();
        $buffers->open((string) realpath($project->dir.'/broken.php'), "<?php\n")->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0]]];
        $buffers->open((string) realpath($project->dir.'/edited.php'), "<?php\n")->document->insert('x');
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

        $tree->beforeRender();
        $rows = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertMatchesRegularExpression('/broken\.php\s+'.Glyphs::SEVERITY_ERROR.'$/m', $rows, 'the error mark is right-aligned on its own row');
        $this->assertMatchesRegularExpression('/edited\.php\s+'.Glyphs::DIRTY.'$/m', $rows);
        $this->assertMatchesRegularExpression('/clean\.php\s+$/m', $rows, 'a clean file carries no mark at all');
    }

    /** Both marks on one row: the diagnostic first, then the dirty dot, so the columns stay stable. */
    public function testAFileCanCarryBothMarksAtOnce(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $buffers = new BufferManager();
        $buffer = $buffers->open((string) realpath($project->dir.'/a.php'), "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 2, 'message' => 'hmm', 'col' => 0]]];
        $buffer->document->insert('x');
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

        $tree->beforeRender();
        $rows = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertMatchesRegularExpression('/a\.php\s+'.Glyphs::SEVERITY_WARNING.Glyphs::DIRTY.'$/m', $rows);
    }

    /** A marked row is still exactly the tree's width — the marks eat into the name, they do not widen the pane. */
    public function testMarksDoNotWidenTheRow(): void
    {
        $project = new TempProject(['a-really-quite-long-file-name.php' => "<?php\n"]);
        $buffers = new BufferManager();
        $buffers->open((string) realpath($project->dir.'/a-really-quite-long-file-name.php'), "<?php\n")->document->insert('x');
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

        $tree->beforeRender();
        $lines = $tree->render(new RenderContext(30, 10));

        foreach ($lines as $i => $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line), "row {$i} is not the tree's width");
        }
        $this->assertStringEndsWith(Glyphs::DIRTY, AnsiUtils::stripAnsiCodes($lines[1]), 'the mark survived the truncation that clipped the name');
    }

    /**
     * The rendered lines are memoized on the render revision, which an edit in
     * the EDITOR never bumps — so a mark that appears mid-session only shows if
     * beforeRender drops the memo itself.
     */
    public function testAMarkAppearingMidSessionDropsTheMemo(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $buffers = new BufferManager();
        $buffer = $buffers->open((string) realpath($project->dir.'/a.php'), "<?php\n");
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

        $tree->beforeRender();
        $before = $this->plain($tree->render(new RenderContext(30, 10)));
        $this->assertStringNotContainsString(Glyphs::DIRTY, $before);

        $buffer->document->insert('x');
        $tree->beforeRender();
        $after = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertStringContainsString(Glyphs::DIRTY, $after, 'the memo served the pre-edit rows');
    }

    /**
     * A directory row never wears a mark, whatever the buffer table says — the
     * tree asks the question per FILE. An aggregated folder badge would have to
     * walk the subtree on every frame, which is not what this is.
     */
    /**
     * Buffers are keyed by realpath(); the tree's nodes are built from the raw
     * root it was given. Open a project through a symlink and the two spaces
     * diverge — the marks have to be looked up in buffer space or they silently
     * never appear, which is exactly what a decoration bug looks like.
     */
    public function testMarksStillLandWhenTheProjectRootIsASymlink(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $link = $project->dir.'-link';
        symlink($project->dir, $link);

        try {
            $buffers = new BufferManager();
            // What BufferService does: realpath() before keying.
            $buffers->open((string) realpath($link.'/a.php'), "<?php\n")->document->insert('x');
            $tree = new FileTreeWidget($link, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

            $tree->beforeRender();
            $rows = $this->plain($tree->render(new RenderContext(30, 10)));

            $this->assertMatchesRegularExpression('/a\.php\s+'.Glyphs::DIRTY.'$/m', $rows);
        } finally {
            unlink($link);
        }
    }

    /**
     * The version-control mark is the same shape the gutter draws, and it lands
     * on a file no buffer has opened — the whole point of the status channel.
     */
    public function testAChangedFileIsMarkedEvenWithNoBufferOpen(): void
    {
        $project = new TempProject(['added.php' => "<?php\n", 'edited.php' => "<?php\n", 'clean.php' => "<?php\n"]);
        $statuses = new FileStatuses();
        $statuses->applyFileStatuses([
            (string) realpath($project->dir.'/added.php') => ChangeMarkKind::Added,
            (string) realpath($project->dir.'/edited.php') => ChangeMarkKind::Modified,
        ]);
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations(new BufferManager(), $statuses));

        $tree->beforeRender();
        $rows = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertMatchesRegularExpression('/added\.php\s+'.Glyphs::VCS_ADDED.'$/m', $rows);
        $this->assertMatchesRegularExpression('/edited\.php\s+'.Glyphs::VCS_MODIFIED.'$/m', $rows);
        $this->assertMatchesRegularExpression('/clean\.php\s+$/m', $rows);
    }

    /**
     * A publisher names files under the project root it was handed, which is
     * NOT realpath space when the root is a symlink (or /var on macOS). Core
     * keys buffers the other way, so the tree has to accept both — the marks
     * simply never appear otherwise, and nothing else would say so.
     */
    public function testAStatusPublishedInRawRootSpaceStillMarksTheRow(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $link = $project->dir.'-link';
        symlink($project->dir, $link);

        try {
            $statuses = new FileStatuses();
            // Keyed under the raw root the plugin was given, not realpath's.
            $statuses->applyFileStatuses([$link.'/a.php' => ChangeMarkKind::Modified]);
            $tree = new FileTreeWidget($link, fileDecorations: new FileDecorations(new BufferManager(), $statuses));

            $tree->beforeRender();
            $rows = $this->plain($tree->render(new RenderContext(30, 10)));

            $this->assertMatchesRegularExpression('/a\.php\s+'.Glyphs::VCS_MODIFIED.'$/m', $rows);
        } finally {
            unlink($link);
        }
    }

    /** Status, then diagnostic, then dirty — a fixed order, so the columns stay stable. */
    public function testTheThreeMarksShareARowInAFixedOrder(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $path = (string) realpath($project->dir.'/a.php');
        $statuses = new FileStatuses();
        $statuses->applyFileStatuses([$path => ChangeMarkKind::Modified]);
        $buffers = new BufferManager();
        $buffer = $buffers->open($path, "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0]]];
        $buffer->document->insert('x');
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, $statuses));

        $tree->beforeRender();
        $rows = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertMatchesRegularExpression(
            '/a\.php\s+'.Glyphs::VCS_MODIFIED.Glyphs::SEVERITY_ERROR.Glyphs::DIRTY.'$/m',
            $rows,
        );
    }

    public function testADirectoryRowIsNeverMarked(): void
    {
        $project = new TempProject(['sub/a.php' => "<?php\n"]);
        $buffers = new BufferManager();
        // A buffer keyed on the directory's own path: the widget must reject it
        // by asking whether the NODE is a directory, not by trusting the lookup
        // to miss.
        $buffers->open((string) realpath($project->dir.'/sub'), "<?php\n")->document->insert('x');
        $tree = new FileTreeWidget($project->dir, fileDecorations: new FileDecorations($buffers, new FileStatuses()));

        $tree->beforeRender();
        $rows = $this->plain($tree->render(new RenderContext(30, 10)));

        $this->assertMatchesRegularExpression('/sub\/\s+$/m', $rows, 'the directory row carries no mark');
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
        $this->assertLessThan(strpos($plain, needle: 'a.php'), strpos($plain, needle: 'sub/'), 'directories sort first');
    }

    public function testSelectedNodeAndRowMirrorTheListSelection(): void
    {
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->render(new RenderContext(30, 10));

        $this->assertSame('a.php', $tree->selectedNode()?->name, 'boot selects the first entry');
        $this->assertSame(1, $tree->selectedRow(), 'first entry sits just under the header row');

        $tree->nodeAt(2); // select b.php the way a click would
        $this->assertSame('b.php', $tree->selectedNode()?->name);
        $this->assertSame(2, $tree->selectedRow());
    }

    public function testRevealPathExpandsAncestorsAndSelectsTheFile(): void
    {
        $project = new TempProject([
            'a.php' => "<?php\n",
            'src/deep/Target.php' => "<?php\n",
        ]);
        $tree = new FileTreeWidget($project->dir);
        $tree->render(new RenderContext(40, 20)); // boot: only the top level shows

        $this->assertStringNotContainsString('Target.php', $this->plain($tree->render(new RenderContext(40, 20))), 'the deep file is hidden while src is collapsed');

        $tree->revealPath($project->dir.'/src/deep/Target.php');

        $this->assertSame('Target.php', $tree->selectedNode()?->name, 'the revealed file is selected');
        $this->assertStringContainsString('Target.php', $this->plain($tree->render(new RenderContext(40, 20))), 'its ancestors expanded so it shows');
    }

    public function testRevealPathIsANoOpForAnUnresolvablePath(): void
    {
        $project = new TempProject(['a.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->render(new RenderContext(40, 20));
        $before = $tree->selectedNode()?->name;

        $tree->revealPath('/somewhere/else/x.php'); // outside the root: early return
        $tree->revealPath($project->dir.'/nope.php'); // in-root but not surfaced

        $this->assertSame($before, $tree->selectedNode()?->name, 'selection is unchanged for a path the tree cannot show');
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
        AttachedStyles::attach($tree); // the selection shade resolves through the themed sheet
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
        $this->assertSame(50, $tree->width());
        foreach ($tree->render(new RenderContext(80, 6)) as $line) {
            $this->assertSame(50, AnsiUtils::visibleWidth($line), 'rows render at the new width');
        }

        $tree->setWidth(1); // clamped up to the 12-column floor
        $this->assertSame(12, $tree->width());
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
        $events = new EventDispatcher();
        $tree = new FileTreeWidget($project->dir, events: $events);
        $tree->setFocused(true);
        $opened = null;
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened = $event->path;
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

    public function testCollapseJumpsToParentSkippingSiblingsAtTheSameDepth(): void
    {
        $project = new TempProject(['sub/b.php' => "<?php\n", 'sub/c.php' => "<?php\n"]);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->render($context);

        $tree->handleInput("\r"); // expand sub/
        $tree->handleInput("\x1b[B"); // → b.php (depth 1)
        $tree->handleInput("\x1b[B"); // → c.php (depth 1, the second child)

        // Left/collapse on the second child scans upward past its sibling b.php
        // (same depth → skipped) before landing on the sub/ parent.
        $tree->handleInput("\x1b[D");
        $this->assertSame('sub', $tree->selectedNode()?->name);
    }

    public function testRefreshIfChangedSeesAnEntryAddedByANOTHERProcess(): void
    {
        // The tree trusts an unchanged directory mtime older than the grace
        // window and skips the scandir. PHP caches stat results per path for
        // the life of the process and clears them only for writes IT performs,
        // so after the grace window an mtime bumped by ANOTHER process still
        // reads as the cached one — and the directory is trusted forever.
        //
        // The test beside this one writes through TempProject, which is
        // in-process and clears the cache as a side effect, which is why this
        // went unnoticed. Here the writer is a separate process: another tool,
        // a git checkout, a formatter.
        $project = new TempProject(['a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $tree->render(new RenderContext(30, 10));
        $this->assertFalse($tree->refreshIfChanged(), 'nothing changed yet');

        // Past the grace window, so the mtime shortcut is the path under test.
        sleep(3);
        $proc = proc_open(['/bin/sh', '-c', 'printf "" > '.escapeshellarg($project->dir.'/c.php')], [], $pipes);
        \assert(\is_resource($proc), description: 'the external writer started');
        proc_close($proc);

        $this->assertTrue($tree->refreshIfChanged(), 'an entry added by another process is never seen');
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

    public function testARepeatRenderAtTheSameRevisionServesTheMemo(): void
    {
        // The vendor's flex:0 intrinsic measure force-clears the widget cache
        // and renders every frame — the private memo is what keeps a pure
        // editor-scroll frame from re-rendering the tree twice. Proof by
        // sentinel: plant fake memo lines; only a true memo HIT can return
        // them, and only a true MISS (revision bump) can replace them.
        $project = new TempProject(['a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $context = new RenderContext(30, 10);
        $tree->render($context);
        $tree->clearRenderCache(); // what the measure pass does to the stock cache

        Probe::set($tree, 'memoLines', ['SENTINEL']);
        $this->assertSame(['SENTINEL'], $tree->render($context), 'same revision: the memo answered, no re-render happened');

        $this->assertTrue($tree->hoverRow(1), 'hovering the a.php row changes the tint state');
        $rerendered = $tree->render($context);
        $this->assertNotContains('SENTINEL', $rerendered, 'the revision bump missed the memo and re-rendered');
        $this->assertStringContainsString('a.php', $this->plain($rerendered));
    }

    public function testANarrowerContextReRenders(): void
    {
        $project = new TempProject(['a-rather-long-filename.php' => '']);
        $tree = new FileTreeWidget($project->dir);

        $wide = $tree->render(new RenderContext(30, 10));
        $narrow = $tree->render(new RenderContext(12, 10));

        $this->assertNotSame($wide, $narrow, 'the width is part of the memo key');
        $this->assertNotSame($this->plain($wide), $this->plain($narrow), 'the narrow render truncates');
    }

    public function testADifferentRowCountReRenders(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tree = new FileTreeWidget($project->dir);

        $tall = $tree->render(new RenderContext(30, 10));
        $short = $tree->render(new RenderContext(30, 4));

        $this->assertNotSame($tall, $short, 'the row count is part of the memo key');
        $this->assertCount(4, $short);
    }

    public function testAWidthDragReRenders(): void
    {
        $project = new TempProject(['a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $context = new RenderContext(60, 10);
        $first = $tree->render($context);

        $tree->setWidth(45); // the separator drag: invalidates, so the memo misses
        $second = $tree->render($context);

        $this->assertNotSame($first, $second);
        $this->assertNotSame($this->plain($first), $this->plain($second), 'the rows re-padded to the dragged width');
    }

    public function testRefreshDirsSliceWalksTheRingAndSeesALaterDirChange(): void
    {
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->handleInput("\r"); // expand sub/ → scanned dirs: [root, sub]
        $tree->render($context);

        $this->assertFalse($tree->refreshDirsSlice(1), 'slice 1: the root is unchanged');
        $this->assertSame(1, Probe::get($tree, 'dirCursor'));

        $project->write('sub/d.php', '');
        $this->assertTrue($tree->refreshDirsSlice(1), 'slice 2: the cursor reached sub/ and saw the change');
        $this->assertSame(0, Probe::get($tree, 'dirCursor'), 'a hit rewinds the cursor');
        $this->assertStringContainsString('d.php', $this->plain($tree->render($context)));
    }

    public function testRefreshDirsSliceSeesAnExpandedDirectoryVanish(): void
    {
        // Deleting the whole directory (a branch switch dropping a tree): the
        // signature of a gone dir is '' — it must read as changed, not crash.
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->handleInput("\r"); // expand sub/ → scanned dirs: [root, sub]
        $tree->render($context);

        // Park the cursor ON sub/ first, or the root's own change (sub left
        // its entry list) would be the one detected and the gone-dir
        // signature path would never run.
        $this->assertFalse($tree->refreshDirsSlice(1), 'the root is unchanged; the cursor advances to sub/');
        unlink($project->path('sub/b.php'));
        rmdir($project->path('sub'));
        $this->assertTrue($tree->refreshDirsSlice(1), 'the vanished directory reads as changed');
        $this->assertStringNotContainsString('sub', $this->plain($tree->render($context)));
    }

    public function testRefreshDirsSliceWrapsAroundTheRing(): void
    {
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $tree->handleInput("\r"); // two scanned dirs
        $tree->render(new RenderContext(30, 10));

        $this->assertFalse($tree->refreshDirsSlice(1));
        $this->assertFalse($tree->refreshDirsSlice(1));
        $this->assertSame(0, Probe::get($tree, 'dirCursor'), 'the cursor wrapped back to the front');
    }

    public function testRefreshDirsSliceBudgetIsCappedAtTheDirCount(): void
    {
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $tree->handleInput("\r");
        $tree->render(new RenderContext(30, 10));

        $this->assertFalse($tree->refreshDirsSlice(10), 'each dir is checked at most once per call');
        $this->assertSame(0, Probe::get($tree, 'dirCursor'), 'two checks over two dirs land back at the front');

        $project->write('c.php', '');
        $this->assertTrue($tree->refreshDirsSlice(10), 'an over-budget slice still sees the change');
    }

    public function testTheSliceCursorSurvivesARebuildThatShrankTheMap(): void
    {
        $project = new TempProject(['sub/b.php' => '', 'a.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->setFocused(true);
        $context = new RenderContext(30, 10);
        $tree->handleInput("\r"); // expand: [root, sub]
        $tree->render($context);
        $this->assertFalse($tree->refreshDirsSlice(1)); // cursor now 1

        $tree->handleInput("\r"); // collapse sub/ → the map shrinks to [root]
        $project->write('c.php', '');
        $this->assertTrue($tree->refreshDirsSlice(1), 'the stale cursor re-clamps instead of missing the only dir');
        $this->assertStringContainsString('c.php', $this->plain($tree->render($context)));
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

    public function testAnMtimeExactlyAtTheGraceBoundaryIsStillCompared(): void
    {
        $project = new TempProject(['a.php' => '']);
        $clock = new MockClock();
        $boundary = $clock->now()->getTimestamp() - 2; // exactly POLL_GRACE_SECONDS old
        touch($project->dir, $boundary);
        $tree = new FileTreeWidget($project->dir, $clock);

        // A same-mtime change AT the boundary: strictly-older-than is what
        // trusts an mtime, so the boundary itself still gets the scandir.
        $project->write('b.php', '');
        touch($project->dir, $boundary);
        $this->assertTrue($tree->refreshIfChanged(), 'the boundary mtime is not yet trusted');
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
        $events = new EventDispatcher();
        $tree = new FileTreeWidget($project->dir, events: $events);
        $tree->setFocused(true);
        $opened = null;
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened = $event->path;
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
        $events = new EventDispatcher();
        $tree = new FileTreeWidget($project->dir, width: 25, events: $events);

        $this->assertTrue($tree->isVerticallyExpanded());
        $this->assertSame($tree, $tree->expandVertically(false), 'always fills its pane');
        $this->assertSame(25, $tree->width());

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
        $events->addListener(TreeFileActivated::class, static function (TreeFileActivated $event) use (&$opened): void {
            $opened = $event->path;
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
            array_map(static fn ($l) => trim(\Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($l)), array_slice($tree->render(new RenderContext(20, 4)), offset: 1)),
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
        $this->assertSame('sub', $sub->name);
        $this->assertTrue($sub->isDir);

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

        foreach (range(1, end: 20) as $_) {
            $tree->handleInput("\x1b[B");
        }
        $lines = $tree->render($context);

        $this->assertCount(8, $lines);
        foreach ($lines as $line) {
            $this->assertSame(30, AnsiUtils::visibleWidth($line));
        }
        $this->assertStringContainsString('file-20.php', $this->plain($lines), 'viewport followed the selection');

        // A page is the seven ENTRY rows, not the eight-row pane: row 0 is the
        // header. So page up from 20 lands on 13, and the two screens meet —
        // paging by 8 landed on 12 and slid file-13 past unseen.
        $tree->handleInput("\x1b[5~");
        $this->assertStringContainsString('file-13.php', $this->plain($tree->render($context)));
        $tree->handleInput("\x1b[6~"); // page down
        $this->assertStringContainsString('file-20.php', $this->plain($tree->render($context)));
    }
}
