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
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;
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
    use UntrustsProjects;

    private const string TASK = 't1';

    private FakeLspClient $client;
    private int $launched = 0;
    private ToastCenter $toasts;
    private ServerProgress $progress;

    /** @param array<string, non-empty-list<array{command: list<string>, name: string}>> $relayed the plugins' servers run elsewhere */
    private function boot(string $root = '/p', ?string $lspCommand = 'php-ls', array $relayed = []): LspSessionPool
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20));
        $this->toasts = new ToastCenter(new FakeScheduler(), $tui);
        $this->progress = new ServerProgress($tui);
        $events = new EventDispatcher();
        $pool = new LspSessionPool(
            $root,
            new FixedActiveFile(),
            new Translator('en'),
            function (array $command, string $rootDir, \Closure $onLog): FakeLspClient {
                ++$this->launched;

                return $this->client = new FakeLspClient();
            },
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
        $plugins = new readonly class($relayed) implements LanguageServerProvider {
            /** @param array<string, non-empty-list<array{command: list<string>, name: string}>> $relayed */
            public function __construct(
                private array $relayed,
            ) {}

            #[\Override]
            public function languageServers(): array
            {
                return [];
            }

            public function relayedServers(): array
            {
                return $this->relayed;
            }

            public function missingServers(): array
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
            $root,
            // A catch-all server, so one didOpen brings a session up.
            $lspCommand,
            false,
        );
        $bootstrapper->start();
        // Sessions start lazily — this is what launches one.
        $pool->notifyDidOpen($root.'/a.php', "<?php\n", 'php', 1);

        return $pool;
    }

    /** No server for an untrusted project, and the bar says why rather than "install one". */
    public function testAnUntrustedProjectStartsNoServerAndSaysSo(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.php' => '<?php']);

        $pool = $this->boot($project->dir, null);

        $this->assertSame(0, $this->launched, 'no session was launched');
        $this->assertSame('off — project not trusted', $pool->statusFor($project->dir.'/a.php'));
        $this->assertSame('php-ls', $this->boot($project->dir, 'php-ls')->statusFor($project->dir.'/a.php'), 'one named for this run starts');
    }

    /** A server run elsewhere — assist's relay to the host — is the one an untrusted project still starts. */
    public function testAnUntrustedProjectStartsAServerRunElsewhere(): void
    {
        $this->untrustProjects();
        $project = new TempProject(['a.php' => '<?php']);

        $pool = $this->boot($project->dir, null, ['php' => [['command' => ['porta', 'assist-lsp', 'php'], 'name' => 'assist']]]);

        $this->assertSame(1, $this->launched);
        $this->assertStringStartsWith('assist', $pool->statusFor($project->dir.'/a.php'));
    }

    public function testAServerMessageBecomesAToastAttributedToThatServer(): void
    {
        $this->boot();

        $this->client->notificationHandlers['window/showMessage'](['type' => 2, 'message' => 'composer autoload missing']);

        $toasts = $this->toasts->toasts();
        $this->assertCount(1, $toasts);
        $this->assertSame('[php-ls] composer autoload missing', $toasts[0]->message->plain());
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
