<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\LspClient;
use Porta\Support\JsonRpcFramer;
use Porta\Support\Outcome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Revolt\EventLoop;

final class LspClientTest extends TestCase
{
    use PumpsLoop;

    /** @var list<LspClient> */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }
        $this->clients = [];
    }

    private function client(string $server = 'mock-lsp-server.php', ?callable $logger = null): LspClient
    {
        return $this->clients[] = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/'.$server],
            \dirname(__DIR__, levels: 2),
            $logger,
        );
    }

    private const string CAPABILITIES_GOLDEN = __DIR__.'/../Support/golden/lsp-client-capabilities.json';

    public function testInitializeParamsAdvertiseTheFullClientCapabilitySet(): void
    {
        // No process spawned: initializeParams()/clientCapabilities() are pure,
        // so this reflects the handshake payload directly and cheaply.
        $client = new LspClient(['unused'], '/tmp/proj');

        $params = Probe::invoke($client, 'initializeParams');

        $this->assertSame(\getmypid(), $params['processId']);
        $this->assertSame('file:///tmp/proj', $params['rootUri']);
        $this->assertSame([['uri' => 'file:///tmp/proj', 'name' => 'proj']], $params['workspaceFolders']);
        $this->assertSame(['name' => 'porta', 'version' => '0.1.0'], $params['clientInfo']);
        // The capability set is pinned by a GOLDEN FILE, not by a copy of
        // itself. It used to be restated here in full — seventy lines the
        // duplication gate found, and the copy carried a justification the
        // source did not, so the reason for `tagSupport` lived only in the
        // test that echoed it. The reasons live beside the values now.
        $this->assertFileExists(self::CAPABILITIES_GOLDEN, 'run once with UPDATE_LSP_CAPABILITIES_GOLDEN=1 to seed');
        if (\getenv('UPDATE_LSP_CAPABILITIES_GOLDEN')) {
            \file_put_contents(self::CAPABILITIES_GOLDEN, \json_encode($params['capabilities'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }
        $this->assertSame(
            \json_decode((string) \file_get_contents(self::CAPABILITIES_GOLDEN), associative: true),
            $params['capabilities'],
        );
        $this->assertArrayNotHasKey('initializationOptions', $params, 'no options configured, none sent');
    }

    public function testInitializeParamsCarryTheConfiguredInitializationOptions(): void
    {
        $client = new LspClient(['unused'], '/tmp/proj', initializationOptions: ['workspaceTrust' => false, 'environment' => 'dev']);

        $params = Probe::invoke($client, 'initializeParams');

        $this->assertSame(['workspaceTrust' => false, 'environment' => 'dev'], $params['initializationOptions']);
    }

    /**
     * Run the event loop until $done() is true or the timeout elapses.
     */
    public function testStopSkipsAnAlreadyClosedPipe(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });

        // Close stderr behind the client's back: stop() must skip the dead
        // resource in its pipe-closing loop and still close the rest cleanly.
        $transport = Probe::get($client, 'transport');
        $stderr = Probe::get($transport, 'stderr');
        if (\is_resource($stderr)) {
            \fclose($stderr);
        }

        $client->stop();
        $this->assertFalse($client->isRunning());
    }

    public function testInitializeDidOpenDiagnosticsAndCompletion(): void
    {
        $client = $this->client();

        $initialized = false;
        $diagnostics = null;
        $completions = null;

        $client->onNotification('textDocument/publishDiagnostics', static function (array $params) use (&$diagnostics): void {
            if ('file:///tmp/x.php' === ($params['uri'] ?? '')) { // the mock also publishes for an unknown uri
                $diagnostics = $params;
            }
        });

        $client->start(static function (Outcome $ok) use (&$initialized, $client): void {
            $initialized = $ok->isOk();
            $client->notify('textDocument/didOpen', [
                'textDocument' => ['uri' => 'file:///tmp/x.php', 'languageId' => 'php', 'version' => 1, 'text' => "<?php\n"],
            ]);
        });

        $this->await(static function () use (&$diagnostics): bool {
            return null !== $diagnostics;
        });
        $this->assertTrue($initialized);
        $this->assertTrue($client->isInitialized);
        $this->assertSame('Mock diagnostic', $diagnostics['diagnostics'][0]['message'] ?? null);
        $this->assertSame('file:///tmp/x.php', $diagnostics['uri'] ?? null);

        $client->request(
            'textDocument/completion',
            [
                'textDocument' => ['uri' => 'file:///tmp/x.php'],
                'position' => ['line' => 0, 'character' => 0],
            ],
            static function (mixed $result) use (&$completions): void {
                $completions = $result;
            },
        );

        $this->await(static function () use (&$completions): bool {
            return null !== $completions;
        });
        $this->assertSame('strlen', $completions['items'][0]['label'] ?? null);

        $client->stop();
        $this->assertFalse($client->isRunning());
    }

    public function testWhenReadyQueuesUntilInitialized(): void
    {
        $client = $this->client();

        $order = [];
        $client->whenReady(static function () use (&$order): void {
            $order[] = 'queued';
        });

        $ready = false;
        $client->start(static function () use (&$ready, &$order): void {
            $order[] = 'init';
            $ready = true;
        });

        $this->await(static function () use (&$ready): bool {
            return $ready;
        });
        $client->whenReady(static function () use (&$order): void {
            $order[] = 'immediate';
        });

        $this->assertSame(['queued', 'init', 'immediate'], $order);
        // An immediate call must not also queue the action — it already ran,
        // and there is nothing left to ever drain it a second time.
        $this->assertSame([], Probe::get($client, 'whenReady'));
    }

    public function testRenameHoverReferencesSignature(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });

        $responses = [];
        $collect = static function (string $key) use (&$responses): callable {
            return static function (mixed $result, ?array $error) use ($key, &$responses): void {
                $responses[$key] = ['result' => $result, 'error' => $error];
            };
        };

        $doc = ['textDocument' => ['uri' => 'file:///tmp/p/src/Demo.php'], 'position' => ['line' => 0, 'character' => 0]];
        $client->request('textDocument/hover', $doc, $collect('hover'));
        $client->request('textDocument/signatureHelp', $doc, $collect('signature'));
        $client->request('textDocument/references', $doc + ['context' => ['includeDeclaration' => true]], $collect('references'));
        $client->request('textDocument/rename', $doc + ['newName' => 'Foo'], $collect('rename'));

        $this->await(static function () use (&$responses): bool {
            return 4 === \count($responses);
        });

        $this->assertStringContainsString('greet(): string', $responses['hover']['result']['contents']['value']);
        $this->assertSame('greet(string $name): string', $responses['signature']['result']['signatures'][0]['label']);
        $this->assertCount(3, $responses['references']['result']);
        $this->assertArrayHasKey('changes', $responses['rename']['result']);
    }

    public function testServerCrashIsHandledGracefully(): void
    {
        $client = $this->client('crashing-lsp-server.php');

        $ready = null;
        $logs = [];
        $client = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/crashing-lsp-server.php'],
            \dirname(__DIR__, levels: 2),
            static function (string $line) use (&$logs): void {
                $logs[] = $line;
            },
        );
        $this->clients[] = $client;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });

        $this->await(
            static function () use (&$ready): bool {
                return null !== $ready;
            },
            2.0,
            what: 'the crash to surface as a start outcome',
        );
        $this->assertFalse($ready, 'EOF before initialize reports Failed — "starting…" forever was the old defect');
        $this->assertFalse($client->isInitialized);
        $this->assertNotEmpty($logs, 'stderr and EOF are logged');
    }

    public function testAMidSessionServerDeathReportsFailedAndGatesFeatures(): void
    {
        // The server initializes fine, then dies. The second start-outcome is
        // what flips the pool's status from the server's name to "failed"
        // instead of advertising a dead process, and isInitialized() must go
        // false so feature requests decline like an unserved language.
        $outcomes = [];
        $client = $this->client();
        $client->start(static function (Outcome $ok) use (&$outcomes): void {
            $outcomes[] = $ok;
        });
        $this->await(static function () use (&$outcomes): bool {
            return [] !== $outcomes;
        }, what: 'the handshake to complete');
        $this->assertSame([Outcome::Ok], $outcomes);
        $this->assertTrue($client->isInitialized);

        $process = Probe::get(Probe::get($client, 'transport'), 'process');
        \assert(\is_resource($process), description: 'the transport holds a live process while the client is up');
        \proc_terminate($process, signal: 9);

        $this->await(static function () use (&$outcomes): bool {
            return 2 === \count($outcomes);
        }, what: 'the mid-session death to surface');
        $this->assertSame([Outcome::Ok, Outcome::Failed], $outcomes);
        $this->assertFalse($client->isInitialized, 'a dead server is not an initialized one');
    }

    public function testAServerThatExitsSilentlyBeforeInitializeReportsFailed(): void
    {
        // Spawn SUCCEEDS (php is real) but the "server" exits at once with no
        // output at all — the deferred-exec/instant-crash shape, distinct from
        // the spawn failure the $started check already caught. The initialize
        // response can never arrive, so the EOF is the only failure signal.
        $outcome = null;
        $client = new LspClient([\PHP_BINARY, '-r', 'exit(1);'], \dirname(__DIR__, levels: 2));
        $this->clients[] = $client;
        $client->start(static function (Outcome $ok) use (&$outcome): void {
            $outcome = $ok;
        });

        $this->await(static function () use (&$outcome): bool {
            return null !== $outcome;
        }, what: 'the EOF-before-initialize failure to surface');
        $this->assertSame(Outcome::Failed, $outcome);
        $this->assertFalse($client->isInitialized);
    }

    public function testGarbageOutputIsResynced(): void
    {
        $client = $this->client('garbage-lsp-server.php');

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });

        $this->await(static function () use (&$ready): bool {
            return null !== $ready;
        }, 2.0);
        $this->assertTrue($ready, 'valid frame after garbage still parsed');
    }

    public function testServerSideRequestsGetDefaultReplies(): void
    {
        $client = $this->client('demanding-lsp-server.php');

        $applied = null;
        $client->onApplyEdit(static function (array $edit) use (&$applied): bool {
            $applied = $edit;

            return true;
        });

        $echoed = null;
        $client->onNotification('mock/echo', static function (array $params) use (&$echoed): void {
            $echoed = $params;
        });

        $client->start(static fn (): null => null);

        // The demanding server sends configuration/applyEdit/unknown requests
        // after initialize, then echoes our replies back as a notification.
        $this->await(static function () use (&$echoed): bool {
            return null !== $echoed;
        }, 3.0);

        $this->assertNotNull($applied, 'applyEdit handler received the edit');
        $this->assertSame([null], $echoed['configuration'], 'workspace/configuration answered with nulls');
        $this->assertTrue($echoed['applyEdit']['applied']);
        $this->assertNull($echoed['unknown'], 'unknown request answered with null result');
    }

    public function testInitializeErrorReportsNotReady(): void
    {
        $client = $this->client('error-lsp-server.php', static function (): void {});

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return null !== $ready;
        });

        $this->assertFalse($ready);
        $this->assertFalse($client->isInitialized);
    }

    public function testChunkedResponseFrameIsBuffered(): void
    {
        $client = $this->client('chunked-lsp-server.php');

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return null !== $ready;
        });

        $this->assertTrue($ready, 'split frame reassembled across reads');
    }

    public function testDrainStdoutGuards(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });

        $transport = Probe::get($client, 'transport');
        $onMessage = static function (): void {};

        // Live pipe with no pending data: empty read, not EOF
        Probe::invoke($transport, 'drainStdout', $onMessage, null);
        $this->assertTrue($client->isRunning());

        // After stop() the pipe is closed: the guard returns immediately
        $client->stop();
        Probe::invoke($transport, 'drainStdout', $onMessage, null);
        $this->assertFalse($client->isRunning());
    }

    public function testStartFailureWithBogusCommand(): void
    {
        $client = new LspClient(['/nonexistent-binary-xyz'], \dirname(__DIR__, levels: 2));
        $this->clients[] = $client;

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return null !== $ready;
        }, 2.0);

        $this->assertFalse($ready);
    }

    public function testStopBeforeStartIsSafe(): void
    {
        $client = $this->client();
        $client->stop();

        $this->assertFalse($client->isRunning());
        $this->assertFalse($client->isInitialized);
    }

    public function testSendAfterStopIsSilentlyDropped(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });

        $client->stop();
        $client->notify('textDocument/didSave', ['textDocument' => ['uri' => 'file:///x']]);

        $this->assertFalse($client->isRunning());
    }

    public function testAWedgedServerNeverBlocksTheEventLoop(): void
    {
        // The freeze regression: a server that stops draining its stdin used to
        // park the whole loop inside a blocking fwrite once a frame exceeded
        // the OS pipe buffer — screen, keyboard and mouse all dead.
        $client = $this->client('deaf-lsp-server.php');
        $client->start(static function (): void {});

        $start = \microtime(true);
        $client->notify('porta/blob', ['data' => \str_repeat('a', times: 262_144)]);
        $client->notify('porta/blob', ['data' => \str_repeat('b', times: 262_144)]);
        $this->assertLessThan(0.5, \microtime(true) - $start, 'sends return immediately, bytes queue instead of blocking');

        // The loop stays live: this timer would never fire on the blocking code.
        $ticked = false;
        EventLoop::delay(0.05, static function () use (&$ticked): void {
            $ticked = true;
        });
        $this->await(static function () use (&$ticked): bool {
            return $ticked;
        }, 2.0);
        $this->assertTrue($ticked, 'the event loop kept running while the server sat on a full pipe');

        $client->stop();
        $this->assertFalse($client->isRunning());
    }

    public function testStopReapsAStragglerThatIgnoresSigtermWithoutBlockingTheLoop(): void
    {
        // The freeze regression: JsonRpcTransport::stop() used to proc_close()
        // the child, which blocks until it actually exits — so a server that
        // shrugs off SIGTERM parked the whole IDE for its remaining lifetime.
        // The server never speaks the protocol, so this never awaits readiness.
        $client = $this->client('stubborn-lsp-server.php');
        $client->start(static function (): void {});
        $this->assertTrue($client->isRunning(), 'the child is up before stop() is exercised');

        $before = \microtime(true);
        $client->stop();
        $this->assertLessThan(0.5, \microtime(true) - $before, 'stop() returns immediately, the reap happens in the background');
        $this->assertFalse($client->isRunning());
    }

    public function testStopSendsThePoliteShutdownSequenceBeforeTerminating(): void
    {
        // isRunning() clears the moment stop() is called (it just nulls the
        // held process resource) — it cannot tell a graceful exit from a
        // still-alive orphan, so this checks the real OS process. The server
        // ignores SIGTERM entirely and only exits on the JSON-RPC `exit`
        // notification, so it only dies here if stop() actually sent it.
        $client = new LspClient([\PHP_BINARY, __DIR__.'/../Support/sigterm-immune-lsp-server.php'], \dirname(__DIR__, levels: 2));
        $this->clients[] = $client;
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });

        $transport = Probe::get($client, 'transport');
        $process = Probe::get($transport, 'process');
        \assert(\is_resource($process), description: 'the transport holds the spawned process');
        $pid = \proc_get_status($process)['pid'];

        $client->stop();

        $this->await(static fn (): bool => false === \posix_kill($pid, signal: 0), timeout: 3.0, what: 'the child to exit via the polite shutdown, not linger ignoring SIGTERM');
        $this->assertFalse(\posix_kill($pid, signal: 0), 'the child process no longer exists');
    }

    public function testRequestAndNotifyEnvelopesCarryTheJsonRpcVersionAndId(): void
    {
        $log = \tempnam(\sys_get_temp_dir(), prefix: 'lsplog');
        $this->assertNotFalse($log);
        try {
            $client = new LspClient([\PHP_BINARY, __DIR__.'/../Support/logging-lsp-server.php', $log], \dirname(__DIR__, levels: 2));
            $this->clients[] = $client;
            $ready = false;
            $client->start(static function () use (&$ready): void {
                $ready = true;
            });
            $this->await(static function () use (&$ready): bool {
                return $ready;
            });
            // $ready fires once the initialize RESPONSE is processed, but the
            // client's own `initialized` notify() is a separate, independent
            // write — await its arrival in the log too, or this races (and is
            // markedly more likely to lose under coverage/PCOV instrumentation).
            $this->await(
                static fn (): bool => 2 === \substr_count((string) \file_get_contents($log), needle: "\n"),
                timeout: 15.0,
                what: 'both the initialize request and the initialized notify to be logged',
            );

            $lines = \array_values(\array_filter(\explode("\n", (string) \file_get_contents($log))));
            $messages = \array_map(static fn (string $l): mixed => \json_decode($l, associative: true), $lines);

            $this->assertSame(['jsonrpc', 'id', 'method', 'params'], \array_keys($messages[0] ?? []), 'the initialize request()');
            $this->assertSame('2.0', $messages[0]['jsonrpc'] ?? null);
            $this->assertSame(1, $messages[0]['id'] ?? null, 'the first id ever allocated');
            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []], $messages[1] ?? null, 'the initialized notify()');
        } finally {
            if (\is_file($log)) {
                \unlink($log);
            }
        }
    }

    public function testStopSendsShutdownThenExitWithTheCorrectEnvelopes(): void
    {
        $log = \tempnam(\sys_get_temp_dir(), prefix: 'lsplog');
        $this->assertNotFalse($log);
        try {
            $client = new LspClient([\PHP_BINARY, __DIR__.'/../Support/logging-lsp-server.php', $log], \dirname(__DIR__, levels: 2));
            $this->clients[] = $client;
            $ready = false;
            $client->start(static function () use (&$ready): void {
                $ready = true;
            });
            $this->await(static function () use (&$ready): bool {
                return $ready;
            });
            // $ready fires once the initialize RESPONSE is processed, but the
            // client's own `initialized` notify() is a separate, independent
            // write — await its arrival before clearing the log, or a slow
            // delivery can land it AFTER the clear, corrupting the "shutdown
            // is sent first" assertion below with a stray `initialized` line.
            $this->await(
                static fn (): bool => 2 === \substr_count((string) \file_get_contents($log), needle: "\n"),
                timeout: 30.0,
                what: 'the initialize handshake to fully land before clearing the log',
            );
            \file_put_contents($log, data: ''); // drop the initialize entry — only the stop() sequence matters here

            $client->stop();
            // A generous timeout: under the full paratest+PCOV coverage run
            // (many parallel workers, each spawning its own child process),
            // this child's scheduling and this log file's write/read round
            // trip have both been observed to take well over 10s — 20s still
            // was not always enough.
            $this->await(static fn (): bool => 2 === \substr_count((string) \file_get_contents($log), needle: "\n"), timeout: 90.0, what: 'the shutdown and exit notifications to both arrive');

            $lines = \array_values(\array_filter(\explode("\n", (string) \file_get_contents($log))));
            $messages = \array_map(static fn (string $l): mixed => \json_decode($l, associative: true), $lines);

            $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown', 'params' => null], $messages[0] ?? null, 'shutdown is sent first, with its own id');
            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'exit', 'params' => null], $messages[1] ?? null, 'exit follows, as a notification (no id)');
        } finally {
            if (\is_file($log)) {
                \unlink($log);
            }
        }
    }

    public function testQueuedFramesArriveIntactAndInOrderAcrossPartialWrites(): void
    {
        $digests = [];
        $client = $this->client('slow-reading-lsp-server.php');
        $client->onNotification('porta/digest', static function (array $params) use (&$digests): void {
            if ('porta/blob' === ($params['method'] ?? '')) {
                $digests[] = $params['md5'] ?? '';
            }
        });
        $ready = false;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        });
        $this->assertTrue($ready);

        $expected = [];
        foreach (['x', 'y', 'z'] as $fill) {
            $message = ['jsonrpc' => '2.0', 'method' => 'porta/blob', 'params' => ['data' => \str_repeat($fill, times: 131_072)]];
            $frame = JsonRpcFramer::encode($message);
            $expected[] = \md5(\substr($frame, \strpos($frame, needle: "\r\n\r\n") + 4));
            $client->notify('porta/blob', $message['params']);
        }

        $this->await(static function () use (&$digests): bool {
            return 3 === \count($digests);
        }, 15.0);
        $this->assertSame($expected, $digests, 'all frames crossed the slow pipe intact and in send order');
    }

    public function testWriteQueueOverflowTerminatesTheWedgedServer(): void
    {
        $log = [];
        $client = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/deaf-lsp-server.php'],
            \dirname(__DIR__, levels: 2),
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
            maxWriteBuffer: 65_536,
        );
        $this->clients[] = $client;
        $client->start(static function (): void {});

        $client->notify('porta/blob', ['data' => \str_repeat('a', times: 65_536)]);
        $client->notify('porta/blob', ['data' => \str_repeat('b', times: 65_536)]);

        $overflowed = static fn (array $log): bool => [] !== \array_filter($log, static fn (string $l): bool => \str_contains($l, 'overflow'));
        $this->await(static function () use (&$log, &$overflowed): bool {
            return $overflowed($log);
        }, 2.0);
        $this->assertTrue($overflowed($log), 'the overflow was logged');

        // The wedged server was terminated — the session ends like any crash.
        $this->await(static function () use (&$client): bool {
            return !$client->isRunning();
        }, 3.0);
        $this->assertFalse($client->isRunning());
    }

    /**
     * The stdout-EOF path must cancel BOTH pipe watchers: a stderr watcher
     * left armed on the dead peer's pipe would spin on every loop tick (and
     * anchor the transport) until an owner stop() an abandoned client never
     * issues — the [loop-leak] regression this pins.
     */
    public function testPeerExitClearsEveryLoopWatcher(): void
    {
        $baseline = \count(EventLoop::getIdentifiers());
        $client = $this->client('crashing-lsp-server.php');
        $client->start(static function (): void {});
        $this->assertGreaterThan($baseline, \count(EventLoop::getIdentifiers()), 'the pipe watchers are armed');

        // The crashing server exits immediately; EOF must retire the watchers.
        $this->await(static fn (): bool => \count(EventLoop::getIdentifiers()) <= $baseline, 5.0, 'the EOF path to cancel both pipe watchers');
        $this->assertCount($baseline, EventLoop::getIdentifiers(), 'no watcher survives the peer');
    }
}
