<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Input\MouseEvent;
use Porta\Input\PixelPointer;
use Porta\Ui\TerminalRestorer;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Pixel reports are asked for, and turned on only once the terminal has said
 * it knows them AND how tall a cell is: a terminal that ignored the mode would
 * go on reporting cells, which would then be read as pixels.
 */
final class PixelPointerTest extends TestCase
{
    private VirtualTerminal $terminal;

    private PixelPointer $pointer;

    protected function setUp(): void
    {
        $this->terminal = new VirtualTerminal(columns: 80, rows: 40);
        $this->pointer = new PixelPointer(new Tui(terminal: $this->terminal, keybindings: new Keybindings([])));
    }

    /** Feed one reply as input; whether a later listener would still see it. */
    private function reply(string $data): bool
    {
        $event = new InputEvent($data);
        $this->pointer->onInput($event);

        return !$event->isPropagationStopped();
    }

    private function press(): MouseEvent
    {
        $mouse = MouseEvent::parse("\x1b[<0;25;48M");
        $this->assertInstanceOf(MouseEvent::class, $mouse);

        return $this->pointer->cells($mouse);
    }

    public function testItAsksForTheModeAndTheCellSize(): void
    {
        $this->pointer->probe([]);

        $this->assertSame("\x1b[?1016\$p\x1b[16t", $this->terminal->getOutput());
    }

    public function testQuittingTurnsItOff(): void
    {
        $this->assertStringStartsWith("\x1b[?1016l", TerminalRestorer::RESTORE, 'before the cell reports it rides on');
    }

    public function testAnEnvironmentCanKeepItOff(): void
    {
        $this->pointer->probe(['PORTA_PIXEL_MOUSE' => 'off']);

        $this->assertSame('', $this->terminal->getOutput());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function answerOrders(): iterable
    {
        yield 'the mode first' => [["\x1b[?1016;2\$y", "\x1b[6;20;10t"]];
        yield 'the size first' => [["\x1b[6;20;10t", "\x1b[?1016;2\$y"]];
        yield 'already set' => [["\x1b[?1016;1\$y", "\x1b[6;20;10t"]];
        yield 'set for good' => [["\x1b[?1016;3\$y", "\x1b[6;20;10t"]];
    }

    /** @param list<string> $replies */
    #[DataProvider('answerOrders')]
    public function testBothAnswersTurnItOnInEitherOrder(array $replies): void
    {
        foreach ($replies as $reply) {
            $this->reply($reply);
        }

        $this->assertSame(PixelPointer::ON, $this->terminal->getOutput(), 'turned on once, when both were in');
        $mouse = $this->press();
        $this->assertSame([2, 2, false], [$mouse->col, $mouse->row, $mouse->lowerHalf], 'and reports are read in pixels of that size');
    }

    public function testTheModeReportIsSwallowedButTheSizeIsLeftToTheGraphicsProbe(): void
    {
        $this->assertFalse($this->reply("\x1b[?1016;2\$y"), 'the mode report is no key');
        $this->assertTrue($this->reply("\x1b[6;20;10t"), 'the graphics probe reads the size from the same reply');
        $this->assertTrue($this->reply('a'), 'and everything else passes');
    }

    /** @return iterable<string, array{string}> */
    public static function noes(): iterable
    {
        yield 'unknown' => ["\x1b[?1016;0\$y"];
        yield 'reset for good' => ["\x1b[?1016;4\$y"];
    }

    #[DataProvider('noes')]
    public function testATerminalWithoutTheModeKeepsCellReports(string $answer): void
    {
        $this->reply($answer);
        $this->reply("\x1b[6;20;10t");

        $this->assertSame('', $this->terminal->getOutput(), 'never turned on');
        $mouse = $this->press();
        $this->assertSame([24, 47, null], [$mouse->col, $mouse->row, $mouse->lowerHalf], 'a cell report, as it came');
    }

    public function testTheModeAloneTurnsNothingOn(): void
    {
        $this->reply("\x1b[?1016;2\$y");

        $this->assertSame('', $this->terminal->getOutput(), 'no size, no pixels: a report could not be placed');
        $this->assertNull($this->press()->lowerHalf);
    }

    /** A font zoom changes the cell's pixels and the terminal's cells together: a new size asks again. */
    public function testANewTerminalSizeAsksForTheCellSizeAgain(): void
    {
        $this->pointer->beforeFrame();
        $this->assertSame('', $this->terminal->getOutput(), 'nothing to ask while it is off');

        $this->reply("\x1b[?1016;2\$y");
        $this->reply("\x1b[6;20;10t");
        $this->terminal->clearOutput();
        $this->pointer->beforeFrame();
        $this->assertSame('', $this->terminal->getOutput(), 'the same size asks nothing');

        $this->terminal->simulateResize(columns: 100, rows: 50);
        $this->pointer->beforeFrame();
        $this->assertSame("\x1b[16t", $this->terminal->getOutput());
        $this->reply("\x1b[6;16;8t");
        $this->assertSame([3, 2], [$this->press()->col, $this->press()->row], 'and reports are read in the new size');
    }
}
