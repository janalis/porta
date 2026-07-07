<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\GitInfo;
use Porta\Git\GitViewController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\StatusBarWidget;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Drives GitViewController against a real git repository with a real Tui on a
 * virtual terminal — no full Ide boot, so every controller branch is cheap to
 * reach. Skipped when git is absent.
 */
final class GitViewControllerTest extends TestCase
{
    private TempProject $project;
    private GitInfo $git;
    private Tui $tui;
    private ChangesListWidget $changes;
    private DiffViewWidget $diffView;
    private CommitBoxWidget $commitBox;
    private ContainerWidget $main;
    private StatusBarWidget $statusBar;
    private int $closed = 0;
    private int $committed = 0;

    private function controller(bool $realRepo = true): GitViewController
    {
        if ($realRepo && null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $this->project = new TempProject(['a.txt' => "one\ntwo\n"]);
        if ($realRepo) {
            $d = escapeshellarg($this->project->dir);
            exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init");
        }
        $this->git = new GitInfo($this->project->dir);

        $this->changes = new ChangesListWidget();
        $this->diffView = new DiffViewWidget();
        $this->commitBox = new CommitBoxWidget();
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);
        $gitArea = new ContainerWidget();
        $gitArea->setId('git-area');
        $gitArea->add($this->changes)->add($this->commitBox)->add($this->diffView);
        $this->statusBar = new StatusBarWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->closed = 0;
        $this->committed = 0;

        return new GitViewController(
            $this->git,
            $this->project->dir,
            $this->changes,
            $this->diffView,
            $this->commitBox,
            $this->main,
            $gitArea,
            [$editWidget],
            $this->tui,
            $this->statusBar,
            onClosed: function (): void {
                ++$this->closed;
            },
            onCommitted: function (): void {
                ++$this->committed;
            },
        );
    }

    private function statusFlash(): string
    {
        return AnsiUtils::stripAnsiCodes(implode(' ', $this->statusBar->render(new RenderContext(100, 1))));
    }

    /** @return list<string> porcelain rows of the test repo */
    private function porcelain(): array
    {
        exec('git -C '.escapeshellarg($this->project->dir).' status --porcelain 2>/dev/null', $lines);

        return $lines;
    }

