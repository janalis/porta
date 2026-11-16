<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TestCommandTransport;

/** One short process per verb: its answer, its failure in words, and closing what is in flight. */
final class CommandTransportTest extends TestCase
{
    use PumpsLoop;

    /** @return array{int, string, string} */
    private function exec(TestCommandTransport $transport, array $command, ?string $stdin = null): array
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
        $this->assertSame([3, 'IN', "oops\n"], $this->exec(new TestCommandTransport(), ['sh', '-c', 'cat; echo oops >&2; exit 3'], stdin: 'IN'));
    }

    public function testACommandThatCannotStartAnswersAsAShellWould(): void
    {
        $this->assertSame([127, '', '/nonexistent/tool: command not found'], $this->exec(new TestCommandTransport(), ['/nonexistent/tool']));
    }

    public function testAFailureIsSaidInItsLastWords(): void
    {
        $transport = new TestCommandTransport();

        $this->assertSame('Permission denied (publickey).', $transport::say(255, stderr: "Warning: something\nPermission denied (publickey).\n\n"));
        $this->assertSame('exit code 2', $transport::say(2, stderr: " \n"));
        $this->assertSame('no answer in 600 seconds', $transport::say(-1, stderr: ''));
    }

    public function testClosingCancelsWhatIsInFlight(): void
    {
        $transport = new TestCommandTransport();
        $answered = false;
        $transport->exec(['sleep', '5'], stdin: null, done: static function () use (&$answered): void {
            $answered = true;
        });

        $transport->close();
        $this->pump(0.2);

        $this->assertFalse($answered);
    }

    public function testAtMostSoManyRunAtOnceAndTheRestWaitTheirTurnInOrder(): void
    {
        $transport = new TestCommandTransport();
        $transport->atMost(1);
        $order = [];
        foreach (['one', 'two', 'three'] as $word) {
            $transport->exec([\PHP_BINARY, '-r', 'usleep(50000); echo "'.$word.'";'], stdin: null, done: static function (int $code, string $out) use (&$order): void {
                $order[] = $out;
            });
        }
        $this->assertCount(1, Probe::get($transport, property: 'running'), 'one at a time');

        $this->await(static function () use (&$order): bool {
            return 3 === \count($order);
        }, what: 'all three');

        $this->assertSame(['one', 'two', 'three'], $order);
        $transport->exec(['true'], stdin: null, done: static function (): void {});
        $transport->atMost(0);
        $transport->close();
        $this->assertSame([], Probe::get($transport, property: 'waiting'), 'closing forgets what waited');
    }

    public function testAFailureIsNoRefusedLoginUnlessTheTransportKnowsOne(): void
    {
        $this->assertFalse(new TestCommandTransport()->refusesLogin('Permission denied'));
    }

    public function testACommandThatCannotStartLetsTheNextInLineGo(): void
    {
        $transport = new TestCommandTransport();
        $transport->atMost(1);
        $answers = [];
        $transport->exec([\PHP_BINARY, '-r', 'usleep(100000);'], stdin: null, done: static function (int $code) use (&$answers): void {
            $answers[] = 'first '.$code;
        });
        $transport->exec(['/porta-no-such-binary'], stdin: null, done: static function (int $code) use (&$answers): void {
            $answers[] = 'missing '.$code;
        });
        $transport->exec([\PHP_BINARY, '-r', 'echo 1;'], stdin: null, done: static function (int $code) use (&$answers): void {
            $answers[] = 'last '.$code;
        });

        $this->await(static function () use (&$answers): bool {
            return 3 === \count($answers);
        }, what: 'all three, the one after the refusal too');
        $this->assertEqualsCanonicalizing(['first 0', 'missing 127', 'last 0'], $answers, 'every one answered — the order of the first two is the free slot\'s, not the queue\'s');
    }
}
