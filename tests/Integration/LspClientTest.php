<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\LspClient;
use Porta\Support\Outcome;
use Revolt\EventLoop;

final class LspClientTest extends TestCase
{
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

    /**
     * Run the event loop until $done() is true or the timeout elapses.
     */
    private function await(callable $done, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, $suspension->resume(...));
            $suspension->suspend();
            $elapsed += 0.02;
        }
    }

    public function testStopSkipsAnAlreadyClosedPipe(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn (): bool => $ready);

        // Close stderr behind the client's back: stop() must skip the dead
        // resource in its pipe-closing loop and still close the rest cleanly.
        $stderr = new \ReflectionProperty($client, 'stderr')->getValue($client);
        if (\is_resource($stderr)) {
            fclose($stderr);
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

        $this->await(static fn () => null !== $diagnostics);
        $this->assertTrue($initialized);
        $this->assertTrue($client->isInitialized());
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

        $this->await(static fn () => null !== $completions);
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

        $this->await(static fn () => $ready);
        $client->whenReady(static function () use (&$order): void {
            $order[] = 'immediate';
        });

        $this->assertSame(['queued', 'init', 'immediate'], $order);
    }

    public function testRenameHoverReferencesSignature(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);

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

        $this->await(static fn () => 4 === \count($responses));

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

        $this->await(static fn () => [] !== $logs, 2.0);
        $this->assertNull($ready, 'initialize never completes on a crashed server');
        $this->assertFalse($client->isInitialized());
        $this->assertNotEmpty($logs, 'stderr and EOF are logged');
    }

    public function testGarbageOutputIsResynced(): void
    {
        $client = $this->client('garbage-lsp-server.php');

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });

        $this->await(static fn () => null !== $ready, 2.0);
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

        $client->start(static fn () => null);

        // The demanding server sends configuration/applyEdit/unknown requests
        // after initialize, then echoes our replies back as a notification.
        $this->await(static fn () => null !== $echoed, 3.0);

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
        $this->await(static fn () => null !== $ready);

        $this->assertFalse($ready);
        $this->assertFalse($client->isInitialized());
    }

    public function testChunkedResponseFrameIsBuffered(): void
    {
        $client = $this->client('chunked-lsp-server.php');

        $ready = null;
        $client->start(static function (Outcome $ok) use (&$ready): void {
            $ready = $ok->isOk();
        });
        $this->await(static fn () => null !== $ready);

        $this->assertTrue($ready, 'split frame reassembled across reads');
    }

    public function testDrainStdoutGuards(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);

        $drain = new \ReflectionMethod($client, 'drainStdout');

        // Live pipe with no pending data: empty read, not EOF
        $drain->invoke($client);
        $this->assertTrue($client->isRunning());

        // After stop() the pipe is closed: the guard returns immediately
        $client->stop();
        $drain->invoke($client);
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
        $this->await(static fn () => null !== $ready, 2.0);

        $this->assertFalse($ready);
    }

    public function testStopBeforeStartIsSafe(): void
    {
        $client = $this->client();
        $client->stop();

        $this->assertFalse($client->isRunning());
        $this->assertFalse($client->isInitialized());
    }

    public function testSendAfterStopIsSilentlyDropped(): void
    {
        $client = $this->client();
        $ready = false;
        $client->start(static function () use (&$ready): void {
            $ready = true;
        });
        $this->await(static fn () => $ready);

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

        $start = microtime(true);
        $client->notify('porta/blob', ['data' => str_repeat('a', times: 262_144)]);
        $client->notify('porta/blob', ['data' => str_repeat('b', times: 262_144)]);
        $this->assertLessThan(0.5, microtime(true) - $start, 'sends return immediately, bytes queue instead of blocking');

        // The loop stays live: this timer would never fire on the blocking code.
        $ticked = false;
        \Revolt\EventLoop::delay(0.05, static function () use (&$ticked): void {
            $ticked = true;
        });
        $this->await(static fn (): bool => $ticked, 2.0);
        $this->assertTrue($ticked, 'the event loop kept running while the server sat on a full pipe');

        $client->stop();
        $this->assertFalse($client->isRunning());
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
        $this->await(static fn (): bool => $ready);
        $this->assertTrue($ready);

        $expected = [];
        foreach (['x', 'y', 'z'] as $fill) {
            $message = ['jsonrpc' => '2.0', 'method' => 'porta/blob', 'params' => ['data' => str_repeat($fill, times: 131_072)]];
            $frame = \Porta\Support\JsonRpcFramer::encode($message);
            $expected[] = md5(substr($frame, strpos($frame, needle: "\r\n\r\n") + 4));
            $client->notify('porta/blob', $message['params']);
        }

        $this->await(static fn (): bool => 3 === \count($digests), 15.0);
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

        $client->notify('porta/blob', ['data' => str_repeat('a', times: 65_536)]);
        $client->notify('porta/blob', ['data' => str_repeat('b', times: 65_536)]);

        $overflowed = static fn (array $log): bool => [] !== array_filter($log, static fn (string $l): bool => str_contains($l, 'overflow'));
        $this->await(static fn (): bool => $overflowed($log), 2.0);
        $this->assertTrue($overflowed($log), 'the overflow was logged');

        // The wedged server was terminated — the session ends like any crash.
        $this->await(static fn (): bool => !$client->isRunning(), 3.0);
        $this->assertFalse($client->isRunning());
    }
}
