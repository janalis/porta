<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use Porta\Run\RunController;
use Porta\Terminal\TerminalSession;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestRunController;
use Porta\Tests\Support\TestRuns;
use Porta\Tests\Support\TestTui;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * A run nobody at the keyboard started — a plugin's, an agent's — over a real
 * PTY: it takes neither the panel nor the focus nor the user's tab, it says
 * how it ended, and a waiter is told when it never will.
 */
final class BackgroundRunTest extends TestCase
{
    use PumpsLoop;

    /** Held for the whole test: a TempProject deletes its directory when freed, and the runs' cwd is in it. */
    private TempProject $project;

    private TerminalSessions $sessions;

    private TerminalPanelWidget $panel;

    private Tui $tui;

    private RunController $runs;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject(['composer.json' => '{"scripts": {"test": "phpunit"}}']);
        $this->tui = TestTui::create(100, 24);
        $this->runs = TestRunController::create(
            $this->project->dir,
            $this->tui,
            $this->sessions = new TerminalSessions(),
            $this->panel = new TerminalPanelWidget(),
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->runs->stop();
    }

    /** @return list<string> */
    private static function php(string $code): array
    {
        return [\PHP_BINARY, '-r', $code];
    }

    public function testARunInTheBackgroundLeavesTheUsersScreenAloneAndReportsHowItEnded(): void
    {
        $shell = $this->sessions->create('shell:1', 'zsh 1');
        $focused = $this->tui->getFocus();
        $exit = null;

        // Through the plugin's facade: {@see Runs::start()} is the way in a plugin has.
        TestRuns::create($this->runs)->start('agent', self::php('echo "hello from the run in ", getcwd(), "\n"; exit(3);'), static function (int $code) use (&$exit): void {
            $exit = $code;
        });

        $this->assertSame($shell, $this->sessions->active(), 'the tab the user looks at stays the active one');
        $this->assertFalse($this->panel->isVisible(), 'the panel was not opened');
        $this->assertSame($focused, $this->tui->getFocus(), 'focus did not move');
        $session = $this->runs->runSession('agent');
        $this->assertInstanceOf(TerminalSession::class, $session);
        $this->assertNull($session->process?->exitCode(), 'no exit code while it runs');

        $this->await(static function () use (&$exit): bool {
            return null !== $exit;
        }, what: 'the run to end');

        $this->assertSame(3, $exit);
        $this->assertSame(3, $session->process?->exitCode());
        $output = TestRuns::create($this->runs)->output('agent');
        $this->assertNotNull($output);
        $this->assertFalse($output['running']);
        $this->assertSame(3, $output['exitCode']);
        $this->assertStringContainsString('hello from the run in '.\realpath($this->project->dir), $output['output'], 'run in the project');
        $this->assertStringNotContainsString("\x1b", $output['output'], 'plain text, not terminal escapes');
    }

    public function testAWaiterIsToldWhenItsRunIsReplacedOrClosedFirst(): void
    {
        $told = [];
        $wait = static function (string $who) use (&$told): \Closure {
            return static function (int $code) use ($who, &$told): void {
                $told[] = $who.':'.$code;
            };
        };
        $long = self::php('sleep(30);');

        $this->runs->runInBackground($long, 'agent', $wait('first'));
        $this->runs->runInBackground(self::php('exit(0);'), 'agent', $wait('second'));
        $this->assertSame(['first:-1'], $told, 'replaced: a killed process never reports, so the waiter is told now');
        $this->await(static function () use (&$told): bool {
            return \in_array('second:0', $told, strict: true);
        }, what: 'the replacing run to end');

        $this->runs->runInBackground($long, 'other', $wait('closed'));
        $index = \array_search($this->runs->runSession('other'), $this->sessions->sessions, strict: true);
        $this->assertIsInt($index);
        $this->runs->closeSession($index);
        $this->assertSame(['first:-1', 'second:0', 'closed:-1'], $told);

        $this->runs->runInBackground($long, 'silent'); // nobody waits: nothing to tell
        $this->runs->runInBackground(self::php('exit(0);'), 'silent');
        $this->assertSame(['first:-1', 'second:0', 'closed:-1'], $told);
    }

    public function testTheConfigurationsAreTheRunMenus(): void
    {
        $this->assertContains('composer test', \array_column(TestRuns::create($this->runs)->configurations(), 'label'));
    }
}
