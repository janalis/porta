<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\TerminalStateRefresher;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TerminalStateRefresherTest extends TestCase
{
    public function testEmitsTheReassertBytesEveryNthTickOnARealTerminal(): void
    {
        $writes = [];
        $terminal = new class($writes) implements TerminalInterface {
            /** @param list<string> $writes */
            public function __construct(
                private array &$writes,
            ) {}

            public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void {}

            public function stop(): void {}

            public function write(string $data): void
            {
                $this->writes[] = $data;
            }

            public function getColumns(): int
            {
                return 80;
            }

            public function getRows(): int
            {
                return 24;
            }

            public function isKittyProtocolActive(): bool
            {
                return false;
            }

            public function moveBy(int $lines): void {}

            public function hideCursor(): void {}

            public function showCursor(): void {}

            public function clearLine(): void {}

            public function clearFromCursor(): void {}

            public function clearScreen(): void {}

            public function setTitle(string $title): void {}

            public function bell(): void {}

            public function isVirtual(): bool
            {
                return false;
            }
        };
        $refresher = new TerminalStateRefresher(new Tui(terminal: $terminal), every: 3);

        $refresher->tick();
        $refresher->tick();
        $this->assertSame([], $writes, 'nothing before the Nth tick');

        $refresher->tick();
        $this->assertSame([TerminalStateRefresher::REASSERT], $writes, 'the 3rd tick re-asserts');

        $refresher->tick();
        $refresher->tick();
        $refresher->tick();
        $this->assertCount(2, $writes, 'and again on the 6th');
        $this->assertStringContainsString("\x1b[?2026l", TerminalStateRefresher::REASSERT, 'synchronized output unlatched');
        $this->assertStringContainsString("\x1b[?1003h", TerminalStateRefresher::REASSERT, 'mouse motion mode re-enabled');
    }

    public function testAVirtualTerminalIsNeverWrittenTo(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $refresher = new TerminalStateRefresher(new Tui(terminal: $terminal), every: 2);

        $refresher->tick();
        $refresher->tick();
        $refresher->tick();
        $refresher->tick();

        $this->assertSame('', $terminal->getOutput(), 'E2e output stays byte-stable');
    }
}
