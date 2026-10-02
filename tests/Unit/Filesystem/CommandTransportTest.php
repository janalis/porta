<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\CommandTransport;
use Porta\Filesystem\RemoteStamp;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;

/** One short process per verb: its answer, its failure in words, and closing what is in flight. */
final class CommandTransportTest extends TestCase
{
    use PumpsLoop;

    private function transport(): CommandTransport
    {
        return new class extends CommandTransport {
            /**
             * @param list<string>                         $command
             * @param \Closure(int, string, string): void $done
             */
            public function exec(array $command, ?string $stdin, \Closure $done): void
            {
                $this->run($command, $stdin, $done);
            }

            public function atMost(int $limit): void
            {
                $this->runAtMost($limit);
            }

            public static function say(int $code, string $stderr): string
            {
                return self::failure($code, $stderr);
            }

            public function list(string $path, \Closure $done): void {}

            public function fetch(string $path, \Closure $done): void {}

            public function put(string $path, string $bytes, ?RemoteStamp $expected, \Closure $done): void {}

            public function mkdir(string $path, \Closure $done): void {}

            public function rename(string $from, string $to, \Closure $done): void {}

            public function remove(string $path, bool $isDir, \Closure $done): void {}
        };
    }

    /** @return array{int, string, string} */
    private function exec(CommandTransport $transport, array $command, ?string $stdin = null): array
    {
        $answer = null;
        $transport->exec($command, $stdin, static function (int $code, string $out, string $err) use (&$answer): void {
            $answer = [$code, $out, $err];
        });
        $this->await(static function () use (&$answer): bool {
            return null !== $answer;
        });

        return $answer;
    }

    public function testItAnswersTheExitCodeOutputAndErrors(): void
    {
        $this->assertSame([3, 'IN', "oops\n"], $this->exec($this->transport(), ['sh', '-c', 'cat; echo oops >&2; exit 3'], 'IN'));
    }

    public function testACommandThatCannotStartAnswersAsAShellWould(): void
    {
        $this->assertSame([127, '', '/nonexistent/tool: command not found'], $this->exec($this->transport(), ['/nonexistent/tool']));
    }

    public function testAFailureIsSaidInItsLastWords(): void
    {
        $transport = $this->transport();

        $this->assertSame('Permission denied (publickey).', $transport::say(255, "Warning: something\nPermission denied (publickey).\n\n"));
        $this->assertSame('exit code 2', $transport::say(2, " \n"));
        $this->assertSame('no answer in 600 seconds', $transport::say(-1, ''));
    }

    public function testClosingCancelsWhatIsInFlight(): void
    {
        $transport = $this->transport();
        $answered = false;
        $transport->exec(['sleep', '5'], null, static function () use (&$answered): void {
            $answered = true;
        });

        $transport->close();
        $this->pump(0.2);

        $this->assertFalse($answered);
    }

    public function testAtMostSoManyRunAtOnceAndTheRestWaitTheirTurnInOrder(): void
    {
        $transport = $this->transport();
        $transport->atMost(1);
        $order = [];
        foreach (['one', 'two', 'three'] as $word) {
            $transport->exec([\PHP_BINARY, '-r', 'usleep(50000); echo "'.$word.'";'], null, static function (int $code, string $out) use (&$order): void {
                $order[] = $out;
            });
        }
        $this->assertCount(1, Probe::get($transport, 'running'), 'one at a time');

        $this->await(static function () use (&$order): bool {
            return 3 === \count($order);
        }, what: 'all three');

        $this->assertSame(['one', 'two', 'three'], $order);
        $transport->exec(['true'], null, static function (): void {});
        $transport->atMost(0);
        $transport->close();
        $this->assertSame([], Probe::get($transport, 'waiting'), 'closing forgets what waited');
    }

    public function testAFailureIsNoRefusedLoginUnlessTheTransportKnowsOne(): void
    {
        $this->assertFalse($this->transport()->refusesLogin('Permission denied'));
    }
}
