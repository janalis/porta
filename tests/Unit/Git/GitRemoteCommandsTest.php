<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Event\GitRemoteCompleted;
use Porta\Git\GitRemote;
use Porta\Git\GitRemoteCommands;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\StatusBarWidget;
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

    /** @var list<GitRemoteCompleted> */
    private array $events = [];

    private function await(callable $done, float $timeout = 5.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
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

        return new GitRemoteCommands($remote, new Notifier($this->status, $tui), new Translator('en'), $dispatcher);
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
        $d = escapeshellarg($project->dir);
        exec("git -C {$d} init -q && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init && git -C {$d} remote add origin {$d}");
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
        $d = escapeshellarg($this->project->dir);
        exec("git -C {$d} remote set-url origin /nonexistent-origin");
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
