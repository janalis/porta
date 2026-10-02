<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Event\LspStatusChanged;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\Backoff;
use Porta\Support\Outcome;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LspSessionPoolTest extends TestCase
{
    use PumpsLoop;

    /** @var list<array{command: list<string>, client: FakeLspClient}> every client the pool built */
    private array $clients = [];
    private FixedActiveFile $activeFile;
    private int $statusRepaints = 0;

    /** What every factory-built client's start() reports; steer to Failed for retry scenarios. */
    private Outcome $clientOutcome = Outcome::Ok;

    private function pool(array $servers, Presence $configure = Presence::With): LspSessionPool
    {
        $this->clients = [];
        $this->clientOutcome = Outcome::Ok;
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
                $client->startOutcome = $this->clientOutcome;
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
        'php' => [['command' => ['php-ls'], 'name' => 'php-ls']],
        'javascript' => [['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls']],
        'typescript' => [['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls']],
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

    /**
     * A renamed folder is closed at EVERY running server, not the one its path
     * happens to route to.
     *
     * The point of the fan-out: `src/Old/` can hold a .php and a .ts, and the
     * two live in different sessions. Routing the close by path — which is what
     * every other notify* on this pool does — would tell one server and leave
     * the other holding a document with no file behind it.
     */
    public function testClosingUnderAFolderReachesEveryRunningServer(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/src/Old/a.php', '<?php', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/src/Old/a.ts', 'x', 'typescript', 1);
        $pool->notifyDidOpen('/tmp/proj/src/Keep/b.php', '<?php', 'php', 1);
        $this->assertCount(2, $this->clients, 'two languages, two sessions — otherwise this proves nothing');
        foreach ($this->clients as $built) {
            $built['client']->notifications = [];
        }

        $pool->notifyDidCloseUnder('/tmp/proj/src/Old');

        $this->assertSame(
            [
                ['textDocument/didClose file:///tmp/proj/src/Old/a.php'],
                ['textDocument/didClose file:///tmp/proj/src/Old/a.ts'],
            ],
            \array_map(
                static fn (array $built): array => \array_map(
                    static fn (array $sent): string => $sent['method'].' '.$sent['params']['textDocument']['uri'],
                    $built['client']->notifications,
                ),
                $this->clients,
            ),
            'each server was told about its own file, and Keep/b.php — same session as Old/a.php — stayed open',
        );
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

    public function testAFailedStartRetriesOnLaterDidOpensThenGoesSticky(): void
    {
        // A dead server used to stay dead until a settings edit. Now the slot
        // is evicted so a later didOpen relaunches — bounded, so a
        // crash-looping binary is not respawned on every buffer switch.
        $pool = $this->pool(self::SERVERS);
        $this->clientOutcome = Outcome::Failed;

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $this->assertCount(1, $this->clients);
        $this->assertSame('failed', $pool->statusFor('/tmp/proj/a.php'));

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 2);
        $this->assertCount(2, $this->clients, 'the dead slot was evicted, so the next didOpen retried');

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 3);
        $this->assertCount(3, $this->clients, 'third and final attempt');

        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 4);
        $this->assertCount(3, $this->clients, 'past the cap the failure is sticky until a reconfigure');
        $this->assertSame('failed', $pool->statusFor('/tmp/proj/a.php'));
    }

    public function testADeathSoonAfterComingUpStillCountsAndAStableServerEarnsAFreshBudget(): void
    {
        $pool = $this->pool(self::SERVERS);
        $this->clientOutcome = Outcome::Failed;
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1); // failure 1
        $this->clientOutcome = Outcome::Ok;
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 2);
        $this->assertSame('php-ls', $pool->statusFor('/tmp/proj/a.php'), 'up');

        $this->die(1); // failure 2: it was not up long enough to have earned anything
        $this->assertSame([2], \array_values(Probe::get($pool, 'startFailures')));
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 3);
        $this->assertCount(3, $this->clients, 'one attempt was left');

        // This one stays up past STABLE before it dies: a fresh slate.
        $upSince = Probe::get($pool, 'upSince');
        $this->assertIsArray($upSince);
        Probe::set($pool, 'upSince', \array_map(static fn (float $t): float => $t - LspSessionPool::STABLE, $upSince));
        $this->die(2);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 4);
        $this->assertCount(4, $this->clients, 'evicted with a fresh budget');
        $this->assertSame([1], \array_values(Probe::get($pool, 'startFailures')), 'only the failure that came after');
        $pool->stop();
    }

    public function testAServerThatDiesWhileUpIsLaunchedAgainAndToldEveryFileItHadOpen(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/b.php', 'b', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/c.php', 'c', 'php', 1);

        $this->die(0);
        $edited = new Document('a, edited while it was down');
        $pool->scheduleDidChange($edited, static fn (): string => '/tmp/proj/a.php', static function (): void {});
        $pool->notifyDidClose('/tmp/proj/c.php');
        $pool->scheduleDidChange(new Document('never opened'), static fn (): string => '/tmp/proj/z.php', static function (): void {});
        $this->pump(Backoff::after(0) - 0.3);
        $this->assertCount(1, $this->clients, 'not at once: after its backoff');

        $this->pump(0.5);
        $this->assertCount(2, $this->clients, 'relaunched after the first backoff');

        $opened = \array_map(
            static fn (array $n): array => [$n['params']['textDocument']['uri'], $n['params']['textDocument']['text']],
            \array_values(\array_filter($this->clients[1]['client']->notifications, static fn (array $n): bool => 'textDocument/didOpen' === $n['method'])),
        );
        $this->assertSame(
            [
                ['file:///tmp/proj/a.php', 'a, edited while it was down'],
                ['file:///tmp/proj/b.php', 'b'],
            ],
            $opened,
            'as they are now, and not what was closed meanwhile',
        );
        $this->assertSame('php-ls', $pool->statusFor('/tmp/proj/a.php'));
        $pool->stop();
    }

    public function testADidOpenDuringTheBackoffLaunchesAtOnceAndTheRestFollowWithoutRepeats(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $this->die(0);

        $pool->notifyDidOpen('/tmp/proj/a.php', 'a again', 'php', 2);
        $this->assertCount(2, $this->clients);
        $this->pump(Backoff::after(0) + 0.2);

        $this->assertCount(2, $this->clients, 'no second launch');
        $opens = \array_filter($this->clients[1]['client']->notifications, static fn (array $n): bool => 'textDocument/didOpen' === $n['method']);
        $this->assertCount(1, $opens, 'told once');
        $pool->stop();
    }

    public function testStoppingCancelsAPendingRelaunch(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $this->die(0);

        $pool->stop();
        $this->pump(Backoff::after(0) + 0.2);

        $this->assertCount(1, $this->clients);
    }

    public function testADeadServerTakesItsFindingsAndItsProgressWithIt(): void
    {
        $published = [];
        $progress = [];
        $pool = $this->pool(self::SERVERS, Presence::Without);
        $pool->configure(
            self::SERVERS,
            static function (string $line): void {},
            static function (array $params, string $owner) use (&$published): void {
                $published[] = [$owner, $params['uri'], \count($params['diagnostics'])];
            },
            static fn (array $edit): bool => false,
            null,
            static function (array $params) use (&$progress): void {
                $progress[] = [$params['token'], $params['value']['kind']];
            },
        );
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $client = $this->clients[0]['client'];
        $client->notificationHandlers['textDocument/publishDiagnostics'](['uri' => 'file:///tmp/proj/a.php', 'diagnostics' => [['message' => 'x']]]);
        $report = $client->notificationHandlers['$/progress'];
        foreach ([[1, 'begin'], [2, 'begin'], [2, 'end']] as [$id, $kind]) {
            $report(['token' => $id, 'value' => ['kind' => $kind, 'title' => 'Indexing']]);
        }
        $report(['value' => ['kind' => 'begin']]);

        $this->die(0);

        $this->assertSame(
            [
                ['php-ls', 'file:///tmp/proj/a.php', 1],
                ['php-ls', 'file:///tmp/proj/a.php', 0],
            ],
            $published,
        );
        $this->assertSame('begin', $progress[0][1]);
        $this->assertSame("php-ls\0null\0".'1', $progress[0][0], 'tokens are the server\'s own, kept apart from any other\'s');
        $this->assertSame([$progress[0][0], 'end'], \array_last($progress), 'what it never ended is ended for it');
        $this->assertCount(4, $progress, 'and only that');
        $pool->stop();
    }

    public function testAServerThatKeepsFailingSaysSoOnceAndIsNotStartedAgain(): void
    {
        $messages = [];
        $pool = $this->pool(self::SERVERS, Presence::Without);
        $pool->configure(
            self::SERVERS,
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
            static function (array $params) use (&$messages): void {
                $messages[] = $params;
            },
        );
        $this->clientOutcome = Outcome::Failed;
        foreach ([1, 2, 3, 4] as $version) {
            $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', $version);
        }

        $this->assertCount(3, $this->clients);
        $this->assertSame([], Probe::get($pool, 'relaunches'), 'a server that never came up is not relaunched behind anyone\'s back');
        $this->assertSame([['type' => 1, 'message' => '[php-ls] stopped 3 times and is not started again — change its settings or restart Porta']], $messages);
    }

    /** The server behind client $index dies: its start callback fires again, Failed. */
    private function die(int $index): void
    {
        $onReady = $this->clients[$index]['client']->lastOnReady;
        $this->assertNotNull($onReady);
        $onReady(Outcome::Failed);
    }

    /**
     * The one case an identical map must NOT be left alone: the binary behind
     * it was replaced on disk.
     *
     * An upgrade lands at the same path, so the argv is byte-identical and
     * {@see LspSessionPool::reconfigure()} correctly declines — which left the
     * running process answering from the inode `rename()` had already
     * unlinked, while the installer's toast said it succeeded.
     *
     * `reload()` takes the map as well as forcing the drop, because the FIRST
     * install changes it too: with no binary yet the plugin resolves no command
     * and is absent from the map entirely. Forcing a drop while KEEPING the old
     * map was the shape that hung — sessions stopped, and nothing left to
     * start.
     */
    public function testReloadDropsTheSessionEvenWhenTheMapIsUnchanged(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $client = $this->clients[0]['client'];

        $this->assertFalse($pool->reconfigure(self::SERVERS), 'sanity: the identical map is still skipped');
        $this->assertFalse($client->stopped);

        $pool->reload(self::SERVERS);

        $this->assertTrue($client->stopped, 'the stale process is stopped');
        $pool->notifyDidOpen('/tmp/proj/b.php', 'y', 'php', 1);
        $this->assertCount(2, $this->clients, 'and the next didOpen starts a fresh one from the map it was handed');
    }

    public function testReconfigureWithAnIdenticalMapKeepsTheRunningSessions(): void
    {
        // The provider-setting hook reconfigures on EVERY setting of a
        // provider plugin — a fix-on-save toggle must not cost every
        // language its warm session and re-index.
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $client = $this->clients[0]['client'];

        $this->assertFalse($pool->reconfigure(self::SERVERS), 'identical map: no-op, and callers skip the buffer re-open');
        $this->assertFalse($client->stopped, 'the warm session survived');
        $pool->notifyDidOpen('/tmp/proj/b.php', 'y', 'php', 1);
        $this->assertCount(1, $this->clients, 'and still serves didOpens');

        $this->assertTrue($pool->reconfigure(['php' => [['command' => ['other-ls'], 'name' => 'other-ls']]]), 'a changed map still applies');
        $this->assertTrue($client->stopped, 'and tears the old session down');
    }

    public function testInitializationOptionsReachTheClientFactoryAndSplitSessions(): void
    {
        $pool = $this->pool([
            'php' => [['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]]],
            'twig' => [['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]]],
            'yaml' => [['command' => ['sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => true]]],
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
        $pool = $this->pool(['*' => [['command' => ['any-ls'], 'name' => 'any-ls']]]);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'x', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/notes.md', 'y', 'markdown', 1);

        $this->assertCount(1, $this->clients);
        $this->assertCount(2, $this->clients[0]['client']->notifications);
    }

    public function testExactLanguageBeatsTheCatchAll(): void
    {
        $pool = $this->pool([
            '*' => [['command' => ['any-ls'], 'name' => 'any-ls']],
            'php' => [['command' => ['php-ls'], 'name' => 'php-ls']],
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

        $this->assertCount(0, \array_filter($this->clients[0]['client']->requests));
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
        $before = \count(EventLoop::getIdentifiers());
        $pool->scheduleDidChange($doc, static fn (): string => '/tmp/proj/a.php', static function (): void {});
        $this->assertGreaterThan($before, \count(EventLoop::getIdentifiers()), 'the debounce armed');

        $pool->flushPendingChange('/tmp/proj/a.php', $doc);

        $this->assertCount($before, EventLoop::getIdentifiers(), 'flushing cancelled the pending debounce');
    }

    public function testWorkspaceLevelRequestsFallBackToTheCatchAllOverPhp(): void
    {
        // Only '*' is configured (no explicit 'php' entry): with no active
        // file, a workspace-level request must still reach it.
        $pool = $this->pool(['*' => [['command' => ['any-ls'], 'name' => 'any-ls']]]);
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
                    $onReady(Outcome::Failed);
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

        $pool->missing(['php' => ['phpactor'], 'typescript' => ['ts-ls']]);
        $this->assertSame('phpactor not found', $pool->statusFor('/tmp/proj/a.php'), 'enabled, its binary not there: not "enable one"');
        $this->assertSame('ts-ls not found', $pool->statusFor('/tmp/proj/b.ts'));
        $this->assertSame('phpactor not found', $pool->statusFor(null), 'no file open: PHP\'s, as ever');
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

        $methods = \array_column($phpClient->notifications, 'method');
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

        $pool->reconfigure(['php' => [['command' => ['new-php-ls'], 'name' => 'new-php-ls']]]);
        $this->assertTrue($old->stopped, 'the running session is stopped');

        // The next didOpen relaunches under the new command.
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $this->assertCount(2, $this->clients);
        $this->assertSame(['new-php-ls'], $this->clients[1]['command']);
        $this->assertSame('new-php-ls', $pool->statusFor('/tmp/proj/a.php'));
    }

    /** php served by its own server AND one that runs beside it. */
    private const array TWO_ON_PHP = [
        'php' => [
            ['command' => ['php-ls'], 'name' => 'php-ls'],
            ['command' => ['sf-ls'], 'name' => 'sf-ls'],
        ],
    ];

    /**
     * Opening a file launches EVERY server for its language.
     *
     * A supplementary server that never received a didOpen has nothing to say
     * about the file, which is the whole of what it is there for.
     */
    public function testDidOpenStartsEveryServerForTheLanguage(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);

        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);

        $this->assertSame([['php-ls'], ['sf-ls']], \array_column($this->clients, 'command'));
        foreach ($this->clients as $built) {
            $this->assertSame(
                ['textDocument/didOpen'],
                \array_column($built['client']->notifications, 'method'),
                'both were told the file is open',
            );
        }
    }

    /**
     * And every later edit, save and close reaches both.
     *
     * Half a lifecycle is worse than none: a server still holding the text
     * from three keystrokes ago answers about a document the user has moved
     * on from, which reads as a wrong answer rather than as no answer.
     */
    public function testTheWholeDocumentLifecycleReachesEveryServer(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        foreach ($this->clients as $built) {
            $built['client']->notifications = [];
        }

        $pool->notifyFullChange('/tmp/proj/a.php', new Document("<?php\necho 1;\n"));
        $pool->notifyDidSave('/tmp/proj/a.php');
        $pool->notifyDidClose('/tmp/proj/a.php');

        foreach ($this->clients as $built) {
            $this->assertSame(
                ['textDocument/didChange', 'textDocument/didSave', 'textDocument/didClose'],
                \array_column($built['client']->notifications, 'method'),
            );
        }
    }

    /**
     * A feature request still goes to ONE server — the language's own.
     *
     * Most verbs pick a single answer (definition, rename, formatting), and
     * asking two servers would mean inventing a tie-break for each. The
     * supplementary one is there for the answers that merge, which is a
     * separate seam.
     */
    public function testAFeatureRequestGoesToTheLanguagesOwnServerOnly(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $this->activeFile->set('/tmp/proj/a.php');

        $pool->request('textDocument/definition', [], static function (mixed $result, ?array $error): void {});

        $this->assertSame(['textDocument/definition'], \array_column($this->clients[0]['client']->requests, 'method'));
        $this->assertSame([], $this->clients[1]['client']->requests, 'the one beside it is not asked');
    }

    /**
     * A second server that cannot start does not take the first with it.
     *
     * The failure budget is keyed by launch signature, not by language, so a
     * crash-looping companion burns its own retries.
     */
    public function testAFailingSecondServerLeavesTheFirstServing(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        // The companion dies mid-session: the same start callback fires again.
        $died = $this->clients[1]['client']->lastOnReady;
        $this->assertNotNull($died);
        $died(Outcome::Failed);

        $pool->notifyDidSave('/tmp/proj/a.php');

        $this->assertSame(
            ['textDocument/didOpen', 'textDocument/didSave'],
            \array_column($this->clients[0]['client']->notifications, 'method'),
            'the language still has a server',
        );
    }

    /** The status cell names both, and does not hide one that failed. */
    public function testStatusNamesEveryServerForTheLanguage(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $this->assertSame('php-ls +sf-ls', $pool->statusFor('/tmp/proj/a.php'), 'before either starts, their names');

        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $died = $this->clients[1]['client']->lastOnReady;
        $this->assertNotNull($died);
        $died(Outcome::Failed);

        $this->assertSame('php-ls +failed', $pool->statusFor('/tmp/proj/a.php'), 'a dead companion is named, not collapsed away');
    }

    /**
     * Two providers resolving to the SAME launch are one process, asked once.
     *
     * The pool already shares a session between languages with one signature;
     * within a language a duplicate would otherwise be started, fed and asked
     * twice over.
     */
    public function testTwoServersWithTheSameLaunchAreOneSession(): void
    {
        $pool = $this->pool([
            'php' => [
                ['command' => ['php-ls'], 'name' => 'one'],
                ['command' => ['php-ls'], 'name' => 'two'],
            ],
        ]);

        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);

        $this->assertCount(1, $this->clients);
        $this->assertSame(['textDocument/didOpen'], \array_column($this->clients[0]['client']->notifications, 'method'), 'told once, not twice');
    }

    /**
     * A duplicate in the middle of the list is SKIPPED, not a full stop: the
     * distinct server after it still starts.
     *
     * Bailing out at the first duplicate looks identical whenever the
     * duplicate is last, which is the easy fixture to write and the one that
     * proves nothing.
     */
    public function testADuplicateDoesNotStopTheServersAfterIt(): void
    {
        $pool = $this->pool([
            'php' => [
                ['command' => ['php-ls'], 'name' => 'one'],
                ['command' => ['php-ls'], 'name' => 'the-same-again'],
                ['command' => ['sf-ls'], 'name' => 'three'],
            ],
        ]);

        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);

        $this->assertSame([['php-ls'], ['sf-ls']], \array_column($this->clients, 'command'));
        foreach ($this->clients as $built) {
            $this->assertSame(['textDocument/didOpen'], \array_column($built['client']->notifications, 'method'));
        }
    }

    /**
     * A server that is not running is skipped, and the ones after it are
     * still reached.
     *
     * The first server dies, which evicts its slot; a later notification must
     * still find the second. Stopping at the gap would silently unsubscribe
     * every server behind a dead one.
     */
    public function testANotRunningServerDoesNotHideTheOnesAfterIt(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $died = $this->clients[0]['client']->lastOnReady;
        $this->assertNotNull($died);
        $died(Outcome::Failed); // evicts the FIRST server's slot
        $this->clients[1]['client']->notifications = [];

        $pool->notifyDidSave('/tmp/proj/a.php');

        $this->assertSame(
            ['textDocument/didSave'],
            \array_column($this->clients[1]['client']->notifications, 'method'),
            'the survivor was still told',
        );
    }

    /**
     * A server without the capability is SKIPPED, and the ones after it are
     * still asked.
     *
     * Bailing at the first one that cannot answer looks identical whenever
     * that one is last, which is the easy fixture and the one that proves
     * nothing.
     */
    public function testAServerWithoutTheCapabilityDoesNotHideTheOnesAfterIt(): void
    {
        $capabilities = [['codeLensProvider' => true], [], ['codeLensProvider' => true]];
        $built = [];
        $pool = $this->poolWithCapabilities(
            [
                'php' => [
                    ['command' => ['one'], 'name' => 'one'],
                    ['command' => ['two'], 'name' => 'two'],
                    ['command' => ['three'], 'name' => 'three'],
                ],
            ],
            $capabilities,
            $built,
        );
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $this->activeFile->set('/tmp/proj/a.php');

        $asked = $pool->requestAll('textDocument/codeLens', [], 'codeLensProvider', static function (mixed $r, ?array $e, string $o): void {});

        $this->assertSame([1, 0, 1], \array_map(static fn (FakeLspClient $c): int => \count($c->requests), $built));
        $this->assertSame(2, $asked, 'it says how many it asked');
    }

    /**
     * `ready()` answers the language's OWN server, not whichever answered
     * last — it is the one a feature request will go to.
     */
    public function testReadyAnswersTheFirstServerNotTheLast(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        // Only the SECOND is past initialize, so "the last one that answered"
        // and "the language's own server" give different answers. Written
        // through Probe because a client's readiness is its own to set.
        Probe::set($this->clients[0]['client'], 'isInitialized', false);

        $this->assertNull($pool->ready('/tmp/proj/a.php', new Document('<?php')), 'the language\'s own server is not ready, so nothing is');
        $this->assertTrue($this->clients[1]['client']->isInitialized, 'the other one IS, which is what makes this a real distinction');
    }

    /** A follow-up to a server that has since gone is answered with an error, and asks nobody else. */
    public function testAFollowUpToAServerThatIsGoneFailsAndGoesToNobodyElse(): void
    {
        $pool = $this->pool(self::TWO_ON_PHP);
        $pool->notifyDidOpen('/tmp/proj/a.php', '<?php', 'php', 1);
        $this->activeFile->set('/tmp/proj/a.php');
        foreach ($this->clients as $built) {
            $built['client']->requests = [];
        }

        $error = null;
        $pool->requestTo('a server that never existed', 'completionItem/resolve', [], static function (mixed $r, ?array $e) use (&$error): void {
            $error = $e;
        });
        $this->assertNull($pool->capabilityOf('a server that never existed', 'completionProvider'));
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the answer');
        $this->assertSame(['code' => -32_002, 'message' => 'That language server is no longer running'], $error);

        foreach ($this->clients as $built) {
            $this->assertSame([], $built['client']->requests, 'nobody else was handed it either');
        }
    }

    /**
     * A pool whose clients declare DIFFERENT capabilities, in build order.
     *
     * @param array<string, non-empty-list<array{command: list<string>, name: string}>> $servers
     * @param list<array<string, mixed>>                                                 $capabilities
     * @param list<FakeLspClient>                                                        $built
     */
    private function poolWithCapabilities(array $servers, array $capabilities, array &$built): LspSessionPool
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            $this->activeFile = new FixedActiveFile(),
            new Translator('en'),
            static function (array $command, string $rootDir, \Closure $onLog) use ($capabilities, &$built): FakeLspClient {
                $client = new FakeLspClient($capabilities[\count($built)] ?? []);
                $built[] = $client;

                return $client;
            },
        );
        $pool->configure(
            $servers,
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return $pool;
    }

    public function testARelaunchDueAfterTheServersWereDisabledLaunchesNothing(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $this->die(0);

        $pool->disable();
        $this->pump(Backoff::after(0) + 0.2);

        $this->assertCount(1, $this->clients);
    }

    public function testARelaunchThatFailsToStartTellsItNothing(): void
    {
        $pool = $this->pool(self::SERVERS);
        $pool->notifyDidOpen('/tmp/proj/a.php', 'a', 'php', 1);
        $this->die(0);
        $this->clientOutcome = Outcome::Failed;

        $this->pump(Backoff::after(0) + 0.2);

        $this->assertCount(2, $this->clients, 'tried');
        $this->assertSame([], $this->clients[1]['client']->notifications, 'and with nothing up, nothing sent');
        $pool->stop();
    }
}
