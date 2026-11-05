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

    public function testWithNoServerAnyMoreARequestFailsAndWhatWaitsRunsOnTheNextTick(): void
    {
        $client = new FakeLspClient();
        $client->startOutcome = Outcome::Failed;
        $session = $this->session($client);
        $this->start($session);
        $heard = [];

        $session->request('textDocument/hover', [], static function (mixed $result, ?array $error) use (&$heard): void {
            $heard[] = [$result, $error];
        });
        $session->whenReady(static function () use (&$heard): void {
            $heard[] = 'ran';
        });
        $this->assertSame([], $heard, 'not from inside the call');

        $this->await(static function () use (&$heard): bool {
            return 2 === \count($heard);
        }, what: 'both');
        $this->assertSame([[null, ['code' => -32_800, 'message' => 'The language server stopped']], 'ran'], $heard);
        $this->assertSame([], $client->requests, 'nothing reached the stopped client');
    }

    public function testEditsBeforeTheServerIsReadyGoAsOneChangeOfTheLatestText(): void
    {
        $client = new FakeLspClient();
        $client->deferReady = true;
        $session = $this->session($client);
        $this->start($session);
        $session->notifyDidOpen('/p/a.php', 'one', 'php', 1);
        $document = new Document('one');
        foreach (['two', 'three', 'four'] as $text) {
            $document->replaceAll($text);
            $session->notifyFullChange('/p/a.php', $document);
        }
        $session->notifyDidOpen('/p/b.php', 'b', 'php', 1);
        $closed = new Document('gone soon');
        $session->notifyFullChange('/p/b.php', $closed);
        $session->notifyDidClose('/p/b.php');

        $client->ready();

        $changes = \array_values(\array_filter($client->notifications, static fn (array $n): bool => 'textDocument/didChange' === $n['method']));
        $this->assertCount(1, $changes, 'one change, not one per edit');
        $this->assertSame([['text' => 'four']], $changes[0]['params']['contentChanges'], 'the latest, whole');
        $this->assertSame($document->version, $changes[0]['params']['textDocument']['version']);
        $this->assertSame(
            ['textDocument/didOpen', 'textDocument/didChange', 'textDocument/didOpen', 'textDocument/didClose'],
            \array_column($client->notifications, 'method'),
            'in order, and nothing for a file closed meanwhile',
        );
    }

    public function testAFileClosedAndReopenedWhileItStartsSendsItsEditAfterTheReopen(): void
    {
        $client = new FakeLspClient();
        $client->deferReady = true;
        $session = $this->session($client);
        $this->start($session);
        $session->notifyDidOpen('/p/a.php', 'one', 'php', 1);
        $first = new Document('one');
        $first->replaceAll('two');
        $session->notifyFullChange('/p/a.php', $first);
        $session->notifyDidClose('/p/a.php');
        $session->notifyDidOpen('/p/a.php', 'reopened', 'php', 1);
        $second = new Document('reopened');
        $second->replaceAll('edited');
        $session->notifyFullChange('/p/a.php', $second);

        $client->ready();

        $this->assertSame(
            ['textDocument/didOpen', 'textDocument/didClose', 'textDocument/didOpen', 'textDocument/didChange'],
            \array_column($client->notifications, 'method'),
            'the edit after the reopen, not before the close',
        );
        $this->assertSame([['text' => 'edited']], \array_last($client->notifications)['params']['contentChanges']);
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

    /**
     * The pending edit goes to the server under the file it was made in. The
     * editor widget is shared by every buffer, so the path read when the
     * debounce fired was the next tab's after a switch — a.php's text sent as
     * b.php — and a flush for b.php diffed b against itself, dropping a's edit.
     */
    public function testAPendingEditIsSentForItsOwnFileAfterASwitch(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $a = new Document('A-original');
        $b = new Document('B-original');
        $current = '/porta-no-such-root/project/a.php';

        $session->scheduleDidChange(
            $a,
            static function () use (&$current): ?string {
                return $current;
            },
            static function (): void {},
        );
        $current = '/porta-no-such-root/project/b.php'; // the user switched tabs
        $session->ready($current, $b);

        $uris = \array_map(static fn (array $sent): mixed => $sent['params']['textDocument']['uri'] ?? null, $client->notifications);
        $this->assertSame(['file:///porta-no-such-root/project/a.php'], $uris, "a's edit, under a's name");
    }

    /** A tab closed within the debounce takes its pending edit with it: no didChange for a closed document. */
    public function testClosingAFileDropsItsPendingEdit(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $session->scheduleDidChange(new Document('a'), static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});

        $session->notifyDidClose('/porta-no-such-root/project/a.php');
        $session->flushPendingChange(null, null);

        $this->assertSame(['textDocument/didClose'], \array_column($client->notifications, 'method'));
    }

    /** An edit scheduled for another document sends the one still pending, which re-arming the timer used to drop. */
    public function testSchedulingAnotherDocumentSendsThePendingOneFirst(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);

        $session->scheduleDidChange(new Document('a'), static fn (): ?string => '/porta-no-such-root/project/a.php', static function (): void {});
        $session->scheduleDidChange(new Document('b'), static fn (): ?string => '/porta-no-such-root/project/b.php', static function (): void {});

        $uris = \array_map(static fn (array $sent): mixed => $sent['params']['textDocument']['uri'] ?? null, $client->notifications);
        $this->assertSame(['file:///porta-no-such-root/project/a.php'], $uris, 'a went at once; b waits its debounce');
    }

    /**
     * A flush sends the edit early, and what was to follow it still follows.
     *
     * The follow-up is the inlay-hint re-request. Every feature request
     * flushes — document highlight on each caret move among them — so a flush
     * that dropped it meant an edit almost never refreshed the hints: they
     * stayed as the file was when it opened.
     */
    public function testAFlushedEditStillRunsWhatWasToFollowIt(): void
    {
        $client = new FakeLspClient();
        $session = $this->session($client);
        $this->start($session);
        $doc = new Document('x');
        $followed = 0;

        $session->scheduleDidChange($doc, static fn (): ?string => '/porta-no-such-root/project/a.php', static function () use (&$followed): void {
            ++$followed;
        });
        $session->ready('/porta-no-such-root/project/a.php', $doc);
        $this->pump(0.05);

        $this->assertSame(1, $followed, 'the flush sent the edit and then ran the follow-up');
        $this->pump(0.35);
        $this->assertSame(1, $followed, 'once: the cancelled debounce does not run it again');

        $session->flushPendingChange('/porta-no-such-root/project/a.php', $doc);
        $this->pump(0.05);
        $this->assertSame(1, $followed, 'nothing pending, nothing to follow');
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

    /**
     * A server that answers diagnostics only when asked: each file is asked
     * about as it opens and changes, and what it says reaches the same sink
     * a publish does. Closing it clears it.
     */
    public function testAPullServerIsAskedAboutEachFileAsItOpensAndChanges(): void
    {
        $client = new FakeLspClient(['diagnosticProvider' => ['interFileDependencies' => false]]);
        $session = $this->session($client);
        $published = [];
        $noop = static function (): void {};
        $session->start(
            ['fake'],
            $noop,
            static function (array $params) use (&$published): void {
                $published[] = $params;
            },
            static fn (array $e): bool => false,
            $noop,
        );
        $pulls = static fn (): array => \array_values(\array_filter($client->requests, static fn (array $r): bool => 'textDocument/diagnostic' === $r['method']));

        $session->notifyDidOpen('/porta-no-such-root/project/a.php', "<?php\n", 'php', 1);
        $this->assertCount(1, $pulls(), 'asked as it opens');
        $uri = 'file:///porta-no-such-root/project/a.php';
        $pulls()[0]['respond'](['kind' => 'full', 'items' => [['message' => 'Class not found']]]);
        $this->assertSame([['uri' => $uri, 'diagnostics' => [['message' => 'Class not found']], 'pulled' => true]], $published);

        $session->notifyFullChange('/porta-no-such-root/project/a.php', new Document("<?php\nnew A();\n"));
        $this->assertCount(2, $pulls(), 'and again as it changes');
        $session->notifyDidSave('/porta-no-such-root/project/a.php');
        $this->assertCount(2, $pulls(), 'a save changes no other file\'s findings here');

        $client->notificationHandlers['workspace/diagnostic/refresh']([]);
        $this->assertCount(3, $pulls(), 'the server asked for every open file again');

        $session->notifyDidClose('/porta-no-such-root/project/a.php');
        $this->assertSame(['uri' => $uri, 'diagnostics' => [], 'pulled' => true], \end($published), 'closed, it is cleared');
        $client->notificationHandlers['workspace/diagnostic/refresh']([]);
        $this->assertCount(3, $pulls(), 'and no longer asked about');
    }

    public function testWhenFindingsDependOnOtherFilesASaveAsksAboutEveryOpenFile(): void
    {
        $client = new FakeLspClient(['diagnosticProvider' => ['interFileDependencies' => true]]);
        $session = $this->session($client);
        $this->start($session);
        $session->notifyDidOpen('/porta-no-such-root/project/a.php', "<?php\n", 'php', 1);
        $session->notifyDidOpen('/porta-no-such-root/project/b.php', "<?php\n", 'php', 1);

        $session->notifyDidSave('/porta-no-such-root/project/a.php');

        $asked = \array_map(
            static fn (array $r): mixed => $r['params']['textDocument']['uri'] ?? null,
            \array_values(\array_filter($client->requests, static fn (array $r): bool => 'textDocument/diagnostic' === $r['method'])),
        );
        $this->assertSame(
            ['file:///porta-no-such-root/project/a.php', 'file:///porta-no-such-root/project/b.php', 'file:///porta-no-such-root/project/a.php', 'file:///porta-no-such-root/project/b.php'],
            $asked,
        );
    }

    public function testAChangeMadeWhileTheServerStartsIsAskedAboutOnceItIsUp(): void
    {
        $client = new FakeLspClient(['diagnosticProvider' => []]);
        $client->deferReady = true;
        $session = $this->session($client);
        $this->start($session);
        $session->notifyFullChange('/porta-no-such-root/project/a.php', new Document("<?php\n"));

        $client->ready();

        $this->assertSame(['textDocument/diagnostic'], \array_column($client->requests, 'method'));
    }

    public function testAServerThatOnlyPublishesIsNeverAsked(): void
    {
        $client = new FakeLspClient(['diagnosticProvider' => true]);
        $session = $this->session($client);
        $this->start($session);

        $session->notifyDidOpen('/porta-no-such-root/project/a.php', "<?php\n", 'php', 1);
        $session->notifyFullChange('/porta-no-such-root/project/a.php', new Document("<?php\n"));
        $client->notificationHandlers['workspace/diagnostic/refresh']([]);
        $session->notifyDidSave('/porta-no-such-root/project/a.php');
        $session->notifyDidClose('/porta-no-such-root/project/a.php');

        $this->assertSame([], $client->requests);
    }
}
