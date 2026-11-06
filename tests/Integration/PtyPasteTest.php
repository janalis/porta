<?php

declare(strict_types=1);

namespace Porta\Tests\Integration;

use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\TestCase;
use Porta\Terminal\PtyProcess;
use Porta\Tests\Support\PumpsLoop;

/**
 * Large: 9 MB through a pty, which takes about a kilobyte a write — some nine
 * thousand round trips through the kernel. It runs in two seconds alone and
 * past ten under a loaded parallel run, and the size is the point: it must
 * exceed the 8 MiB a NonBlockingWriter queues by default. Its own class
 * because a size is a class's, and PtyProcessTest's other tests are quick.
 */
#[Large]
final class PtyPasteTest extends TestCase
{
    use PumpsLoop;

    /** @var list<PtyProcess> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            $p->stop();
        }
        $this->procs = [];
    }

    /**
     * A paste far larger than the terminal's buffer arrives whole. The master
     * side is non-blocking, so a write takes only what fits; the rest used to
     * be dropped without a word, and a paste past the queue's cap killed the
     * writer outright.
     */
    public function testAWriteLargerThanTheTerminalTakesAtOnceArrivesWhole(): void
    {
        $out = \sys_get_temp_dir().'/porta-pty-paste-'.\getmypid().'-'.\bin2hex(\random_bytes(4));
        // Past the 8 MiB a NonBlockingWriter queues by default: an overflow
        // used to kill the writer, and every later keystroke with it.
        $size = 9_000_000;
        $p = new PtyProcess(['/bin/sh', '-c', 'stty raw -echo; echo raw; exec head -c '.$size.' > '.$out], __DIR__);
        $this->procs[] = $p;
        $exit = null;
        $said = '';
        $p->onOutput(static function (string $chunk) use (&$said): void {
            $said .= $chunk;
        })->onExit(static function (int $c) use (&$exit): void {
            $exit = $c;
        });
        $this->assertTrue($p->start());
        // Raw before a byte is written: a canonical line holds 1 KiB and drops
        // the rest, and head then waits forever. A fixed pause raced the
        // session shim's own start-up.
        $this->await(static function () use (&$said): bool {
            return \str_contains($said, 'raw');
        }, what: 'the terminal in raw mode');

        $p->write(\str_repeat('x', $size));

        try {
            $this->await(
                static function () use (&$exit): bool {
                    return null !== $exit;
                },
                timeout: 30.0,
                what: 'the child to have read every byte',
            );
            $this->assertSame(0, $exit);
            $this->assertSame($size, \filesize($out));
        } finally {
            if (\is_file($out)) {
                \unlink($out);
            }
        }
    }
}
