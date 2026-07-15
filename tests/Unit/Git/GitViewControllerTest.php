<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\Editor\BufferManager;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Git\GitInfo;
use Porta\Git\GitPanes;
use Porta\Git\GitViewController;
use Porta\Git\RefreshMode;
use Porta\Git\WhitespaceMode;
use Porta\I18n\Translator;
use Porta\Layout\DiffLayout;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Support\Clipboard;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\TempProject;
use Porta\Ui\EditorArea;
use Porta\Ui\Notifier;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    private HistoryListWidget $history;
    private GitTabsWidget $tabs;
    private MenuBarWidget $menuBar;
    private ContainerWidget $gitLeft;
    private ContainerWidget $main;
    private StatusBarWidget $statusBar;
    private int $committed = 0;
    private CodeEditorWidget $editor;
    private LayoutController $layoutController;

    private function controller(Presence $realRepo = Presence::With, int $historyPageSize = 200): GitViewController
    {
        if (Presence::With === $realRepo && null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        // .porta/ is ignored so the real LayoutController's pref writes never
        // dirty the porcelain assertions.
        $this->project = new TempProject(['a.txt' => "one\ntwo\n", '.gitignore' => ".porta/\n"]);
        if (Presence::With === $realRepo) {
            $d = escapeshellarg($this->project->dir);
            exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init");
        }
        $this->git = new GitInfo($this->project->dir);

        $this->changes = new ChangesListWidget(new Translator('en'));
        $this->diffView = new DiffViewWidget(new Translator('en'));
        $this->commitBox = new CommitBoxWidget(new Translator('en'));
        $this->history = new HistoryListWidget(new Translator('en'));
        $this->history->setId('git-history');
        $this->tabs = new GitTabsWidget(new Translator('en'));
        $this->tabs->setId('git-tabs');
        $this->menuBar = new MenuBarWidget();
        $this->menuBar->setId('menu');
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);
        $this->gitLeft = new ContainerWidget();
        $this->gitLeft->setId('git-left');
        $this->gitLeft->add($this->tabs)->add($this->changes)->add($this->commitBox);
        $gitArea = new ContainerWidget();
        $gitArea->setId('git-area');
        $gitArea->add($this->gitLeft)->add($this->diffView);
        $this->statusBar = new StatusBarWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $this->committed = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(GitCommitted::class, function (): void {
            ++$this->committed;
        });
        // The restored editor area holds a focusable editor so close() has a
        // real focus target (EditorArea resolves to it with no buffers open).
        $this->editor = new CodeEditorWidget();
        $this->editor->setId('editor');
        $editWidget->add($this->editor);
        $editorArea = new EditorArea(new BufferManager(), $this->editor, new ImageViewerWidget(), new HelpViewerWidget(), $this->tui);
        $t = new Translator('en');
        $this->layoutController = new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            new Renderer(new StyleSheet()),
            new FileTreeWidget($this->project->dir),
            new TerminalPanelWidget(),
            new ResultsPanelWidget(),
            new DebugPanelWidget(),
            new GitSidebar($this->tabs, $this->changes, $this->commitBox, $this->history),
            new SettingsNavWidget($t),
        );

        return new GitViewController(
            $this->git,
            $this->project->dir,
            new GitPanes($this->changes, $this->diffView, $this->commitBox, $this->history, $this->tabs),
            $this->main,
            $gitArea,
            $this->gitLeft,
            $this->menuBar,
            $this->tui,
            new Notifier($this->statusBar, $this->tui),
            $t,
            new class($editWidget) implements EditorAreaPanes {
                public function __construct(
                    private readonly ContainerWidget $editWidget,
                ) {}

                public function orderedEditorPanes(): array
                {
                    return [$this->editWidget];
                }
            },
            $editorArea,
            $dispatcher,
            $this->layoutController,
            new Clipboard($this->tui),
            new WorkspaceViews(),
            historyPageSize: $historyPageSize,
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
        $controller = $this->controller(realRepo: Presence::Without);
        $controller->open();

        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusFlash());
    }

    public function testToggleSwapsTheMainChildrenAndRestoresThem(): void
    {
        $controller = $this->controller();

        $this->menuBar->setMenus([new \Porta\Ui\Menu('File', [])]);
        $this->menuBar->open(0);
        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $this->assertSame(['git-area'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $this->assertFalse($this->menuBar->isOpen(), 'an open dropdown closes across the swap');
        $controller->open(); // idempotent
        $this->assertTrue($controller->isOpen());

        $controller->toggle();
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $this->assertSame($this->editor, $this->tui->getFocus(), 'close restores focus to the editor area');
        $controller->close(); // idempotent
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));

        $controller->toggle(); // second round trip stays stable
        $controller->toggle();
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
    }

    public function testFocusTargetFollowsTheActiveTab(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->assertSame($this->changes, $controller->focusTarget());

        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 2 === \count($this->gitLeft->all()));
        $this->assertSame($this->history, $controller->focusTarget());
    }

    public function testFocusNextAndPreviousWalkThePanesBothWays(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->tui->setFocus($this->changes);
        $controller->focusNext();
        $this->assertSame($this->diffView, $this->tui->getFocus(), 'changes → diff');
        $controller->focusNext();
        $this->assertSame($this->commitBox, $this->tui->getFocus(), 'diff → commit box');
        $controller->focusNext();
        $this->assertSame($this->changes, $this->tui->getFocus(), 'wraps back to the changes list');

        $controller->focusPrevious();
        $this->assertSame($this->commitBox, $this->tui->getFocus(), 'reverse: changes → commit box');
        $controller->focusPrevious();
        $this->assertSame($this->diffView, $this->tui->getFocus(), 'commit box → diff');

        // Focus outside the git panes enters at the changes list either way.
        $this->tui->setFocus($this->menuBar);
        $controller->focusPrevious();
        $this->assertSame($this->changes, $this->tui->getFocus());
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

    public function testAmendPrefillsTheLastMessageAndRewritesTheTip(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        // alt+m arms amend and prefills the last commit's message.
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\x1bm");
        $this->await(fn (): bool => 'init' === $this->commitBox->message());
        $this->assertTrue($this->commitBox->commitEnabled(), 'amend needs nothing staged');

        // Reword and commit: the tip is rewritten, not extended.
        foreach (str_split(' reworded') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u");
        $this->await(fn (): bool => 1 === $this->committed);
        exec('git -C '.escapeshellarg($this->project->dir).' log --format=%s 2>/dev/null', $subjects);
        $this->assertSame(['init reworded'], $subjects);
        $this->assertFalse($this->commitBox->isAmend(), 'the box clears (and disarms) after committing');

        // Toggling amend off wipes the prefill.
        $this->commitBox->handleInput("\x1bm");
        $this->await(fn (): bool => 'init reworded' === $this->commitBox->message());
        $this->commitBox->handleInput("\x1bm");
        $this->assertSame('', $this->commitBox->message());
    }

    public function testStalePrefillIsDroppedWhenAmendDisarmsFirst(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        // Fire the prefill, then disarm before git answers: the late message
        // must not repopulate a box the user already toggled back off.
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\x1bm");
        $this->commitBox->handleInput("\x1bm");
        $this->awaitTicks(15);
        $this->assertSame('', $this->commitBox->message());
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
        $this->await($controller->isOpen(...));

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

        // refresh() and the selection change race same-path diff reloads; a
        // late one would reset the cursor after we position it. Wait until the
        // rendered diff and the cursor hold still before driving the cursor.
        $snapshot = '';
        $stable = 0;
        $this->await(
            function () use (&$snapshot, &$stable): bool {
                $now = $this->diffText().new \ReflectionProperty($this->diffView, 'cursor')->getValue($this->diffView);
                $stable = $now === $snapshot ? $stable + 1 : 0;
                $snapshot = $now;

                return $stable >= 8;
            },
            'diff reloads settled',
            timeout: 5.0,
        );

        // Page up pins the cursor on the hunk header before unstaging the WHOLE hunk.
        $this->diffView->handleInput("\x1b[5~");
        $this->diffView->handleInput(' ');
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

    public function testDiffPrefsApplyOnOpenAndPersistOnToggle(): void
    {
        $controller = $this->controller();
        $this->layoutController->setGitDiffPrefs(DiffLayout::Split, WhitespaceMode::Shown);
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->assertTrue($this->diffView->isSplit(), 'the persisted split preference applies on open');

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('s'); // back to unified: persists
        $this->assertSame(['layout' => DiffLayout::Unified, 'whitespace' => WhitespaceMode::Shown], $this->layoutController->gitDiffPrefs(), 'the unified toggle persisted');
    }

    public function testCtrlCCopiesTheSelectedDiffLines(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one CHANGED\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+one CHANGED'));

        // Range over the two deletions, then copy: plain code, no markers.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two
        $this->diffView->handleInput("\x03");

        $this->assertStringContainsString("\x1b]52;c;".base64_encode("one\ntwo\n"), $this->tui->getTerminal()->getOutput(), 'the diff selection reached the OSC 52 clipboard');
        $this->assertStringContainsString('Copied 2 lines', $this->statusFlash());
    }

    public function testWhitespaceToggleHidesWhitespaceHunksAndBlocksStaging(): void
    {
        $controller = $this->controller();
        // An indent-only change on 'one' plus a real change on 'two'.
        $this->project->write('a.txt', "  one\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !str_contains($this->diffText(), '+  one'), 'the indent-only pair disappears');
        $this->assertStringContainsString('+two CHANGED', $this->diffText(), 'real changes survive');
        $this->assertStringContainsString('Whitespace changes hidden', $this->statusFlash());
        $this->assertSame(['layout' => DiffLayout::Unified, 'whitespace' => WhitespaceMode::Hidden], $this->layoutController->gitDiffPrefs(), 'the preference persisted');

        // Staging is blocked while whitespace is hidden: a -w diff cannot
        // produce an appliable patch.
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => str_contains($this->statusFlash(), 'Show whitespace changes to stage lines'));
        $this->assertSame([' M a.txt'], $this->porcelain(), 'nothing was staged');

        $this->diffView->handleInput('w'); // back on
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));
        $this->assertStringContainsString('Whitespace changes shown', $this->statusFlash());
    }

    public function testWhitespaceOnlyChangeShowsThePlaceholder(): void
    {
        $controller = $this->controller();
        $this->layoutController->setGitDiffPrefs(DiffLayout::Unified, WhitespaceMode::Hidden); // persisted from a previous session
        $this->project->write('a.txt', "  one\ntwo\n"); // reindent only
        $controller->open();

        $this->await(fn (): bool => str_contains($this->diffText(), 'No changes (whitespace hidden)'));
    }

    public function testWhitespaceToggleAppliesToHistoryDiffs(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "  one\ntwo CHANGED\n");
        $d = escapeshellarg($this->project->dir);
        exec("git -C {$d} add -A && git -C {$d} commit -qm reindent");
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History: the reindent commit auto-loads a.txt
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !str_contains($this->diffText(), '+  one'), 'the history patch reloads with -w');
        $this->assertStringContainsString('+two CHANGED', $this->diffText());
    }

    public function testContextCycleReloadsTheDiffWithMoreContextAndWrapsAround(): void
    {
        $controller = $this->controller();
        $d = escapeshellarg($this->project->dir);
        $body = implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, end: 30)))."\n";
        $this->project->write('big.txt', $body);
        exec("git -C {$d} add -A && git -C {$d} commit -qm big");
        $this->project->write('big.txt', str_replace('line 15', replace: 'line 15 EDITED', subject: $body));
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+line 15 EDITED'));
        $this->assertStringNotContainsString('line 5 ', $this->diffText(), 'default context stops three lines out');

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->await(fn (): bool => str_contains($this->diffText(), 'line 5 '), '-U10 pulls in farther context');
        $this->assertStringContainsString('Diff context: ±10 lines', $this->statusFlash());
        $this->assertStringNotContainsString('line 1 ', $this->diffText());

        $this->diffView->handleInput('c');
        $this->await(fn (): bool => str_contains($this->diffText(), 'line 1 '), 'whole-file context starts at line 1');
        $this->assertStringContainsString('Diff context: whole file', $this->statusFlash());

        $this->diffView->handleInput('c'); // wraps back to the default
        $this->await(fn (): bool => !str_contains($this->diffText(), 'line 5 '));
        $this->assertStringContainsString('Diff context: default', $this->statusFlash());
    }

    public function testContextCycleGuardsUntrackedAndMissingDiffs(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        // Untracked diffs are already whole-file: flash, no reload.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->assertStringContainsString('Untracked files always show the whole file', $this->statusFlash());

        // No shown entry at all: a silent no-op.
        new \ReflectionProperty($controller, 'shownEntry')->setValue($controller, null);
        new \ReflectionMethod($controller, 'cycleContext')->invoke($controller);
        $this->assertStringContainsString('Untracked files', $this->statusFlash(), 'no new flash replaced the old one');
    }

    public function testHistoryModeBrowsesCommitsAndShowsReadOnlyDiffs(): void
    {
        $controller = $this->controller();
        // A second commit so the log has two entries.
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        exec('git -C '.escapeshellarg($this->project->dir).' add -A && git -C '.escapeshellarg($this->project->dir).' commit -qm second');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // switch to History
        $this->await(fn (): bool => null !== $this->history->selectedCommit());
        $this->assertSame(['git-tabs', 'git-history'], array_map(static fn ($w) => $w->getId(), $this->gitLeft->all()), 'the left pane swapped to the history list (tabs stay on top)');
        $this->assertSame('second', $this->history->selectedCommit()?->subject);
        $this->assertTrue($this->diffView->isReadOnly());

        // The files of the newest commit load, and the FIRST file's patch
        // shows immediately — no keyboard step needed to see a diff.
        $this->await(fn (): bool => str_contains($this->historyText(), 'M a.txt'));
        $this->await(fn (): bool => str_contains($this->diffText(), '+three'), "the first file's patch auto-loads on commit selection");

        // Keyboard file browsing still refires loads.
        $this->history->setFocused(true);
        $this->history->handleInput("\r"); // into the file list
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[A");
        $this->await(fn (): bool => str_contains($this->diffText(), '+three'), 'the commit file patch renders');

        // Staged keys are inert in read-only mode.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' ');
        $this->assertSame([], $this->porcelain(), 'nothing staged from history');

        // Back to Changes: left pane restored, diff editable again.
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->assertFalse($this->diffView->isReadOnly());
    }

    public function testEmptyCommitShowsAPlaceholderInsteadOfAutoLoading(): void
    {
        $controller = $this->controller();
        exec('git -C '.escapeshellarg($this->project->dir).' commit -q --allow-empty -m hollow');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History: the empty commit is newest
        $this->await(fn (): bool => 'hollow' === $this->history->selectedCommit()?->subject);
        $this->await(fn (): bool => str_contains($this->diffText(), 'No files in this commit'));
    }

    public function testReopeningAfterHistoryModeLandsOnChanges(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 'git-history' === ($this->gitLeft->all()[1] ?? null)?->getId());

        $controller->close();
        $controller->open(); // must reset to the Changes tab
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->assertFalse($this->diffView->isReadOnly());

        // History-mode focus cycle: history <-> diff only.
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 2 === \count($this->gitLeft->all()));
        $this->history->setFocused(true);
        $this->history->handleInput("\t");
        $this->assertSame($this->diffView, $this->tui->getFocus());
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\t");
        $this->assertSame($this->history, $this->tui->getFocus());
    }

    public function testStaleHistoryCallbacksAreDropped(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        new \ReflectionMethod($controller, 'switchMode')->invoke($controller, 'history'); // same mode: no-op
        $this->await(fn (): bool => null !== $this->history->selectedCommit());

        // A log request whose view closes before it resolves is dropped.
        $controller->close();
        $controller->open(); // back on Changes
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // fires git log...
        $controller->close(); // ...and closes before it lands
        $this->awaitTicks(15);
        $this->assertFalse($controller->isOpen());
        $controller->open();

        $commit = $this->history->selectedCommit();
        // Fire the loaders, then flip back to Changes before they resolve:
        // both callbacks must drop their results.
        new \ReflectionMethod($controller, 'loadCommitFiles')->invoke($controller, $commit);
        new \ReflectionMethod($controller, 'loadCommitFileDiff')->invoke($controller, $commit, 'a.txt');
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->awaitTicks(15);
        $this->assertFalse($this->diffView->isReadOnly(), 'back on Changes, untouched by stale history data');
    }

    private function historyText(): string
    {
        return implode("\n", array_map(\Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(...), $this->history->render(new RenderContext(44, 20))));
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
        $controller->refresh(RefreshMode::Quiet);
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
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertTrue($done(), '' === $why ? 'condition met before timeout' : $why);
    }

    private function awaitTicks(int $ticks): void
    {
        for ($i = 0; $i < $ticks; ++$i) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.01, $suspension->resume(...));
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

    public function testHistoryPagesInOlderCommitsAtTheListBottom(): void
    {
        $controller = $this->controller(historyPageSize: 2);
        $d = escapeshellarg($this->project->dir);
        foreach (['second', 'third', 'fourth', 'fifth'] as $n) {
            exec("git -C {$d} commit -q --allow-empty -m {$n}");
        }
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History tab
        $this->await(fn (): bool => 2 === $this->history->commitCount(), 'page 0 loads');
        $this->assertSame('fifth', $this->history->selectedCommit()?->subject);

        // Walking to the bottom pulls page 1; selection stays put.
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => 4 === $this->history->commitCount(), 'page 1 appended');
        $this->assertSame('fourth', $this->history->selectedCommit()?->subject);

        // And the final partial page ends the paging.
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => 5 === $this->history->commitCount(), 'the last page appended (partial: paging ends)');
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[B");
        $this->awaitTicks(5);
        $this->assertSame(5, $this->history->commitCount(), 'no further loads past the end');
    }

    public function testRemoteCompletedReloadsHistoryPageZero(): void
    {
        $controller = $this->controller(historyPageSize: 2);
        $d = escapeshellarg($this->project->dir);
        exec("git -C {$d} commit -q --allow-empty -m second");
        $controller->open();
        $this->await($controller->isOpen(...));

        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true)); // changes mode: a no-op
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History tab
        $this->await(fn (): bool => 2 === $this->history->commitCount());

        // A commit lands "remotely" (same repo here); the completed event reloads page 0.
        exec("git -C {$d} commit -q --allow-empty -m fromremote");
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(fn (): bool => 'fromremote' === ($this->history->selectedCommit()->subject ?? ''), 'the newest commit appears at the top');
    }

    public function testHistoryCallbacksLandingAfterCloseAreIgnored(): void
    {
        $controller = $this->controller(historyPageSize: 1);
        $d = escapeshellarg($this->project->dir);
        exec("git -C {$d} commit -q --allow-empty -m second");
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 1 === $this->history->commitCount());

        // Fire a reload and a load-more, then close before either can land:
        // both callbacks must hit the open/mode guard and change nothing.
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B"); // bottom row: arms load-more
        $controller->close();
        $this->awaitTicks(10);
        $this->assertSame(1, $this->history->commitCount(), 'no page landed after close');
    }

    public function testFileHistoryFiltersAndClearsOnTabSwitch(): void
    {
        $controller = $this->controller(historyPageSize: 1);
        $d = escapeshellarg($this->project->dir);
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        exec('git -C '.$d.' add -A && git -C '.$d.' commit -qm touch-a');
        $this->project->write('b.txt', "new\n");
        exec('git -C '.$d.' add -A && git -C '.$d.' commit -qm touch-b');

        $controller->openFileHistory($this->project->path('a.txt'));
        $this->await(fn (): bool => 'touch-a' === $this->history->selectedCommit()?->subject);
        $this->assertStringContainsString('History — a.txt', $this->historyText(), 'the header names the filtered file');
        $this->assertStringNotContainsString('touch-b', $this->historyText(), 'commits not touching a.txt are hidden');

        // Paging keeps the filter: the next page is "init", not "touch-b".
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B"); // move past the last loaded commit
        $this->await(fn (): bool => str_contains($this->historyText(), 'init'));
        $this->assertStringNotContainsString('touch-b', $this->historyText());

        // Tabbing to Changes drops the filter; History reopens repo-wide.
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => str_contains($this->historyText(), 'touch-b'), 'the filter is gone');
        $this->assertStringNotContainsString('History — a.txt', $this->historyText());
    }

    public function testFileHistoryWhileAlreadyOnHistoryReloadsInPlace(): void
    {
        $controller = $this->controller();
        $d = escapeshellarg($this->project->dir);
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        exec('git -C '.$d.' add -A && git -C '.$d.' commit -qm touch-a');

        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History, repo-wide
        $this->await(fn (): bool => null !== $this->history->selectedCommit());

        $controller->openFileHistory($this->project->path('a.txt'));
        $this->await(fn (): bool => str_contains($this->historyText(), 'History — a.txt'));
        $this->assertSame('touch-a', $this->history->selectedCommit()?->subject);
    }

    public function testFileHistoryOutsideARepositoryFlashesAndStaysClosed(): void
    {
        $controller = $this->controller(realRepo: Presence::Without);
        $controller->openFileHistory($this->project->path('a.txt'));
        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusFlash());
    }
}
