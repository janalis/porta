<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Lsp\FlushMode;
use Porta\Lsp\LspClientInterface;
use Porta\Lsp\LspSession;
use Porta\Support\Outcome;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

/**
 * Unit tests for {@see LspSession} using an injected fake transport — no server
 * process is spawned. Exercises the injected-client-factory branch that the
 * {@see LspClientInterface} seam unlocks.
 */
final class LspSessionTest extends TestCase
{
    /**
     * Paths live under a root that cannot exist, never under `/tmp`.
     *
     * `Uri::fromPath()` calls `realpath()`, which resolves a path only when
     * the file is actually there — and on macOS `/tmp` is a symlink to
     * `/private/tmp`. So `file:///tmp/x.php` was the right answer exactly
     * while nothing happened to occupy `/tmp/x.php`; the day another project
     * left a file there, three tests here started failing with
     * `file:///private/tmp/x.php` and nothing in this repository had changed.
     */

    use PumpsLoop;

    private function session(FakeLspClient $client): LspSession
    {
        return new LspSession(
            '/porta-no-such-root/project',
            static fn (array $command, string $rootDir, \Closure $onLog, ?array $options): LspClientInterface => $client,
        );
    }

    private function start(LspSession $session): void
    {
        $noop = static function (): void {};
        $session->start(['fake'], $noop, static fn (array $p): null => null, static fn (array $e): bool => false, $noop);
    }

    public function testStartForwardsInitializationOptionsToTheFactory(): void
    {
        $received = [];
        $session = new LspSession(
            '/porta-no-such-root/project',
            static function (array $command, string $rootDir, \Closure $onLog, ?array $options) use (&$received): LspClientInterface {
                $received[] = $options;

                return new FakeLspClient();
            },
        );
        $noop = static function (): void {};

        $session->start(['fake'], $noop, static fn (array $p): null => null, static fn (array $e): bool => false, $noop, ['workspaceTrust' => true]);
        $this->assertSame([['workspaceTrust' => true]], $received);

        $session->start(['fake'], $noop, static fn (array $p): null => null, static fn (array $e): bool => false, $noop);
        $this->assertNull($received[1], 'omitting the argument hands the factory null');
    }

