<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Presence;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LspSessionPoolTest extends TestCase
{
    /** @var list<array{command: list<string>, client: FakeLspClient}> every client the pool built */
    private array $clients = [];
    private FixedActiveFile $activeFile;
    private int $statusRepaints = 0;

    private function pool(array $servers, Presence $configure = Presence::With): LspSessionPool
    {
        $this->clients = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LspStatusChanged::class, function (): void {
            ++$this->statusRepaints;
        });
        $pool = new LspSessionPool(
            '/tmp/proj',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            function (array $command, string $rootDir, \Closure $onLog, ?array $options): FakeLspClient {
                $client = new FakeLspClient();
                $this->clients[] = ['command' => $command, 'client' => $client, 'onLog' => $onLog, 'options' => $options];

                return $client;
            },
            $dispatcher,
        );
        if (Presence::With === $configure) {
            $pool->configure(
                $servers,
                static function (string $line): void {},
                static function (array $params): void {},
                static fn (array $edit): bool => false,
            );
        }

        return $pool;
    }

    private const array SERVERS = [
        'php' => ['command' => ['php-ls'], 'name' => 'php-ls'],
        'javascript' => ['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls'],
        'typescript' => ['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls'],
    ];

    public function testLogLinesArePrefixedWithTheServerName(): void
    {
        $logged = [];
        $pool = $this->pool(self::SERVERS, configure: Presence::Without);
        $pool->configure(
            self::SERVERS,
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);

        $this->clients[0]['onLog']('stderr says hi');

        $this->assertSame(['[php-ls] stderr says hi'], $logged, 'the shared hook identifies the server');
    }

    public function testDidOpenLazilyStartsTheMatchingServer(): void
    {
        $pool = $this->pool(self::SERVERS);
        $this->assertCount(0, $this->clients, 'nothing starts at configure time');

        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);

        $this->assertCount(1, $this->clients);
        $this->assertSame(['php-ls'], $this->clients[0]['command']);
        $this->assertSame('textDocument/didOpen', $this->clients[0]['client']->notifications[0]['method']);
        $this->assertSame(1, $this->statusRepaints, 'startup resolution repaints the status bar');
    }

    public function testLanguagesSharingACommandShareOneSession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.js', 'x', 'javascript', 1);
        $pool->notifyDidOpen('/tmp/proj/b.ts', 'y', 'typescript', 1);

        $this->assertCount(1, $this->clients, 'JS and TS share the ts-ls process');
        $this->assertCount(2, $this->clients[0]['client']->notifications);
    }

    public function testInitializationOptionsReachTheClientFactoryAndSplitSessions(): void
    {
        $pool = $this->pool([
            'php' => ['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]],
            'twig' => ['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]],
            'yaml' => ['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => true]],
        ]);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/b.twig', 'y', 'twig', 1);

        $this->assertCount(1, $this->clients, 'same command + same options share one process');
        $this->assertSame(['workspaceTrust' => false], $this->clients[0]['options']);

        $pool->notifyDidOpen('/tmp/proj/c.yaml', 'z', 'yaml', 1);

        $this->assertCount(2, $this->clients, 'same command under different options launches separately');
        $this->assertSame(['workspaceTrust' => true], $this->clients[1]['options']);
    }

    public function testAServerWithoutOptionsHandsTheFactoryNull(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);

        $this->assertNull($this->clients[0]['options']);
    }

    public function testDistinctLanguagesGetDistinctSessions(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/b.ts', 'y', 'typescript', 1);

        $this->assertCount(2, $this->clients);
    }

    public function testUnmatchedLanguageIsANoOpWithoutACatchAll(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/notes.md', 'x', 'markdown', 1);
        $pool->notifyDidClose('/tmp/proj/notes.md');

        $this->assertCount(0, $this->clients);
        $this->assertNull($pool->ready('/tmp/proj/notes.md', new Document('x')));
    }

    public function testCatchAllServesEveryLanguage(): void
    {
        $pool = $this->pool(['*' => ['command' => ['any-ls'], 'name' => 'any-ls']]);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/notes.md', 'y', 'markdown', 1);

        $this->assertCount(1, $this->clients);
        $this->assertCount(2, $this->clients[0]['client']->notifications);
    }

    public function testExactLanguageBeatsTheCatchAll(): void
    {
        $pool = $this->pool([
            '*' => ['command' => ['any-ls'], 'name' => 'any-ls'],
            'php' => ['command' => ['php-ls'], 'name' => 'php-ls'],
        ]);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);

        $this->assertSame(['php-ls'], $this->clients[0]['command']);
    }

    public function testRequestsRouteByTheActiveFile(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/b.ts', 'y', 'typescript', 1);

        $this->activeFile->set('/tmp/proj/b.ts');
        $pool->request('textDocument/definition', ['x' => 1], static function (): void {});

        $this->assertCount(0, array_filter($this->clients[0]['client']->requests));
        $this->assertSame('textDocument/definition', $this->clients[1]['client']->requests[0]['method']);
        $this->assertTrue($pool->isActive());
        $this->assertSame('/tmp/proj/b.ts', $pool->ready('/tmp/proj/b.ts', new Document('y')));

        // With no active file, workspace-level calls (symbol search,
        // executeCommand) fall back to the running PHP/catch-all session.
        $this->activeFile->set(null);
        $this->assertTrue($pool->isActive());
        $pool->request('workspace/symbol', ['query' => 'x'], static function (): void {});
        $this->assertSame('workspace/symbol', $this->clients[0]['client']->requests[0]['method'] ?? null);
    }

    public function testWhenReadyRunsAgainstTheActiveFilesSession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->activeFile->set('/tmp/proj/a.php');

        $ran = false;
        $pool->whenReady(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran, 'FakeLspClient initializes synchronously, so a ready action runs immediately');
    }

    public function testWhenReadyWithoutAnActiveSessionIsInert(): void
    {
        $pool = $this->pool(self::SERVERS);

        $ran = false;
        $pool->whenReady(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran, 'no active file — no session to become ready');
    }

    public function testIsInitializedTracksTheActiveSession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $this->assertFalse($pool->isInitialized(), 'nothing open yet');

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->activeFile->set('/tmp/proj/a.php');
        $this->assertTrue($pool->isInitialized(), 'FakeLspClient initializes synchronously');
    }

    public function testNotifyFullChangeWithNoMatchingSessionIsSilent(): void
    {
        $pool = $this->pool(self::SERVERS);

        $pool->notifyFullChange('/tmp/proj/notes.md', new Document('x')); // unmatched language: no session

        $this->assertCount(0, $this->clients);
    }

    public function testFlushPendingChangeReachesTheMatchingSession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $doc = new Document('x');
        $before = \count(\Revolt\EventLoop::getIdentifiers());
        $pool->scheduleDidChange($doc, static fn (): string => '/tmp/proj/a.php', static function (): void {});
        $this->assertGreaterThan($before, \count(\Revolt\EventLoop::getIdentifiers()), 'the debounce armed');

        $pool->flushPendingChange('/tmp/proj/a.php', $doc);

        $this->assertSame($before, \count(\Revolt\EventLoop::getIdentifiers()), 'flushing cancelled the pending debounce');
    }

    public function testWorkspaceLevelRequestsFallBackToTheCatchAllOverPhp(): void
    {
        // Only '*' is configured (no explicit 'php' entry): with no active
        // file, a workspace-level request must still reach it.
        $pool = $this->pool(['*' => ['command' => ['any-ls'], 'name' => 'any-ls']]);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1); // starts the catch-all session
        $this->activeFile->set(null);

        $pool->request('workspace/symbol', ['query' => 'x'], static function (): void {});

        $this->assertSame('workspace/symbol', $this->clients[0]['client']->requests[0]['method'] ?? null);
    }

    public function testStatusForTracksLifecyclePerServer(): void
    {
        $pool = $this->pool(self::SERVERS);
        $this->assertSame('php-ls', $pool->statusFor('/tmp/proj/a.php'), 'configured but not yet started shows the name');
        $this->assertSame('no server', $pool->statusFor('/tmp/proj/notes.md'));
        $this->assertSame('php-ls', $pool->statusFor(null), 'no file falls back to the PHP server');

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->assertSame('php-ls', $pool->statusFor('/tmp/proj/a.php'), 'FakeLspClient initializes synchronously');
    }

    public function testStatusForReflectsAFailedStartRatherThanTheStaticName(): void
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile(),
            new Translator('en'),
            static fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => new class extends FakeLspClient {
                #[\Override]
                public function start(callable $onReady): void
                {
                    $onReady(\Porta\Support\Outcome::Failed);
                }
            },
        );
        $pool->configure(
            self::SERVERS,
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);

        $this->assertSame('failed', $pool->statusFor('/tmp/proj/a.php'), 'not the server\'s static name');
    }

    public function testIsActiveAndRequestAreInertWithNoSessionAnywhere(): void
    {
        $pool = $this->pool(self::SERVERS);
        // Nothing ever opened, and no active file: activeSession() is null.

        $this->assertFalse($pool->isActive());
        $pool->request('workspace/symbol', ['query' => 'x'], static function (): void {});

        $this->assertSame([], $this->clients, 'nothing to route the request to — silent, not a crash');
    }

    public function testStatusForPhpWithoutAServerPointsAtSettings(): void
    {
        $pool = $this->pool([]);
        $this->assertSame('none (enable a PHP server in Settings)', $pool->statusFor('/tmp/proj/a.php'));
        $this->assertSame('no server', $pool->statusFor('/tmp/proj/b.ts'));
        $this->assertSame('no server', $pool->statusFor(null));
    }

    public function testDisabledAndUnconfiguredStates(): void
    {
        $pool = $this->pool([], configure: Presence::Without);
        $this->assertSame('no server', $pool->statusFor('/tmp/proj/a.php'), 'before startLsp runs');
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->assertCount(0, $this->clients, 'unconfigured pool never starts sessions');

        $pool->disable();
        $this->assertSame('disabled', $pool->statusFor('/tmp/proj/a.php'));
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->assertCount(0, $this->clients);
    }

    public function testStopStopsEveryStartedSession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/b.ts', 'y', 'typescript', 1);

        $pool->stop();

        $this->assertTrue($this->clients[0]['client']->stopped);
        $this->assertTrue($this->clients[1]['client']->stopped);
    }

    public function testNonOpeningCallsNeverStartASession(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidSave('/tmp/proj/a.php'); // configured language, but nothing opened it
        $pool->flushPendingChange('/tmp/proj/a.php', new Document('x'));

        $this->assertCount(0, $this->clients, 'only didOpen may launch a server');
    }

    public function testSyncCallsRouteByPath(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $phpClient = $this->clients[0]['client'];

        $doc = new Document('x');
        $pool->notifyDidSave('/tmp/proj/a.php');
        $pool->notifyFullChange('/tmp/proj/a.php', $doc);
        $pool->notifyDidClose('/tmp/proj/a.php');
        $pool->notifyDidSave('/tmp/proj/other.md'); // unmatched: dropped

        $methods = array_column($phpClient->notifications, 'method');
        $this->assertSame(
            ['textDocument/didOpen', 'textDocument/didSave', 'textDocument/didChange', 'textDocument/didClose'],
            $methods,
        );
    }

    public function testConfiguredDiagnosticsAndApplyEditHooksReachTheSession(): void
    {
        $diagnostics = [];
        $applied = [];
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile(),
            new Translator('en'),
            function (array $command, string $rootDir, \Closure $onLog): FakeLspClient {
                $client = new FakeLspClient();
                $this->clients[] = ['command' => $command, 'client' => $client, 'onLog' => $onLog];

                return $client;
            },
        );
        $pool->configure(
            self::SERVERS,
            static function (string $line): void {},
            static function (array $params) use (&$diagnostics): void {
                $diagnostics[] = $params;
            },
            static function (array $edit) use (&$applied): bool {
                $applied[] = $edit;

                return true;
            },
        );
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $client = $this->clients[0]['client'];

        $client->notificationHandlers['textDocument/publishDiagnostics'](['uri' => 'file:///tmp/proj/a.php', 'diagnostics' => []]);
        $this->assertCount(1, $diagnostics, 'the configured diagnostics hook received it, not the no-op default');

        $result = ($client->applyEditHandler)(['changes' => []]);
        $this->assertCount(1, $applied, 'the configured apply-edit hook received it, not the no-op default');
        $this->assertTrue($result, 'and its return value is the one the pool was configured with');
    }

    public function testReconfigureWithoutAPriorConfigureFallsBackToTheNoOpHooks(): void
    {
        // reconfigure() sets the server map directly, without going through
        // configure() — so a pool that was reconfigure()d but never
        // configure()d has null onDiagnostics/onApplyEdit hooks, and every
        // session it starts must fall back to the safe no-op defaults.
        $pool = $this->pool([], configure: Presence::Without);
        $pool->reconfigure(self::SERVERS);

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $client = $this->clients[0]['client'];

        $this->assertFalse(($client->applyEditHandler)(['changes' => []]), 'the default apply-edit hook refuses the edit');
    }

    public function testReconfigureStopsSessionsAndRelaunchesUnderTheNewCommand(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $old = $this->clients[0]['client'];
        $this->assertFalse($old->stopped);

        $pool->reconfigure(['php' => ['command' => ['new-php-ls'], 'name' => 'new-php-ls']]);
        $this->assertTrue($old->stopped, 'the running session is stopped');

        // The next didOpen relaunches under the new command.
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $this->assertCount(2, $this->clients);
        $this->assertSame(['new-php-ls'], $this->clients[1]['command']);
        $this->assertSame('new-php-ls', $pool->statusFor('/tmp/proj/a.php'));
    }
}