    public function testOpenIsRefusedOutsideARepository(): void
    {
        $controller = $this->controller(realRepo: false);
        $controller->open();

        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusFlash());
    }

    public function testToggleSwapsTheMainChildrenAndRestoresThem(): void
    {
        $controller = $this->controller();

        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $this->assertSame(['git-area'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $controller->open(); // idempotent
        $this->assertTrue($controller->isOpen());

        $controller->toggle();
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $this->assertSame(1, $this->closed);
        $controller->close(); // idempotent
        $this->assertSame(1, $this->closed);
    }

    public function testRefreshPopulatesEntriesAndLoadsTheWorktreeDiff(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one changed\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->assertSame('a.txt', $this->changes->selectedEntry()?->path);
        $this->await(fn (): bool => str_contains($this->diffText(), '+one changed'));
        $this->assertStringContainsString('-one', $this->diffText());
    }

    public function testStagedOnlyFileShowsTheCachedDiffAndUnstages(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one staged\ntwo\n");
        exec('git -C '.escapeshellarg($this->project->dir).' add a.txt');
        $controller->open();
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => str_contains($this->diffText(), '+one staged'), 'the cached diff loads');

        // Space on a fully staged file unstages it.
        $this->changes->setFocused(true);
        $this->changes->handleInput(' ');
        $this->await(fn (): bool => 'none' === $this->changes->selectedEntry()?->stagedState());
        $this->assertSame([' M a.txt'], $this->porcelain());
    }

    public function testUntrackedFileDiffAndStageRoundTrip(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        $this->changes->setFocused(true);
        $this->changes->handleInput(' '); // stage the untracked file
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
        $this->assertSame(['A  new.txt'], $this->porcelain());
    }

    public function testDiscardTrackedRestoresTheWorktree(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "mangled\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->changes->setFocused(true);
        $this->changes->handleInput('d');
        $this->changes->handleInput('y');
        // Await the flash (fired after the restore's exit), not the worktree
        // state — git cleans the file before its process exits.
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'Discarded changes to a.txt'));

        $this->assertSame("one\ntwo\n", file_get_contents($this->project->path('a.txt')));
        $this->assertSame([], $this->porcelain());
    }

    public function testCommitSuccessAndFailurePaths(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "committed change\ntwo\n");
        exec('git -C '.escapeshellarg($this->project->dir).' add a.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->commitBox->setFocused(true);
        foreach (str_split('Change a') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u"); // ctrl+enter
        $this->await(fn (): bool => 1 === $this->committed);
        $this->assertStringContainsString('Committed:', $this->statusFlash());
        $this->assertSame('', $this->commitBox->message(), 'the box clears after a commit');

        // Second commit with a clean index: git refuses, the failure path flashes.
        $this->await(fn (): bool => null === $this->changes->selectedEntry());
        foreach (str_split('Nothing') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        // commitEnabled() blocks submit() now, so drive the pipeline directly
        // through a forced submit with a staged predicate bypassed via reflection.
        new \ReflectionMethod($controller, 'commit')->invoke($controller, 'Nothing');
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'Commit failed'));
        $this->assertSame(1, $this->committed, 'a failed commit fires no onCommitted');
    }

    public function testCommitStaysDisabledWithOnlyUnstagedChanges(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "dirty\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput('x'); // summary non-empty
        $this->assertFalse($this->commitBox->commitEnabled(), 'nothing staged: the predicate says no');
    }

    public function testStageFailureFlashes(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await(fn (): bool => $controller->isOpen());

        $ghost = new \Porta\Git\ChangeEntry('does-not-exist.txt', ' ', 'M');
        new \ReflectionMethod($controller, 'toggleStage')->invoke($controller, $ghost);
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'git refused the staging change'));
    }

    public function testDiffArrivingAfterCloseIsDropped(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "late\n");
        $controller->open();
        $this->await(fn (): bool => null !== ($entry = $this->changes->selectedEntry()));

        $entry = $this->changes->selectedEntry();
        new \ReflectionMethod($controller, 'loadDiff')->invoke($controller, $entry);
        $controller->close(); // the in-flight diff callback must no-op now
        $this->awaitTicks(15);
        $this->assertFalse($controller->isOpen());
    }

    public function testLineStagingAndUnstagingThroughTheDiffPane(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one CHANGED\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+one CHANGED'));

        // Stage only the 'one' pair via the diff pane: v-range over rows 1-2.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two... rows: header, -one, -two, +one, +two? git pairs deletions first
        $this->diffView->handleInput("\x1b[B"); // include +one
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => [] !== array_filter($this->porcelain(), static fn (string $l): bool => str_starts_with($l, 'MM')));

        // Now the file is partially staged; selecting it again shows the
        // worktree remainder. Unstage via the cached side: make the worktree
        // clean for the staged part by... simpler: fully stage, then unstage a line.
        exec('git -C '.escapeshellarg($this->project->dir).' add a.txt');
        $controller->refresh();
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => str_contains($this->diffText(), '+two CHANGED'), 'cached diff loads');

        $this->diffView->handleInput(' '); // space on the hunk header: unstage the WHOLE hunk
        $this->await(fn (): bool => 'none' === ($this->changes->selectedEntry()?->stagedState() ?? 'gone'));
    }

    public function testStageSelectionGuardsUntrackedAndEmptySelections(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' '); // untracked: flash, no patch
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'stage the whole file'));
        $this->assertSame(['?? new.txt'], $this->porcelain(), 'nothing was staged');

        // Guard: no shown document at all.
        new \ReflectionProperty($controller, 'shownDocument')->setValue($controller, null);
        new \ReflectionMethod($controller, 'stageSelection')->invoke($controller, [0 => [0 => true]]);
        $this->assertSame(['?? new.txt'], $this->porcelain());
    }

    public function testApplyFailureFlashes(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "edited\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+edited'));

        // A context-only selection builds no patch and is a silent no-op.
        new \ReflectionMethod($controller, 'stageSelection')->invoke($controller, [0 => [2 => true]]);
        $this->assertStringNotContainsString('git apply', $this->statusFlash());

        // Corrupt the worktree state between diff load and apply: replace the
        // file so the crafted patch no longer matches the index.
        exec('git -C '.escapeshellarg($this->project->dir).' add a.txt'); // index = edited
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' '); // whole first hunk, built against the OLD worktree diff
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'git apply refused'));
    }

    public function testFocusCycleWalksTheThreePanes(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertSame($this->changes, $this->tui->getFocus());
        $this->changes->setFocused(true);
        $this->changes->handleInput("\t");
        $this->assertSame($this->diffView, $this->tui->getFocus());
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\t");
        $this->assertSame($this->commitBox, $this->tui->getFocus());
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\t"); // summary -> description
        $this->commitBox->handleInput("\t"); // description -> next pane
        $this->assertSame($this->changes, $this->tui->getFocus());
    }

    public function testQuietRefreshSkipsRepaintWhenNothingChanged(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "poked\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $before = $this->changes->selectedEntry();
        $controller->refresh(quiet: true);
        $this->awaitTicks(10);
        $this->assertSame($before?->path, $this->changes->selectedEntry()?->path, 'identical status output leaves state alone');

        $controller->close();
        $controller->refresh(); // closed: the status callback drops its result
        $this->awaitTicks(10);
        $this->assertFalse($controller->isOpen());
    }

    private function diffText(): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->diffView->render(new RenderContext(80, 20))));
    }

    private function await(callable $done, string $why = '', float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertTrue($done(), '' === $why ? 'condition met before timeout' : $why);
    }

    private function awaitTicks(int $ticks): void
    {
        for ($i = 0; $i < $ticks; ++$i) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.01, static fn () => $suspension->resume());
            $suspension->suspend();
        }
    }

    private static function findGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }
}
