<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
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
