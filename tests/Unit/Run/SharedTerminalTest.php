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

/**
 * A terminal shared with collaborators, both ends, over a real PTY: one side
 * reads a tab's scrollback and its output as it arrives and types into its
 * process; the other shows a tab whose process runs elsewhere, fed from
 * outside, its keys handed back.
 */
final class SharedTerminalTest extends TestCase
{
    use PumpsLoop;

    /** Held for the whole test, and read by it: the runs' cwd is in it, and a TempProject deletes its directory when freed. */
    private TempProject $project;

    private TerminalSessions $sessions;

    private RunController $runs;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new TempProject([]);
        $this->runs = TestRunController::create($this->project->dir, TestTui::create(100, 24), $this->sessions = new TerminalSessions(), new TerminalPanelWidget());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->runs->stop();
    }

    public function testATabIsReadAsItPrintsAndTypedInto(): void
    {
        $runs = TestRuns::create($this->runs);
        $runs->start('cat', [\PHP_BINARY, '-r', 'echo "ready in ", getcwd(), "\n"; while (false !== ($l = fgets(STDIN))) { echo "got ", $l; }']);
        $this->await(static fn (): bool => \str_contains((string) $runs->scrollback('run:cat'), 'ready'), what: 'the run to start');
        $this->assertSame([['id' => 'run:cat', 'title' => 'cat', 'running' => true]], $runs->terminals());
        $this->assertStringContainsString('ready in '.\realpath($this->project->dir), (string) $runs->scrollback('run:cat'), 'run in the project');

        $seen = '';
        $this->assertTrue($runs->watch('run:cat', static function (string $chunk) use (&$seen): void {
            $seen .= $chunk;
        }));
        $this->assertTrue($runs->write('run:cat', "hello\n"));
        $this->await(static function () use (&$seen): bool {
            return \str_contains($seen, 'got hello');
        }, what: 'the typed line, read as it printed');
        $this->assertStringContainsString("\r\ngot hello\r\n", (string) $runs->scrollback('run:cat'), 'the scrollback, lines ended as a terminal ends them');

        $this->assertTrue($runs->watch('run:cat', null));
        $runs->write('run:cat', "again\n");
        $this->await(static fn (): bool => \str_contains((string) $runs->scrollback('run:cat'), 'got again'), what: 'the second line');
        $this->assertStringNotContainsString('again', $seen, 'no longer watched');

        $this->assertFalse($runs->watch('nope', null));
        $this->assertFalse($runs->write('nope', 'x'));
        $this->assertNull($runs->scrollback('nope'));
    }

    public function testAMirroredTabShowsWhatItIsFedAndHandsItsKeysOn(): void
    {
        $runs = TestRuns::create($this->runs);
        $mine = $this->sessions->create('shell:1', 'zsh 1');
        $typed = [];
        $runs->mirror('assist:shell:1', 'Hal\'s zsh', static function (string $bytes) use (&$typed): void {
            $typed[] = $bytes;
        });
        $this->assertSame($mine, $this->sessions->active(), 'made behind the tab in front');
        $this->assertFalse($runs->write('assist:shell:1', 'x'), 'no process here to type into');

        $runs->feed('assist:shell:1', "\x1b[32m\$ ls\x1b[0m\r\nsrc\r\n");
        $runs->feed('nope', 'ignored');
        $this->assertStringContainsString('src', (string) $runs->scrollback('assist:shell:1'));

        $mirror = $this->sessions->find('assist:shell:1');
        $this->assertInstanceOf(TerminalSession::class, $mirror);
        $this->sessions->makeActive($mirror);
        $this->runs->forwardInput('l');
        $this->runs->feed('assist:shell:1', "more\r\n");
        $this->assertSame(['l'], $typed, 'its keys, for whoever relays them');

        // Made again: retitled, and its keys go nowhere now.
        $runs->mirror('assist:shell:1', 'Hal\'s zsh (ended)', null);
        $this->assertSame('Hal\'s zsh (ended)', $mirror->title);
        $this->runs->forwardInput('x');
        $this->assertSame(['l'], $typed);
        $this->runs->forwardInput('');
    }
}
