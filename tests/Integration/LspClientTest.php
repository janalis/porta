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
            \file_put_contents(self::CAPABILITIES_GOLDEN, \json_encode($params['capabilities'], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $this->markTestSkipped('Golden regenerated.');
        }
        $this->assertSame(
            \json_decode((string) \file_get_contents(self::CAPABILITIES_GOLDEN), associative: true, flags: \JSON_THROW_ON_ERROR),
            $params['capabilities'],
            'the advertised capabilities changed — if intended, rerun with UPDATE_LSP_CAPABILITIES_GOLDEN=1',
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

        $client->notify('porta/blob', ['data' => \str_repeat('a', times: 262_144)]);
        $client->notify('porta/blob', ['data' => \str_repeat('b', times: 262_144)]);
        // No wall-clock assertion here. On the blocking path these notify()
        // calls never return at all — the deaf server never drains its stdin —
        // so reaching the next line is already the proof, and the timer below
        // is what states it. A clock budget added nothing but a failure mode.

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
        $log = '';
        $client = $this->client('stubborn-lsp-server.php', static function (string $line) use (&$log): void {
            $log .= $line;
        });
        $client->start(static function (): void {});
        $this->assertTrue($client->isRunning(), 'the child is up before stop() is exercised');

        // The server never speaks the protocol, so protocol readiness cannot be
        // awaited — but it does say when its SIGTERM-ignore handler is in
        // place, and that is the state this test needs. Terminating it before
        // then kills it outright, which exercises the trivial "already dead"
        // branch and would also make the liveness assertion below a coin toss.
        $this->await(static function () use (&$log): bool {
            return \str_contains($log, 'sigterm-ignored');
        }, what: 'the child to ignore SIGTERM');

        $process = Probe::get(Probe::object($client, 'transport'), 'process');
        \assert(\is_resource($process), description: 'the transport holds the spawned process');
        $pid = \proc_get_status($process)['pid'];

        $client->stop();

        // "Returns immediately" as a fact rather than a stopwatch: the child
        // ignores SIGTERM and only exits after its own sleep, so it is STILL
        // RUNNING here — which is exactly what a proc_close() could not have
        // left behind, since that returns only once the child is dead.
        $this->assertTrue(\posix_kill($pid, signal: 0), 'stop() returned while the child was still alive: the reap is in the background');
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
            $messages = \array_map(static fn (string $l): mixed => \json_decode($l, associative: true, flags: \JSON_THROW_ON_ERROR), $lines);

            $this->assertSame(['jsonrpc', 'id', 'method', 'params'], \array_keys($messages[0] ?? []), 'the initialize request()');
            $this->assertSame('2.0', $messages[0]['jsonrpc'] ?? null);
            $this->assertSame(1, $messages[0]['id'] ?? null, 'the first id ever allocated');
            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => []], $messages[1] ?? null, 'the initialized notify()');

            // Without params, neither carries the member: `null` is not a
            // JSON-RPC params value, and a strict server rejects the message.
            \file_put_contents($log, data: '');
            $client->request('porta/bare', null, static function (): void {});
            $client->notify('porta/bareNote', null);
            $this->await(static fn (): bool => 2 === \substr_count((string) \file_get_contents($log), needle: "\n"), timeout: 15.0, what: 'both bare messages to be logged');
            $lines = \array_values(\array_filter(\explode("\n", (string) \file_get_contents($log))));
            $bare = \array_map(static fn (string $l): mixed => \json_decode($l, associative: true, flags: \JSON_THROW_ON_ERROR), $lines);
            $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'porta/bare'], $bare[0] ?? null, 'a request() without params');
            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'porta/bareNote'], $bare[1] ?? null, 'a notify() without params');
        } finally {
            if (\is_file($log)) {
                \unlink($log);
            }
        }
    }

    public function testWatchedFileRegistrationsAreKeptUntilUnregisteredOrStopped(): void
    {
        $client = $this->client();
        $register = static fn (int $id, mixed $registrations): array => ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'client/registerCapability', 'params' => ['registrations' => $registrations]];

        Probe::invoke($client, 'handleMessage', $register(1, [
            // phpantom's, as it registers them.
            [
                'id' => 'watch',
                'method' => 'workspace/didChangeWatchedFiles',
                'registerOptions' => [
                    'watchers' => [
                        ['globPattern' => '**/*.php', 'kind' => 7],
                        ['globPattern' => '**/composer.lock'], // no kind: all three
                        ['globPattern' => ['baseUri' => 'file:///x', 'pattern' => '*.php']], // a RelativePattern, not declared: skipped
                        'not a watcher',
                    ],
                ],
            ],
            // Any other capability is acknowledged and ignored, as before.
            ['id' => 'fmt', 'method' => 'textDocument/formatting'],
            'not a registration',
        ]));
        Probe::invoke($client, 'handleMessage', $register(2, [
            ['id' => 'json', 'method' => 'workspace/didChangeWatchedFiles', 'registerOptions' => ['watchers' => [['globPattern' => '**/*.json', 'kind' => 2]]]],
        ]));
        $this->assertSame(
            [
                ['glob' => '**/*.php', 'kind' => 7],
                ['glob' => '**/composer.lock', 'kind' => 7],
                ['glob' => '**/*.json', 'kind' => 2],
            ],
            $client->fileWatchers,
        );

        Probe::invoke($client, 'handleMessage', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'client/unregisterCapability',
            'params' => [
                'unregisterations' => [
                    ['id' => 'watch', 'method' => 'workspace/didChangeWatchedFiles'],
                    'not an unregistration',
                ],
            ],
        ]);
        $this->assertSame([['glob' => '**/*.json', 'kind' => 2]], $client->fileWatchers, 'only the unregistered id goes');

        Probe::invoke($client, 'handleMessage', $register(4, registrations: 'not a list'));
        $client->stop();
        $this->assertSame([], $client->fileWatchers, 'a stopped server watches nothing');
    }

    public function testAnErrorNobodyWaitsForReachesTheLogAndAnAnswerDoesNot(): void
    {
        $logged = [];
        $client = $this->client(logger: static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $answered = [];
        $client->request('porta/waited', [], static function (mixed $result, ?array $error) use (&$answered): void {
            $answered[] = [$result, $error];
        });

        // The one request waiting is answered, and nothing is logged for it.
        Probe::invoke($client, 'handleMessage', ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32_601, 'message' => 'unknown']]);
        // An answer nobody waits for (a shutdown's) is dropped quietly.
        Probe::invoke($client, 'handleMessage', ['jsonrpc' => '2.0', 'id' => 9, 'result' => null]);
        // An error nobody waits for, and one the server could not pin to a
        // request, are the server saying something went wrong.
        Probe::invoke($client, 'handleMessage', ['jsonrpc' => '2.0', 'id' => 9, 'error' => ['code' => -32_800, 'message' => 'Canceled']]);
        Probe::invoke($client, 'handleMessage', ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32_600, 'message' => 'Invalid Request']]);

        $this->assertSame([[null, ['code' => -32_601, 'message' => 'unknown']]], $answered);
        $this->assertSame(
            [
                'server error for request 9: {"code":-32800,"message":"Canceled"}',
                'server error: {"code":-32600,"message":"Invalid Request"}',
            ],
            $logged,
        );
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
            $messages = \array_map(static fn (string $l): mixed => \json_decode($l, associative: true, flags: \JSON_THROW_ON_ERROR), $lines);

            // No `params` member at all: JSON-RPC has no null params, and a
            // strict server (symfony-lsp) answers `"params": null` with -32600.
            $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'], $messages[0] ?? null, 'shutdown is sent first, with its own id');
            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'exit'], $messages[1] ?? null, 'exit follows, as a notification (no id)');
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

    public function testARequestWaitingWhenTheServerDiesFailsInsteadOfWaitingForever(): void
    {
        $client = $this->readyClient();
        $answers = [];
        $client->request('porta/never', [], static function (mixed $result, ?array $error) use (&$answers): void {
            $answers[] = $error;
        });

        $process = Probe::get(Probe::get($client, 'transport'), 'process');
        \assert(\is_resource($process), description: 'a live process while the client is up');
        \proc_terminate($process, signal: 9);

        $this->await(static function () use (&$answers): bool {
            return [] !== $answers;
        }, what: 'the waiting request to fail');
        $this->assertSame([['code' => -32_800, 'message' => 'The language server stopped']], $answers);
    }

    public function testStopFailsWhatWaitsAndWhatWaitedForReadyRunsAndFailsInTurn(): void
    {
        $client = $this->client('deaf-lsp-server.php');
        $client->start(static function (): void {});
        $answers = [];
        $client->whenReady(static function () use ($client, &$answers): void {
            $client->request('textDocument/hover', [], static function (mixed $result, ?array $error) use (&$answers): void {
                $answers[] = $error['message'] ?? null;
            });
        });

        $client->stop();
        $this->assertSame([], $answers, 'never from inside stop()');

        $this->await(static function () use (&$answers): bool {
            return [] !== $answers;
        }, what: 'the request queued for ready to run and fail');
        $this->assertSame(['The language server stopped'], $answers);

        $late = null;
        $client->request('textDocument/hover', [], static function (mixed $result, ?array $error) use (&$late): void {
            $late = $error['message'] ?? null;
        });
        $client->whenReady(static function () use (&$late): void {
            $late .= ' then ran';
        });
        $this->await(static function () use (&$late): bool {
            return null !== $late && \str_ends_with($late, ' then ran');
        }, what: 'a request after stop to fail at once');
        $this->assertSame('The language server stopped then ran', $late);
    }

    public function testARequestNobodyAnswersFailsAndIsCancelledAtTheServer(): void
    {
        $log = [];
        $client = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/mock-lsp-server.php'],
            \dirname(__DIR__, levels: 2),
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
            requestTimeout: 0.2,
        );
        $this->clients[] = $client;
        $ready = false;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        }, what: 'the handshake');
        $error = null;
        $client->request('porta/never', [], static function (mixed $result, ?array $answer) use (&$error): void {
            $error = $answer;
        });

        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'the deadline');

        $this->assertSame(['code' => -32_800, 'message' => 'The language server did not answer in 0.2 seconds'], $error);
        $this->assertContains('porta/never got no answer in 0.2 seconds', $log);
        $this->assertTrue($client->isInitialized, 'one slow answer is not a dead server');
    }

    public function testAnAnsweredRequestLeavesNoDeadlineBehind(): void
    {
        $client = $this->readyClient();
        $baseline = \count(EventLoop::getIdentifiers());
        $done = false;
        $client->request('textDocument/completion', ['textDocument' => ['uri' => 'file:///tmp/x.php'], 'position' => ['line' => 0, 'character' => 0]], static function () use (&$done): void {
            $done = true;
        });
        $this->assertCount($baseline + 1, EventLoop::getIdentifiers(), 'a deadline while it waits');

        $this->await(static function () use (&$done): bool {
            return $done;
        }, what: 'the answer');

        $this->assertCount($baseline, EventLoop::getIdentifiers(), 'and none once answered');
    }

    public function testAServerThatNeverAnswersInitializeFailsToStartOnce(): void
    {
        $outcomes = [];
        $client = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/deaf-lsp-server.php'],
            \dirname(__DIR__, levels: 2),
            initializeTimeout: 0.2,
        );
        $this->clients[] = $client;
        $client->start(static function (Outcome $ok) use (&$outcomes): void {
            $outcomes[] = $ok;
        });

        $this->await(static function () use (&$outcomes): bool {
            return [] !== $outcomes;
        }, what: 'the initialize deadline');
        $client->stop(); // its stopped-error must not count as a second failure
        $this->pump(0.1);

        $this->assertSame([Outcome::Failed], $outcomes);
    }

    public function testAWriteThatCannotLandIsADeadServerAtOnce(): void
    {
        $outcomes = [];
        $client = new LspClient(
            [\PHP_BINARY, __DIR__.'/../Support/deaf-lsp-server.php'],
            \dirname(__DIR__, levels: 2),
            maxWriteBuffer: 65_536,
        );
        $this->clients[] = $client;
        $client->start(static function (Outcome $ok) use (&$outcomes): void {
            $outcomes[] = $ok;
        });

        $client->notify('porta/blob', ['data' => \str_repeat('a', times: 65_536)]);
        $client->notify('porta/blob', ['data' => \str_repeat('b', times: 65_536)]);

        $this->assertSame([Outcome::Failed], $outcomes, 'no waiting for an EOF a wedged server may never send');
    }

    public function testAnEditThatThrowsIsStillAnsweredAsNotApplied(): void
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
            }, what: 'the handshake');
            $client->onApplyEdit(static fn (array $edit): bool => throw new \RuntimeException('the disk is full'));

            try {
                Probe::invoke($client, 'handleMessage', ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'workspace/applyEdit', 'params' => ['edit' => []]]);
                $this->fail('the error still reaches whoever handles errors');
            } catch (\RuntimeException $thrown) {
                $this->assertSame('the disk is full', $thrown->getMessage());
            }

            $reply = '{"jsonrpc":"2.0","id":7,"result":{"applied":false,"failureReason":"the disk is full"}}';
            $this->await(static fn (): bool => \str_contains((string) \file_get_contents($log), $reply), what: 'the server to be answered');
            $this->assertStringContainsString($reply, (string) \file_get_contents($log));
        } finally {
            \unlink($log);
        }
    }

    private function readyClient(): LspClient
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        }, what: 'the handshake');

        return $client;
    }

    public function testAServerToldToExitIsLetFinishSavingBeforeItIsEnded(): void
    {
        $saved = \tempnam(\sys_get_temp_dir(), prefix: 'lspsaved');
        $this->assertNotFalse($saved);
        \unlink($saved);
        $client = new LspClient([\PHP_BINARY, __DIR__.'/../Support/flushing-lsp-server.php', $saved], \dirname(__DIR__, levels: 2));
        $this->clients[] = $client;
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static function () use (&$ready): bool {
            return $ready;
        }, what: 'the handshake');

        $client->stop();

        try {
            $this->await(static fn (): bool => \is_file($saved), timeout: 3.0, what: 'the server to finish saving');
            $this->assertSame('saved', \file_get_contents($saved));
        } finally {
            if (\is_file($saved)) {
                \unlink($saved);
            }
        }
    }

    public function testARequestWhoseWriteKillsTheServerFailsAtOnce(): void
    {
        $client = new LspClient([\PHP_BINARY, __DIR__.'/../Support/deaf-lsp-server.php'], \dirname(__DIR__, levels: 2), maxWriteBuffer: 65_536);
        $this->clients[] = $client;
        $client->start(static function (): void {});
        $answer = null;

        $client->request('textDocument/hover', ['blob' => \str_repeat('x', times: 200_000)], static function (mixed $result, ?array $error) use (&$answer): void {
            $answer = $error['message'] ?? null;
        });

        $this->await(
            static function () use (&$answer): bool {
                return null !== $answer;
            },
            timeout: 3.0,
            what: 'the failure, not the 30-second deadline',
        );
        $this->assertSame('The language server stopped', $answer);
    }
}