    public function testAFailedStartStopsTheClientBeforeDroppingIt(): void
    {
        // Dropping the reference without stop() would orphan a server that
        // closed stdout but kept running, and its GC'd proc handle does a
        // blocking waitpid on the loop.
        $client = new FakeLspClient();
        $client->startOutcome = Outcome::Failed;
        $session = $this->session($client);

        $this->start($session);

        $this->assertTrue($client->stopped, 'the dropped client was terminated and reaped, not leaked');
        $this->assertFalse($session->isActive());
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

    public function testDiagnosticsAndApplyEditHooksAreWiredToTheClient(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $diagnostics = [];
        $applied = [];
        $noop = static function (): void {};
        $session->start(
            ['fake'],
            $noop,
            static function (array $p) use (&$diagnostics): void {
                $diagnostics[] = $p;
            },
            static function (array $e) use (&$applied): bool {
                $applied[] = $e;

                return true;
            },
            $noop,
        );

        $client->notificationHandlers['textDocument/publishDiagnostics'](['uri' => 'file:///x.php', 'diagnostics' => []]);
        $this->assertCount(1, $diagnostics, 'the onDiagnostics hook reached the client');

        $result = ($client->applyEditHandler)(['changes' => []]);
        $this->assertCount(1, $applied, 'the onApplyEdit hook reached the client');
        $this->assertTrue($result);
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

        $session->notifyDidOpen('/porta-no-such-root/a b.php', "<?php\n", 'php', 1);

        $this->assertCount(1, $client->notifications);
        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didOpen', $sent['method']);
        $this->assertSame('file:///porta-no-such-root/a%20b.php', $sent['params']['textDocument']['uri']);
        $this->assertSame("<?php\n", $sent['params']['textDocument']['text']);
    }

    public function testNotifyFullChangeReplacesEntireText(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $doc = new Document("<?php\necho 1;\n");
        $session->notifyFullChange('/porta-no-such-root/x.php', $doc);

        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didChange', $sent['method']);
        $this->assertSame([['text' => "<?php\necho 1;\n"]], $sent['params']['contentChanges']);
        $this->assertSame('file:///porta-no-such-root/x.php', $sent['params']['textDocument']['uri']);
        $this->assertSame($doc->version, $sent['params']['textDocument']['version']);
    }

    public function testNotifyDidCloseSendsTheDocumentIdentifier(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->notifyDidClose('/porta-no-such-root/x.php');

        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didClose', $sent['method']);
        $this->assertSame(['textDocument' => ['uri' => 'file:///porta-no-such-root/x.php']], $sent['params']);
    }

    /**
     * closeUnder() answers from the session's OWN open set, so a folder rename
     * closes exactly the files inside it and a never-opened path is never
     * announced as closed.
     */
    public function testCloseUnderClosesTheOpenFilesInsideAFolderAndNothingElse(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        // The two that do NOT match are opened FIRST and in between, because
        // $syncedText keeps insertion order: with every match at the front, a
        // loop that stopped at the first miss instead of skipping it would
        // still close everything this asserts on. That mutant escaped a real
        // run of the first draft of this test.
        $session->notifyDidOpen('/porta-no-such-root/other/d.php', 'd', 'php', 1);
        $session->notifyDidOpen('/porta-no-such-root/old/a.php', 'a', 'php', 1);
        // A SIBLING whose path merely starts with the root's characters. The
        // boundary the naive str_starts_with() spelling gets wrong, and the
        // only reason this is a separate case from the one below.
        $session->notifyDidOpen('/porta-no-such-root/oldish/c.php', 'c', 'php', 1);
        $session->notifyDidOpen('/porta-no-such-root/old/deep/b.php', 'b', 'php', 1);
        $client->notifications = [];

        $session->closeUnder('/porta-no-such-root/old');

        $this->assertSame(
            ['file:///porta-no-such-root/old/a.php', 'file:///porta-no-such-root/old/deep/b.php'],
            \array_map(static fn (array $sent): string => $sent['params']['textDocument']['uri'], $client->notifications),
        );
        $this->assertSame(
            ['textDocument/didClose', 'textDocument/didClose'],
            \array_map(static fn (array $sent): string => $sent['method'], $client->notifications),
        );
    }

    /** A plain file is its own subtree: the root IS the open document. */
    public function testCloseUnderClosesTheFileWhenTheRootIsOne(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $session->notifyDidOpen('/porta-no-such-root/a.php', 'a', 'php', 1);
        $client->notifications = [];

        $session->closeUnder('/porta-no-such-root/a.php');

        $this->assertSame(
            [['method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => 'file:///porta-no-such-root/a.php']]]],
            $client->notifications,
        );
    }

    /** Nothing open under it: no server is told about a document it never had. */
    public function testCloseUnderSaysNothingAboutAPathThatWasNeverOpened(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $session->notifyDidOpen('/porta-no-such-root/a.php', 'a', 'php', 1);
        $client->notifications = [];

        $session->closeUnder('/porta-no-such-root/never-opened.php');

        $this->assertSame([], $client->notifications);
    }

    public function testNotifyDidSaveSendsTheDocumentIdentifier(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->notifyDidSave('/porta-no-such-root/x.php');

        $sent = $client->notifications[0];
        $this->assertSame('textDocument/didSave', $sent['method']);
        $this->assertSame(['textDocument' => ['uri' => 'file:///porta-no-such-root/x.php']], $sent['params']);
    }

    public function testScheduleDidChangeWithoutAClientIsANoOp(): void
    {
        $session = $this->session(new FakeLspClient());
        $before = \count(EventLoop::getIdentifiers());
        // Never started: no client, so nothing to debounce against.
        $session->scheduleDidChange(new Document('x'), static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});

        $this->assertFalse($session->isActive());
        $this->assertCount($before, EventLoop::getIdentifiers(), 'no debounce timer is armed without a client');
    }

    public function testEveryTransportCallIsInertBeforeStart(): void
    {
        // None of these guard with an early return before touching $this->client
        // — they all lean on the nullsafe operator. A never-started session
        // (client still null) must survive every one of them without crashing.
        $client = new FakeLspClient();
        $session = $this->session($client);

        $session->stop();
        $session->request('textDocument/hover', [], static function (): void {});
        $session->whenReady(static function (): void {});
        $session->notifyDidOpen('/porta-no-such-root/x.php', 'text', 'php', 1);
        $session->notifyDidClose('/porta-no-such-root/x.php');
        $session->notifyDidSave('/porta-no-such-root/x.php');
        $session->notifyFullChange('/porta-no-such-root/x.php', new Document('x'));

        $this->assertNull($session->serverCapability('inlayHintProvider'), 'no client yet — no capabilities to read');
        $this->assertFalse($session->isActive());
        $this->assertSame([], $client->notifications, 'the client was never started — nothing to notify');
        $this->assertSame([], $client->requests);
    }

    public function testReadyRequiresInitializedServerAndFlushesNothingPending(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $doc = new Document("<?php\n");

        // Not started yet: not initialized.
        $this->assertNull($session->ready('/porta-no-such-root/x.php', $doc));

        $this->start($session);
        $this->assertSame('/porta-no-such-root/x.php', $session->ready('/porta-no-such-root/x.php', $doc));
        $this->assertNull($session->ready(null, $doc), 'null path short-circuits');
    }

    public function testReadyFlushesAPendingDebounceUnlessToldToKeepIt(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $doc = new Document('x');

        $session->scheduleDidChange($doc, static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});
        $before = \count(EventLoop::getIdentifiers());

        $session->ready('/porta-no-such-root/project/a.php', $doc, FlushMode::Keep);
        $this->assertCount($before, EventLoop::getIdentifiers(), 'Keep leaves the debounce armed');
        $this->assertSame([], \array_column($client->notifications, 'method'), 'and sends nothing yet');

        $session->ready('/porta-no-such-root/project/a.php', $doc, FlushMode::Flush);
        $this->assertLessThan($before, \count(EventLoop::getIdentifiers()), 'Flush (the default) cancels the pending debounce');
        $this->assertSame(['textDocument/didChange'], \array_column($client->notifications, 'method'), 'and sends the pending edit immediately');
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

    public function testAFlushedEditRidesTheIncrementalPathNotFullText(): void
    {
        // An incremental-sync (kind 2) server with an established baseline:
        // the flush a feature request forces (typing `->` triggers completion)
        // must diff against that baseline, not re-ship the whole file — the
        // old full-text flush made the incremental path nearly unreachable.
        $client = new FakeLspClient(['textDocumentSync' => 2]);
        $session = $this->session($client);
        $this->start($session);

        $doc = new Document("<?php\necho 1;\n");
        $session->notifyFullChange('/porta-no-such-root/project/a.php', $doc); // baseline
        $doc->setCursor(1, 6);
        $doc->insert('2'); // "echo 12;"

        $session->scheduleDidChange($doc, static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});
        $session->flushPendingChange('/porta-no-such-root/project/a.php', $doc);

        $sent = \end($client->notifications);
        $this->assertNotFalse($sent);
        $this->assertSame('textDocument/didChange', $sent['method']);
        $change = $sent['params']['contentChanges'][0];
        $this->assertArrayHasKey('range', $change, 'a ranged delta, not a whole-document replacement');
        $this->assertSame('2', $change['text'], 'exactly the inserted character travels');
    }

    public function testAFlushForAFullSyncServerStillSendsTheWholeText(): void
    {
        // No incremental capability: the flush falls back to full text.
        $client = new FakeLspClient(['textDocumentSync' => 1]);
        $session = $this->session($client);
        $this->start($session);

        $doc = new Document("<?php\necho 1;\n");
        $session->scheduleDidChange($doc, static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});
        $session->flushPendingChange('/porta-no-such-root/project/a.php', $doc);

        $sent = \end($client->notifications);
        $this->assertNotFalse($sent);
        $this->assertSame([['text' => "<?php\necho 1;\n"]], $sent['params']['contentChanges']);
    }

