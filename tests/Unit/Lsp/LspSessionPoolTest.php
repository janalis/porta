<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Editor\Document;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;

final class LspSessionPoolTest extends TestCase
{
    /** @var list<array{command: list<string>, client: FakeLspClient}> every client the pool built */
    private array $clients = [];
    private ?string $activePath = null;
    private int $statusRepaints = 0;

    private function pool(array $servers, bool $configure = true): LspSessionPool
    {
        $this->clients = [];
        $pool = new LspSessionPool(
            '/tmp/proj',
            fn (): ?string => $this->activePath,
            new Translator('en'),
            function (array $command, string $rootDir, \Closure $onLog): FakeLspClient {
                $client = new FakeLspClient();
                $this->clients[] = ['command' => $command, 'client' => $client];

                return $client;
            },
        );
        if ($configure) {
            $pool->configure(
                $servers,
                static function (string $line): void {},
                static function (array $params): void {},
                static fn (array $edit): bool => false,
                function (): void { ++$this->statusRepaints; },
            );
        }

        return $pool;
    }

    private const array SERVERS = [
        'php' => ['command' => ['php-ls'], 'name' => 'php-ls'],
        'javascript' => ['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls'],
        'typescript' => ['command' => ['ts-ls', '--stdio'], 'name' => 'ts-ls'],
    ];

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

        $this->activePath = '/tmp/proj/b.ts';
        $pool->request('textDocument/definition', ['x' => 1], static function (): void {});

        $this->assertCount(0, array_filter($this->clients[0]['client']->requests));
        $this->assertSame('textDocument/definition', $this->clients[1]['client']->requests[0]['method']);
        $this->assertTrue($pool->isActive());
        $this->assertSame('/tmp/proj/b.ts', $pool->ready('/tmp/proj/b.ts', new Document('y')));

        // With no active file, workspace-level calls (symbol search,
        // executeCommand) fall back to the running PHP/catch-all session.
        $this->activePath = null;
        $this->assertTrue($pool->isActive());
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

    public function testStatusForPhpWithoutAServerKeepsTheInstallHint(): void
    {
        $pool = $this->pool([]);
        $this->assertSame('none (install phpactor)', $pool->statusFor('/tmp/proj/a.php'));
        $this->assertSame('no server', $pool->statusFor('/tmp/proj/b.ts'));
        $this->assertSame('no server', $pool->statusFor(null));
    }

    public function testDisabledAndUnconfiguredStates(): void
    {
        $pool = $this->pool([], configure: false);
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
