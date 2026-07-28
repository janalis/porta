<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Event\GitRemoteCompleted;
use Porta\Git\GitRemote;
use Porta\Git\GitRemoteCommands;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\WorkspaceViews;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class GitRemoteCommandsTest extends TestCase
{
    private StatusBarWidget $status;
    private CodeEditorWidget $editor;

    /** @var list<GitRemoteCompleted> */
    private array $events = [];

    private function await(callable $done, float $timeout = 5.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertTrue($done(), 'condition met before timeout');
    }

    private function commands(GitRemote $remote): GitRemoteCommands
    {
        $this->status = new StatusBarWidget();
        $this->events = [];
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(GitRemoteCompleted::class, function (GitRemoteCompleted $event): void {
            $this->events[] = $event;
        });

        return new GitRemoteCommands($remote, new Notifier($this->status, $tui), new Translator('en'), $dispatcher, $this->editor = new CodeEditorWidget(), new WorkspaceViews(), $tui);
    }

    private function flash(): string
    {
        return AnsiUtils::stripAnsiCodes(implode(' ', $this->status->render(new RenderContext(100, 1))));
    }

    /** A repo whose origin is itself — every remote operation completes fast. */
    private function selfRemoteRepo(): GitRemote
    {
        if (null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $project = new TempProject(['a.txt' => "x\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->git('remote', 'add', 'origin', $project->dir);
        $this->project = $project; // keep the TempProject alive through the async run

        return new GitRemote($project->dir);
    }

    private TempProject $project;

    public function testFetchFlashesStartThenSuccessAndDispatches(): void
    {
        $commands = $this->commands($this->selfRemoteRepo());

        $commands->fetch();
        $this->assertStringContainsString('Fetching…', $this->flash(), 'the start flash is up while the operation runs');

        $this->await(fn (): bool => [] !== $this->events);
        $this->assertStringContainsString('Fetched', $this->flash());
        $this->assertSame('fetch', $this->events[0]->operation);
        $this->assertTrue($this->events[0]->ok);
    }

    public function testFailureFlashesGitsFirstErrorLine(): void
    {
        $remote = $this->selfRemoteRepo();
        $this->project->git('remote', 'set-url', 'origin', '/nonexistent-origin');
        $commands = $this->commands($remote);

        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);

        $this->assertStringContainsString('Pull failed:', $this->flash());
        $this->assertFalse($this->events[0]->ok);
        $this->assertSame('pull', $this->events[0]->operation);
    }

    public function testBusyRefusalAndRunOperationRouting(): void
    {
        $remote = $this->selfRemoteRepo();
        $commands = $this->commands($remote);

        $commands->runOperation('push');
        $this->assertStringContainsString('Pushing…', $this->flash(), 'runOperation routed to push');
        $commands->runOperation('fetch');
        $this->assertStringContainsString('already running', $this->flash(), 'a second operation is refused while one runs');
        $commands->runOperation('pull'); // routed, then refused by the busy guard
        $commands->runOperation('unknown'); // silently ignored

        $this->await(fn (): bool => [] !== $this->events);
        $this->assertCount(1, $this->events, 'only the first operation completed');
    }

    public function testPushSetUpstreamReportsAsAPush(): void
    {
        $commands = $this->commands($this->selfRemoteRepo());

        $commands->pushSetUpstream();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertSame('push', $this->events[0]->operation);
    }

    public function testUnavailableRepositoryFlashesInsteadOfRunning(): void
    {
        if (null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $plain = new TempProject();
        $commands = $this->commands(new GitRemote($plain->dir));

        $commands->fetch();
        $this->assertStringContainsString('Not a git repository', $this->flash());
        $this->assertSame([], $this->events, 'nothing ran, nothing dispatched');
    }

    /** A diverged bare+clone pair whose next plain pull must fail --ff-only. */
    private function divergedRepo(): GitRemote
    {
        if (null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $bare = new TempProject();
        $other = new TempProject();
        $mine = new TempProject();
        $this->fixtures = [$bare, $other, $mine];
        $bare->git('init', '-q', '--bare', '.');
        foreach ([$other, $mine] as $clone) {
            $clone->git('clone', '-q', $bare->dir, '.');
            $clone->git('config', 'user.email', 't@t');
            $clone->git('config', 'user.name', 't');
            $clone->git('config', 'commit.gpgsign', 'false');
            $clone->git('config', 'push.autoSetupRemote', 'false');
            $clone->git('config', 'push.default', 'simple');
        }
        file_put_contents($other->path('a.txt'), data: "one\n");
        $other->git('add', '-A');
        $other->git('commit', '-q', '-m', 'init');
        $other->git('push', '-q', '-u', 'origin', 'HEAD');
        $mine->git('pull', '-q', 'origin');
        $mine->git('branch', '-q', '--set-upstream-to', 'origin/'.$mine->git('rev-parse', '--abbrev-ref', 'HEAD'));
        // Diverge both sides on different files so a rebase/merge is clean.
        file_put_contents($other->path('remote.txt'), data: "remote\n");
        $other->git('add', '-A');
        $other->git('commit', '-q', '-m', 'remote');
        $other->git('push', '-q', 'origin', 'HEAD');
        file_put_contents($mine->path('local.txt'), data: "local\n");
        $mine->git('add', '-A');
        $mine->git('commit', '-q', '-m', 'local');
        $this->mine = $mine;

        return new GitRemote($mine->dir);
    }

    /** @var list<TempProject> */
    private array $fixtures = [];
    private TempProject $mine;

    public function testDivergedPullOffersRebaseAndRebasing(): void
    {
        $commands = $this->commands($this->divergedRepo());

        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertTrue($this->editor->isOverlayOpen(), 'the diverged pull opened the choice overlay');

        // Pick "Rebase" (the first row).
        $this->editor->handleInput("\r");
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->assertTrue($this->events[1]->ok, 'the rebase pull succeeded');
        $this->assertStringContainsString('Pulled', $this->flash());
        $this->assertFileExists($this->mine->path('remote.txt'));
    }

    public function testDivergedPullCancelThenMerge(): void
    {
        $commands = $this->commands($this->divergedRepo());

        // Round 1: pick "Cancel" (the third row) — nothing runs.
        $commands->pull();
        $this->await(fn (): bool => [] !== $this->events);
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r");
        $this->assertFalse($this->editor->isOverlayOpen());
        $this->assertCount(1, $this->events, 'cancelling the choice starts no second operation');

        // Round 2: pick "Merge" (the second row).
        $commands->pull();
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r");
        $this->await(fn (): bool => \count($this->events) >= 3);
        $this->assertTrue($this->events[2]->ok, 'the merge pull succeeded');
        $subject = $this->mine->git('log', '-1', '--format=%s');
        $this->assertStringContainsString('Merge', $subject);
    }

    public function testNonUpstreamPushFailureKeepsTheGenericFlash(): void
    {
        // A diverged push is rejected for a non-upstream reason: the
        // interceptor declines and the generic failure flash appears.
        $commands = $this->commands($this->divergedRepo());

        $commands->push();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertFalse($this->editor->isOverlayOpen(), 'a rejected push is not an upstream offer');
        $this->assertStringContainsString('Push failed:', $this->flash());
    }

    public function testPushWithoutUpstreamOffersSetUpstream(): void
    {
        $remote = $this->divergedRepo();
        $this->mine->git('checkout', '-q', '-b', 'feature');
        $commands = $this->commands($remote);

        $commands->push();
        $this->await(fn (): bool => [] !== $this->events);
        $this->assertFalse($this->events[0]->ok);
        $this->assertTrue($this->editor->isOverlayOpen(), 'the missing-upstream push opened the confirm overlay');

        $this->editor->handleInput("\r"); // "Push with --set-upstream …"
        $this->await(fn (): bool => \count($this->events) >= 2);
        $this->assertTrue($this->events[1]->ok, 'the set-upstream push succeeded');
    }

    public function testCancelOperationIdleAndBusy(): void
    {
        $remote = $this->selfRemoteRepo();
        $commands = $this->commands($remote);

        $commands->cancelOperation();
        $this->assertStringContainsString('No git remote operation is running', $this->flash());

        $commands->fetch();
        $this->assertTrue($remote->isBusy());
        $commands->cancelOperation();
        $this->assertFalse($remote->isBusy());
        $this->assertStringContainsString('Git remote operation cancelled', $this->flash());
        $this->assertSame([], $this->events, 'no completion event is synthesized for a cancel');
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
