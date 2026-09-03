<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Lsp\LspLog;
use Porta\Lsp\ServerMessageReporter;
use Porta\Overlay\State\Toast;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\ToastSeverity;
use Porta\Tests\Support\FakeScheduler;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * `window/showMessage` is how a server talks to the USER. Porta had no handler
 * for it at all, so these are the first messages that reach anyone.
 */
final class ServerMessageReporterTest extends TestCase
{
    private ToastCenter $toasts;
    private LspLog $log;

    private function reporter(): ServerMessageReporter
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->toasts = new ToastCenter(new FakeScheduler(), $tui);
        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00');
            }
        };
        $this->log = new LspLog($clock, new EventDispatcher());

        return new ServerMessageReporter(
            new Notifier(new StatusBarWidget(), $tui, null, $this->toasts),
            $this->log,
        );
    }

    /** @return list<ToastSeverity> */
    private function severities(): array
    {
        return array_map(static fn (Toast $toast): ToastSeverity => $toast->severity, $this->toasts->toasts());
    }

    #[DataProvider('severityMapping')]
    public function testLspMessageTypeBecomesTheMatchingToastSeverity(int $type, ToastSeverity $expected): void
    {
        $reporter = $this->reporter();

        $reporter->report(['type' => $type, 'message' => 'something happened']);

        self::assertSame([$expected], $this->severities());
    }

    public static function severityMapping(): \Generator
    {
        yield 'Error' => [1, ToastSeverity::Error];
        yield 'Warning' => [2, ToastSeverity::Warn];
        yield 'Info' => [3, ToastSeverity::Info];
    }

    public function testALogLevelMessageGoesToTheLogAndNeverInterrupts(): void
    {
        // Type 4 is the server thinking aloud — the same place
        // window/logMessage already goes.
        $reporter = $this->reporter();

        $reporter->report(['type' => 4, 'message' => 'reindexed 402 files']);

        self::assertSame([], $this->severities(), 'a log-level message must not raise a toast');
        $texts = array_map(static fn (array $entry): string => $entry['text'], $this->log->entries());
        self::assertContains('reindexed 402 files', $texts);
    }

    public function testATypeOutsideTheEnumIsShownRatherThanDropped(): void
    {
        $reporter = $this->reporter();

        $reporter->report(['type' => 99, 'message' => 'from the future']);

        self::assertSame([ToastSeverity::Info], $this->severities());
    }

    public function testAMissingTypeIsTreatedAsInfo(): void
    {
        $reporter = $this->reporter();

        $reporter->report(['message' => 'no type field']);

        self::assertSame([ToastSeverity::Info], $this->severities());
    }

    public function testAnEmptyOrWhitespaceMessageSaysNothingAtAll(): void
    {
        // A blank toast is worse than silence: it announces that something
        // happened and then declines to say what.
        $reporter = $this->reporter();

        $reporter->report(['type' => 1, 'message' => '   ']);
        $reporter->report(['type' => 1]);

        self::assertSame([], $this->severities());
    }
}
