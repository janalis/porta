<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Git\GitCommands;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Revolt\EventLoop;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Drives every {@see GitCommands} action against a real git repository. These
 * used to be covered only through the full-Ide E2e flows
 * ({@see \Porta\Tests\E2e\IdeEdgeCasesTest}), which fork real git and race the
 * await window under machine load — so the coverage of GitCommands' async
 * callback bodies came and went with the load. This exercises the same
 * callbacks from a focused fixture: the repo is built synchronously with
 * {@see Process} (no racing there), and each async git op is pumped to
 * completion on the loop before the assertion.
 */
final class GitCommandsTest extends TestCase
{
    private TempProject $project;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;
    private Tui $tui;

    protected function setUp(): void
    {
        if (null === new \Symfony\Component\Process\ExecutableFinder()->find('git')) {
            $this->markTestSkipped('git not available');
        }
    }

    // --- Simple async actions -------------------------------------------------

    public function testStageCurrentStagesTheOpenFileAndFlashes(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ntwo\n"); // modify the tracked file
        $this->openInEditor('a.txt', "one\ntwo\n");

        $commands->stageCurrent();
        $this->pump();

        $this->assertStringContainsString('Staged a.txt', $this->flash());
        // The file was tracked at init, so staging its change shows as staged-modified (M in the index column).
        $this->assertStringContainsString('M  a.txt', $this->porcelain(), 'the change is staged in the index');
    }

