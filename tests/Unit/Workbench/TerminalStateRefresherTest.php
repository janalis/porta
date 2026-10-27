<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Input\PixelPointer;
use Porta\Tests\Support\RecordingTerminal;
use Porta\Workbench\TerminalStateRefresher;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TerminalStateRefresherTest extends TestCase
{
    public function testEmitsTheReassertBytesEveryNthTickOnARealTerminal(): void
    {
        $calls = [];
        $terminal = new RecordingTerminal($calls); // a real terminal, not a virtual one
        $tui = new Tui(terminal: $terminal);
        $pointer = new PixelPointer($tui);
        $refresher = new TerminalStateRefresher($tui, $pointer, every: 3);

        $refresher->tick();
        $refresher->tick();
        $this->assertSame([], self::written($calls), 'nothing before the Nth tick');

        $refresher->tick();
        $this->assertSame([TerminalStateRefresher::REASSERT], self::written($calls), 'the 3rd tick re-asserts');

        $refresher->tick();
        $refresher->tick();
        $refresher->tick();
        $this->assertCount(2, self::written($calls), 'and again on the 6th');
        $this->assertStringContainsString("\x1b[?2026l", TerminalStateRefresher::REASSERT, 'synchronized output unlatched');
        $this->assertStringContainsString("\x1b[?1003h", TerminalStateRefresher::REASSERT, 'mouse motion mode re-enabled');

        // Once pixel reports are on, the re-assert ends with them: its
        // `?1006h` alone would turn them back into cell reports.
        $pointer->onInput(new InputEvent("\x1b[?1016;2\$y"));
        $pointer->onInput(new InputEvent("\x1b[6;20;10t"));
        $calls = [];
        $refresher->tick();
        $refresher->tick();
        $refresher->tick();
        $this->assertSame([TerminalStateRefresher::REASSERT.PixelPointer::ON], \array_slice(self::written($calls), -1), 'pixel reports re-asserted after the cell encoding');
    }

    /**
     * The bytes written, in order: what a terminal on a real tty would have received.
     *
     * @param list<string> $calls
     *
     * @return list<string>
     */
    private static function written(array $calls): array
    {
        return \array_values(\array_map(static fn (string $call): string => \substr($call, offset: 6), \array_filter($calls, static fn (string $call): bool => \str_starts_with($call, 'write:'))));
    }

    public function testAVirtualTerminalIsNeverWrittenTo(): void
    {
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $tui = new Tui(terminal: $terminal);
        $refresher = new TerminalStateRefresher($tui, new PixelPointer($tui), every: 2);

        $refresher->tick();
        $refresher->tick();
        $refresher->tick();
        $refresher->tick();

        $this->assertSame('', $terminal->getOutput(), 'E2e output stays byte-stable');
    }
}