    public function testFlushPendingChangeNoOpsWithNothingPending(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->flushPendingChange('/porta-no-such-root/x.php', new Document('x'));

        $this->assertSame([], $client->notifications, 'nothing was scheduled — nothing to flush');
    }

    public function testStopCancelsAnArmedChangeDebounce(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $before = \count(EventLoop::getIdentifiers());

        $session->scheduleDidChange(new Document('x'), static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});
        $this->assertGreaterThan($before, \count(EventLoop::getIdentifiers()), 'the 0.3s debounce is armed');

        $session->stop();
        $this->assertCount($before, EventLoop::getIdentifiers(), 'stop() cancels the debounce — nothing may fire into the next loop consumer');

        $this->pump(0.35); // past the debounce window
        $sent = \array_column($client->notifications, 'method');
        $this->assertNotContains('textDocument/didChange', $sent, 'no late didChange after stop');
    }

    public function testADebounceFiringForAClosedBufferSendsNothing(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        // The buffer closes before the debounce fires: resolvePath answers null
        // and the didChange is dropped.
        $session->scheduleDidChange(new Document('x'), static fn (): ?string => null, static function (): void {});
        $this->pump(0.35);

        $sent = \array_column($client->notifications, 'method');
        $this->assertNotContains('textDocument/didChange', $sent, 'a closed buffer sends no didChange');
    }
}
