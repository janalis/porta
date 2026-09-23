<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticsSink;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspBootstrapper;
use Porta\Lsp\LspLog;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\ServerMessageReporter;
use Porta\Lsp\ServerProgress;
use Porta\Lsp\WorkspaceEditor;
use Porta\Overlay\State\ToastCenter;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The inbound join: a notification arriving on a live session has to come out
 * as a toast the user can read, or as the status bar's activity line.
 *
 * Worth a test of its own because the two halves were written apart — the
 * handler registration in LspSession, the sinks in LspBootstrapper — and each
 * is convincing alone while delivering nothing end to end. The server-name
 * prefix is asserted here for the same reason: it is added in a third place.
 */
final class LspBootstrapperInboundTest extends TestCase
{
    private const string TASK = 't1';

    private FakeLspClient $client;
    private ToastCenter $toasts;
    private ServerProgress $progress;

    private function boot(): void
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->toasts = new ToastCenter(new FakeScheduler(), $tui);
        $this->progress = new ServerProgress($tui);
        $events = new EventDispatcher();
        $pool = new LspSessionPool(
            '/p',
            new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(),
        );
        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00');
            }
        };
        $notifier = new Notifier(new StatusBarWidget(), $tui, null, $this->toasts);
        $buffers = new BufferManager();
        $invalidator = new class implements EditorInvalidator {
            #[\Override]
            public function invalidate(): void {}
        };
        $diagnostics = new class implements DiagnosticsSink {
            #[\Override]
            public function applyDiagnostics(string $uri, array $byLine, string $owner): void {}
        };
        $plugins = new class implements LanguageServerProvider {
            #[\Override]
            public function languageServers(): array
            {
                return [];
            }
        };

        $bootstrapper = new LspBootstrapper(
            $pool,
            new LspLog($clock, $events),
            new WorkspaceEditor($buffers, $invalidator, $pool, $events, new RefactoringUndo(new BufferManager(), new EventDispatcher())),
            $diagnostics,
            $notifier,
            new Translator('en'),
            $plugins,
            new ServerMessageReporter($notifier, new LspLog($clock, $events)),
            $this->progress,
            '/p',
            // A catch-all server, so one didOpen brings a session up.
            'php-ls',
            false,
        );
        $bootstrapper->start();
        // Sessions start lazily — this is what launches one.
        $pool->notifyDidOpen('/p/a.php', "<?php\n", 'php', 1);
    }

    public function testAServerMessageBecomesAToastAttributedToThatServer(): void
    {
        $this->boot();

        $this->client->notificationHandlers['window/showMessage'](['type' => 2, 'message' => 'composer autoload missing']);

        $toasts = $this->toasts->toasts();
        $this->assertCount(1, $toasts);
        $this->assertSame('[php-ls] composer autoload missing', $toasts[0]->message);
    }

    public function testAnEmptyServerMessageIsNotTurnedIntoAToastOfTheServerName(): void
    {
        // The prefix is added before the empty check runs, so this is exactly
        // the case where attribution could manufacture a message from nothing.
        $this->boot();

        $this->client->notificationHandlers['window/showMessage'](['type' => 1, 'message' => '  ']);

        $this->assertSame([], $this->toasts->toasts());
    }

    public function testProgressFromTheServerReachesTheProgressStore(): void
    {
        $this->boot();

        $this->client->notificationHandlers['$/progress'](['token' => self::TASK, 'value' => ['kind' => 'begin', 'title' => 'Indexing']]);
        $this->client->notificationHandlers['$/progress'](['token' => self::TASK, 'value' => ['kind' => 'report', 'percentage' => 40]]);

        $this->assertSame('Indexing 40%', $this->progress->label());
    }
}
