<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AsyncProcess;
use Porta\Support\JsonRpcTransport;
use Porta\Support\ProcessEnvironment;
use Porta\Terminal\PtyProcess;
use Porta\Tests\Support\PumpsLoop;

final class ProcessEnvironmentTest extends TestCase
{
    use PumpsLoop;

    public function testRestartTransportDoesNotLeakIntoChildCommands(): void
    {
        $this->assertSame(
            ['PATH' => '/bin', 'TERM' => 'xterm'],
            ProcessEnvironment::withoutRestart([
                'PATH' => '/bin',
                'TERM' => 'xterm',
                'PORTA_RESTART_DIRECTORY' => '/private/handoff',
                'PORTA_RESTART_CHECK_AFTER' => '9999999999',
            ]),
        );
    }

    /** @return list<string> */
    private static function command(): array
    {
        return [\PHP_BINARY, '-r', 'echo json_encode([getenv("PORTA_RESTART_DIRECTORY"),getenv("PORTA_RESTART_CHECK_AFTER"),getenv("PORTA_TEST_VISIBLE")], JSON_THROW_ON_ERROR);'];
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        return ['PORTA_RESTART_DIRECTORY' => '/private/handoff', 'PORTA_RESTART_CHECK_AFTER' => '9999999999', 'PORTA_TEST_VISIBLE' => 'kept'];
    }

    public function testRealAsyncChildrenReceivePublicEnvironmentWithoutTheRestartCapability(): void
    {
        $process = new AsyncProcess(self::command(), __DIR__, env: self::environment());
        $out = null;
        $code = null;
        $this->assertTrue($process->start(static function (int $status, string $bytes) use (&$out, &$code): void {
            $out = $bytes;
            $code = $status;
        }));
        $this->await(static function () use (&$out): bool {
            return null !== $out;
        }, what: 'the sanitized child environment');
        $this->assertSame(0, $code);
        $this->assertSame([false, false, 'kept'], \json_decode((string) $out, associative: true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testLanguageServersDoNotInheritTheRestartCapability(): void
    {
        $before = [];
        foreach (self::environment() as $key => $value) {
            $before[$key] = \getenv($key);
            \putenv($key.'='.$value);
        }
        $out = null;
        $script = 'echo "Content-Length: ".strlen($body = json_encode(["values" => [getenv("PORTA_RESTART_DIRECTORY"), getenv("PORTA_RESTART_CHECK_AFTER"), getenv("PORTA_TEST_VISIBLE")]], JSON_THROW_ON_ERROR))."\\r\\n\\r\\n".$body;';
        $process = new JsonRpcTransport([\PHP_BINARY, '-r', $script], __DIR__, 'environment probe');
        try {
            $this->assertTrue($process->start(static function (array $message) use (&$out): void {
                $out = $message;
            }));
            $this->await(static function () use (&$out): bool {
                return null !== $out;
            }, what: 'the language server environment');
            $this->assertSame(['values' => [false, false, 'kept']], $out);
        } finally {
            $process->stop();
            foreach ($before as $key => $value) {
                \putenv(false === $value ? $key : $key.'='.$value);
            }
        }
    }

    public function testRealTerminalChildrenDoNotInheritTheRestartCapability(): void
    {
        $process = new PtyProcess(self::command(), __DIR__, env: self::environment());
        $out = '';
        $finished = false;
        $exit = null;
        $process->onOutput(static function (string $bytes) use (&$out): void {
            $out .= $bytes;
        });
        $process->onExit(static function (int $code) use (&$finished, &$exit): void {
            $exit = $code;
            $finished = true;
        });
        try {
            $this->assertTrue($process->start());
            $this->await(static function () use (&$finished): bool {
                return $finished;
            }, what: 'the sanitized terminal environment');
            $this->assertSame(0, $exit);
            $this->assertSame([false, false, 'kept'], \json_decode($out, associative: true, flags: \JSON_THROW_ON_ERROR));
        } finally {
            $process->stop();
        }
    }
}
