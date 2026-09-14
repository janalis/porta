<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Porta\Ui\TerminalRestorer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TerminalRestorerTest extends TestCase
{
    public function testRestoreWritesTheTeardownSequenceOnceWhileArmed(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $restorer = new TerminalRestorer();

        $restorer->arm($terminal);
        $restorer->restore();
        $restorer->restore(); // a second shutdown pass must not double-write

        $this->assertSame(TerminalRestorer::RESTORE, $terminal->getOutput());
    }

    public function testAFatalErrorStillRestoresTheTerminalThroughTheShutdownNet(): void
    {
        // The class's whole reason to exist: no finally runs on a fatal, only
        // the registered shutdown function. A real subprocess dies mid-"session"
        // and the teardown bytes must still reach its terminal (stdout here).
        $project = new TempProject();
        $script = $project->write('fatal.php', <<<'PHP'
            <?php
            require $argv[1].'/vendor/autoload.php';
            $restorer = new \Porta\Ui\TerminalRestorer();
            $restorer->arm(new \Symfony\Component\Tui\Terminal\Terminal()); // write() is a plain fwrite(STDOUT)
            this_function_does_not_exist(); // the fatal no finally survives
            PHP);

        \exec(\sprintf('%s %s %s 2>/dev/null', \escapeshellarg(\PHP_BINARY), \escapeshellarg($script), \escapeshellarg(\dirname(__DIR__, levels: 3))), $output, $exit);

        $this->assertNotSame(0, $exit, 'the script died on the fatal');
        $this->assertStringContainsString(TerminalRestorer::RESTORE, \implode("\n", $output), 'the shutdown net re-emitted the teardown sequence');
    }

    public function testDisarmMakesRestoreANoOp(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $restorer = new TerminalRestorer();

        $restorer->arm($terminal);
        $restorer->disarm(); // the ordinary teardown ran
        $restorer->restore();

        $this->assertSame('', $terminal->getOutput());
    }
}