    public function testStageCurrentWithNoOpenFileReportsUnavailable(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        // No file open in the editor → getFilePath() is null.
        $commands->stageCurrent();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testCommitPromptsThenCommitsTheStagedIndex(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ntwo\n");
        $this->git('add', '-A');

        $commands->commit();
        $this->type('a fresh commit');
        $this->key("\r"); // submit the prompt
        $this->pump();

        $this->assertStringContainsString('Committed: a fresh commit', $this->flash());
        $this->assertStringContainsString('a fresh commit', $this->git('log', '-1', '--pretty=%s'));
    }

    public function testCommitWithAnEmptyMessageDoesNothing(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = trim($this->git('rev-parse', 'HEAD'));

        $commands->commit();
        $this->key("\r"); // submit with an empty message
        $this->pump();

        $this->assertSame($before, trim($this->git('rev-parse', 'HEAD')), 'no commit was made');
    }

    public function testCommitWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->commit();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testBlameLineReportsTheCommitThatTouchedIt(): void
    {
        $commands = $this->commands(['a.txt' => "one\ntwo\n"]);
        $this->openInEditor('a.txt', "one\ntwo\n");

        $commands->blameLine();
        $this->pump();

        // The init commit authored both lines: sha + author + summary.
        $this->assertStringContainsString('init', $this->flash());
    }

    public function testBlameLineWithNoOpenFileReportsUnavailable(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->blameLine();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testBlameLineReportsWhenTheLineHasNoCommittedBlame(): void
    {
        // The committed file is one line; the editor's cursor is on a line
        // beyond it, so `git blame -L` finds nothing to attribute.
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->openInEditor('a.txt', "one\ntwo\nthree\nfour\nfive\nsix\n");
        $this->editor->getDocument()->setCursor(5, 0);

        $commands->blameLine();
        $this->pump();

        $this->assertStringContainsString('No blame for this line', $this->flash());
    }

    /**
     * Every action refuses outside a repository, through the same guard.
     *
     * @return iterable<string, array{callable(GitCommands): void}>
     */
    public static function outsideARepo(): iterable
    {
        yield 'stage' => [static fn (GitCommands $c) => $c->stageCurrent()];
        yield 'blame' => [static fn (GitCommands $c) => $c->blameLine()];
        yield 'stash' => [static fn (GitCommands $c) => $c->stash()];
        yield 'stashPop' => [static fn (GitCommands $c) => $c->stashPop()];
        yield 'stashes' => [static fn (GitCommands $c) => $c->stashes()];
        yield 'undo' => [static fn (GitCommands $c) => $c->undoLastCommit()];
    }

    /** @param callable(GitCommands): void $action */
    #[\PHPUnit\Framework\Attributes\DataProvider('outsideARepo')]
    public function testActionsRefuseOutsideARepository(callable $action): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $action($commands);
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testStashPushesTheWorkingTreeThenPopsItBack(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ndirty\n"); // a change to stash

        // Pump on the on-disk effect, not the rendered flash: git reverts the
        // tree before the process exits, so polling the file content itself is
        // immune to a flash that renders a beat late under load.
        $commands->stash();
        $this->pump(fn (): bool => !str_contains($this->readFile('a.txt'), 'dirty'));
        $this->assertStringNotContainsString('dirty', $this->readFile('a.txt'), 'the worktree change was stashed away');

        $commands->stashPop();
        $this->pump(fn (): bool => str_contains($this->readFile('a.txt'), 'dirty'));
        $this->assertStringContainsString('dirty', $this->readFile('a.txt'), 'the stash came back');
    }

    public function testStashesFlashesWhenThereAreNone(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->stashes();
        $this->pump();

        $this->assertStringContainsString('No stashes', $this->flash());
    }

    public function testStashesPicksAStashAndPopsIt(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nstashed\n");
        $this->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens(); // the stash list
        $this->key("\r"); // pick the only stash
        $this->key("\x1b[B"); // move to "Pop"
        $this->key("\r"); // choose Pop
        $this->pump();

        $this->assertStringContainsString('stashed', $this->readFile('a.txt'), 'the stash was popped back');
    }

    public function testStashesAppliesAStashKeepingItInTheList(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\napplied\n");
        $this->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\r"); // "Apply" is the first action
        $this->pump();

        $this->assertStringContainsString('applied', $this->readFile('a.txt'), 'the stash was applied to the tree');
        $this->assertStringContainsString('wip', $this->git('stash', 'list'), 'apply keeps the stash');
    }

    public function testStashesDropsAStash(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ndropme\n");
        $this->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\x1b[B"); // Apply → Pop
        $this->key("\x1b[B"); // Pop → Drop
        $this->key("\r"); // choose Drop
        $this->pump();

        $this->assertStringContainsString('Dropped', $this->flash());
        $this->assertStringNotContainsString('wip', $this->git('stash', 'list'), 'the stash is gone');
    }

    public function testStashesActionCanBeCancelled(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nkeep\n");
        $this->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\x1b[B"); // Apply → Pop
        $this->key("\x1b[B"); // Pop → Drop
        $this->key("\x1b[B"); // Drop → Cancel
        $this->key("\r"); // choose Cancel — a no-op
        $this->pump(fn (): bool => !$this->editor->isOverlayOpen());

        $this->assertStringContainsString('wip', $this->git('stash', 'list'), 'the stash is untouched');
    }

    public function testUndoLastCommitSoftResetsAndFlashes(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nsecond\n");
        $this->git('commit', '-am', 'second');
        $tip = trim($this->git('rev-parse', 'HEAD'));

        $commands->undoLastCommit();
        $this->pump();

        $this->assertStringContainsString('Last commit undone', $this->flash());
        $this->assertNotSame($tip, trim($this->git('rev-parse', 'HEAD')), 'HEAD moved back one commit');
    }

    public function testFileHistoryWithNoOpenFileReportsUnavailable(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->fileHistory();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testFileHistoryWithAnOpenFileIsAcceptedWhenNoGitViewIsAttached(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->openInEditor('a.txt', "one\n");

        // WorkspaceViews has no git controller here, so this is a no-op — but
        // it must reach that branch without flashing "unavailable".
        $commands->fileHistory();
        $this->pump();

        $this->assertStringNotContainsString('Git not available', $this->flash());
    }

    // --- The branch picker ----------------------------------------------------

    public function testBranchesCreatesAndSwitchesToANewBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens(); // the branch list
        $this->key("\r"); // "+ Create new branch…" leads the list
        $this->type('feature');
        $this->key("\r"); // submit the name
        $this->pump();

        $this->assertStringContainsString('Created and switched to feature', $this->flash());
        $this->assertSame('feature', trim($this->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testNewBranchOpensTheCreatePromptDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->newBranch(); // the status-bar branch menu's "New Branch…"
        $this->type('feature');
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('Created and switched to feature', $this->flash());
        $this->assertSame('feature', trim($this->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testDeleteBranchOpensThePickerDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->git('branch', 'stale');

        $commands->deleteBranch(); // the status-bar branch menu's "Delete Branch…"
        $this->overlayOpens(); // the deletable list
        $this->key("\r"); // pick "stale" (the only deletable)
        $this->key("\r"); // confirm "Delete"
        $this->pump();

        $this->assertStringContainsString('Deleted branch stale', $this->flash());
    }

    public function testBranchActionsReportUnavailableOutsideARepo(): void
    {
        $this->commandsOutsideRepo(['a.txt' => "one\n"])->newBranch();
        $this->assertStringContainsString('Git not available', $this->flash());

        $this->commandsOutsideRepo(['a.txt' => "one\n"])->deleteBranch();
        $this->assertStringContainsString('Git not available', $this->flash());
    }

    public function testBranchesSwitchesToAnExistingBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->git('branch', 'other');

        $commands->branches();
        $this->overlayOpens();
        // Rows: [+ Create], [− Delete], main (current), other. Move to "other".
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('Switched to other', $this->flash());
        $this->assertSame('other', trim($this->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testBranchesDeletesABranchThroughTheConfirm(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->git('branch', 'stale');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // move to "− Delete branch…"
        $this->key("\r");
        $this->overlayOpens(); // the deletable list
        $this->key("\r"); // pick "stale" (the only deletable)
        $this->key("\r"); // confirm "Delete"
        $this->pump();

        $this->assertStringContainsString('Deleted branch stale', $this->flash());
        $this->assertStringNotContainsString('stale', $this->git('branch'));
    }

    public function testBranchesDeleteCanBeCancelledAtTheConfirm(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->git('branch', 'keep');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // "− Delete branch…"
        $this->key("\r");
        $this->overlayOpens();
        $this->key("\r"); // pick "keep"
        $this->key("\x1b[B"); // Delete → Cancel
        $this->key("\r"); // choose Cancel
        $this->pump(fn (): bool => !$this->editor->isOverlayOpen());

        $this->assertStringContainsString('keep', $this->git('branch'), 'the branch survived the cancel');
    }

    public function testBranchesCreateIgnoresAnEmptyName(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = $this->git('branch');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\r"); // "+ Create new branch…"
        $this->key("\r"); // submit an empty name
        $this->pump(fn (): bool => !$this->editor->isOverlayOpen());

        $this->assertSame($before, $this->git('branch'), 'no branch was created');
    }

    public function testBranchesChecksOutARemoteBranchAsTracking(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        // A bare remote carrying a branch this repo does not have locally.
        $remote = new TempProject([]);
        $this->proc($remote->dir, 'git', 'init', '--bare', '-q');
        $this->git('remote', 'add', 'origin', $remote->dir);
        $this->git('push', '-q', 'origin', 'HEAD:refs/heads/shipped');
        $this->git('fetch', '-q', 'origin');

        $commands->branches();
        $this->overlayOpens();
        // Rows: [+ Create], [− Delete], main (the one local), origin/shipped.
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('shipped', $this->flash());
        $this->assertSame('shipped', trim($this->git('rev-parse', '--abbrev-ref', 'HEAD')));
        $remote->__destruct();
    }

    public function testBranchesDeleteReportsWhenThereIsNothingToDelete(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // "− Delete branch…"
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('No other local branches to delete', $this->flash());
    }

    public function testBranchesWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->branches();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->flash());
    }

    // --- Fixture --------------------------------------------------------------

    /** A GitCommands over a fresh repo with one initial commit. @param array<string, string> $files */
    private function commands(array $files): GitCommands
    {
        $this->project = new TempProject($files);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 't@t');
        $this->git('config', 'user.name', 'Test');
        $this->git('add', '-A');
        $this->git('commit', '-qm', 'init');

        return $this->build();
    }

    /** A GitCommands over a directory that is NOT a git repo. @param array<string, string> $files */
    private function commandsOutsideRepo(array $files): GitCommands
    {
        $this->project = new TempProject($files);

        return $this->build();
    }

    private function build(): GitCommands
    {
        $git = new GitInfo($this->project->dir);
        $this->editor = new CodeEditorWidget();
        $this->statusBar = new StatusBarWidget();
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $translator = new Translator('en');
        $refresher = new GitRefresher($git, new \Porta\Editor\BufferManager(), $this->editor, $this->statusBar, $this->tui);

        return new GitCommands(
            $git,
            $this->editor,
            new Notifier($this->statusBar, $this->tui),
            $this->tui,
            $translator,
            $refresher,
            $this->project->dir,
            new WorkspaceViews(),
        );
    }

    /** Run git synchronously via Symfony Process; returns stdout. */
    private function git(string ...$args): string
    {
        return $this->proc($this->project->dir, 'git', '-C', $this->project->dir, ...$args);
    }

    /** Run any command synchronously via Symfony Process; returns stdout. */
    private function proc(string $cwd, string ...$command): string
    {
        $process = new Process($command, $cwd);
        $process->run();

        return $process->getOutput();
    }

    private function porcelain(): string
    {
        return $this->git('status', '--porcelain');
    }

    private function write(string $rel, string $contents): void
    {
        file_put_contents($this->project->dir.'/'.$rel, $contents);
    }

    private function readFile(string $rel): string
    {
        // Guard the read: git replaces the file by atomic rename during a stash,
        // and a poll landing in that instant would otherwise warn on the
        // momentarily-absent path.
        $path = $this->project->dir.'/'.$rel;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function openInEditor(string $rel, string $contents): void
    {
        $this->editor->showDocument($this->project->dir.'/'.$rel, new Document($contents));
    }

    /** Type printable characters into the open prompt. */
    private function type(string $text): void
    {
        foreach (mb_str_split($text) as $ch) {
            $this->key($ch);
        }
    }

    /** Feed one key event to the editor (routed to the open prompt/overlay). */
    private function key(string $data): void
    {
        $this->editor->handleInput($data);
    }

    private function flash(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    /**
     * Pump the event loop until $until holds, then drain a few follow-up
     * turns. With no predicate it just waits for a flash to land — the signal
     * the simple actions end on; the overlay flows wait for the list to open.
     */
    private function pump(?callable $until = null, float $timeout = 5.0): void
    {
        $until ??= fn (): bool => '' !== trim($this->flash());
        $elapsed = 0.0;
        while (!$until() && $elapsed < $timeout) {
            $this->tick();
            $elapsed += 0.01;
        }
        // Drain any callbacks the resolved op queued behind it (refreshers).
        for ($i = 0; $i < 3; ++$i) {
            $this->tick();
        }
    }

    /** Waits for the editor's list overlay (branch/stash pickers). */
    private function overlayOpens(): void
    {
        $this->pump(fn (): bool => $this->editor->isOverlayOpen());
    }

    private function tick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, $suspension->resume(...));
        $suspension->suspend();
    }
}
