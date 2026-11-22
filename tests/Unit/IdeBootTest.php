<?php

declare(strict_types=1);

namespace Porta\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Porta\Event\IdeReady;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Services;
use Porta\Ui\TerminalRestorer;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * {@see \Porta\Ide::run()} is boot(), the loop, then shutdown(): the halves an
 * in-process driver calls around a Tui it starts itself.
 *
 * What shutdown() must answer for is that it undoes the boot exactly once,
 * however the boot ended: a second call is the driver's stop after a step
 * failed, and a boot that threw has already unwound.
 */
final class IdeBootTest extends TestCase
{
    use BootsIde;
    use PreservesPath;

    protected function setUp(): void
    {
        // No language server: the boot would start one off the live PATH
        \putenv('PATH=/nonexistent');
    }

    public function testShutdownUndoesTheBootOnce(): void
    {
        $ide = $this->bootIde();
        $before = EventLoop::getIdentifiers();

        $ide->boot();
        $booted = $this->terminal->consumeOutput();
        $ide->shutdown();
        $shutDown = $this->terminal->consumeOutput();
        $ide->shutdown();

        $this->assertStringContainsString("\x1b[?1049h\x1b[?1000h\x1b[?1003h\x1b[?1006h", $booted, 'the boot takes the alternate screen and the mouse');
        $this->assertStringContainsString(TerminalRestorer::RESTORE, $shutDown, 'and the shutdown gives them back');
        $this->assertSame('', $this->terminal->consumeOutput(), 'a second shutdown has nothing left to undo');
        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)), 'no poll, signal or probe outlives it');
    }

    public function testShutdownWithoutABootDoesNothing(): void
    {
        $ide = $this->bootIde();
        $this->terminal->consumeOutput();

        $ide->shutdown();

        $this->assertSame('', $this->terminal->consumeOutput());
    }

    public function testABootThatThrowsHasAlreadyShutDown(): void
    {
        $ide = $this->bootIde();
        Services::resolve($ide, EventDispatcherInterface::class)->addListener(IdeReady::class, static function (): never {
            throw new \RuntimeException('not ready');
        });
        $before = EventLoop::getIdentifiers();

        try {
            $ide->boot();
            $this->fail('The boot was expected to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('not ready', $e->getMessage());
        }
        $unwound = $this->terminal->consumeOutput();
        $ide->shutdown();

        $this->assertStringContainsString(TerminalRestorer::RESTORE, $unwound, 'the half-done boot gave the terminal back');
        $this->assertSame('', $this->terminal->consumeOutput(), 'and left the driver nothing to shut down');
        $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
    }
}
