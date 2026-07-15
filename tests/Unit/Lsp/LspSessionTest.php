<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Lsp\LspClientInterface;
use Porta\Lsp\LspSession;
use Porta\Support\Outcome;
use Porta\Tests\Support\FakeLspClient;

/**
 * Unit tests for {@see LspSession} using an injected fake transport — no server
 * process is spawned. Exercises the injected-client-factory branch that the
 * {@see LspClientInterface} seam unlocks.
 */
final class LspSessionTest extends TestCase
{
    private function session(FakeLspClient $client): LspSession
    {
        return new LspSession(
            '/tmp/project',
            static fn (array $command, string $rootDir, \Closure $onLog): LspClientInterface => $client,
        );
    }

    private function start(LspSession $session): void
    {
        $noop = static function (): void {};
        $session->start(['fake'], $noop, static fn (array $p): null => null, static fn (array $e): bool => false, $noop);
    }

    public function testStartUsesInjectedClientAndReportsState(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);

        $this->assertFalse($session->isActive());
        $this->start($session);

        $this->assertTrue($client->started);
        $this->assertTrue($session->isActive());
        $this->assertTrue($session->isInitialized());
    }

    public function testWindowLogMessagesFeedTheLogHook(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $logged = [];
        $noop = static function (): void {};
        $session->start(
            ['fake'],
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            static fn (array $p): null => null,
            static fn (array $e): bool => false,
            $noop,
        );

        $client->notificationHandlers['window/logMessage'](['message' => 'indexing 42 files']);
        $client->notificationHandlers['window/logMessage'](['type' => 3]); // no message field

        $this->assertSame(['indexing 42 files', ''], $logged, 'LSP-level logs ride the same hook as stderr');
    }

    public function testNotifyDidOpenSendsEncodedUri(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->notifyDidOpen('/tmp/a b.php', "<?php\n", 'php', 1);

        $this->assertCount(1, $client->notifications);
        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didOpen', $sent['method']);
        $this->assertSame('file:///tmp/a%20b.php', $sent['params']['textDocument']['uri']);
        $this->assertSame("<?php\n", $sent['params']['textDocument']['text']);
    }

    public function testNotifyFullChangeReplacesEntireText(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $doc = new Document("<?php\necho 1;\n");
        $session->notifyFullChange('/tmp/x.php', $doc);

        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didChange', $sent['method']);
        $this->assertSame([['text' => "<?php\necho 1;\n"]], $sent['params']['contentChanges']);
    }

    public function testScheduleDidChangeWithoutAClientIsANoOp(): void
    {
        $session = $this->session(new FakeLspClient());
        // Never started: no client, so nothing to debounce against.
        $session->scheduleDidChange(new Document('x'), static fn (): ?string => '/tmp/project/a.php', static function (): void {});

        $this->assertFalse($session->isActive());
    }

    public function testReadyRequiresInitializedServerAndFlushesNothingPending(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $doc = new Document("<?php\n");

        // Not started yet: not initialized.
        $this->assertNull($session->ready('/tmp/x.php', $doc));

        $this->start($session);
        $this->assertSame('/tmp/x.php', $session->ready('/tmp/x.php', $doc));
        $this->assertNull($session->ready(null, $doc), 'null path short-circuits');
    }

    public function testRequestAndStopDelegateToClient(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->request('textDocument/hover', ['x' => 1], static function (): void {});
        $this->assertSame('textDocument/hover', $client->requests[0]['method']);

        $session->stop();
        $this->assertTrue($client->stopped);
        $this->assertFalse($session->isInitialized());
    }

    public function testFailedStartClearsClient(): void
    {
        $client = new class extends FakeLspClient {
            public function start(callable $onReady): void
            {
                $this->started = true;
                $onReady(Outcome::Failed); // server failed to initialize
            }
        };
        $session = $this->session($client);
        $this->start($session);

        $this->assertFalse($session->isActive(), 'failed start nulls the client');
    }
}
