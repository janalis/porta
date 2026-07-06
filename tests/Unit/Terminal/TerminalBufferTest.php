<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Terminal;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\TerminalBuffer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TerminalBufferTest extends TestCase
{
    /** @return string[] plain-text view of the buffer */
    private function plain(TerminalBuffer $b): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $b->getLines());
    }

    public function testNewlinesSplitLines(): void
    {
        $b = new TerminalBuffer();
        $b->feed("hello\nworld\n");

        $this->assertSame(['hello', 'world', ''], $this->plain($b));
    }

    public function testCarriageReturnOverwrites(): void
    {
        $b = new TerminalBuffer();
        $b->feed("Progress: 10%\rProgress: 99%");

        $this->assertSame(['Progress: 99%'], $this->plain($b));
    }

    public function testCarriageReturnShorterOverwriteKeepsTail(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcdef\rXYZ");

        $this->assertSame(['XYZdef'], $this->plain($b));
    }

    public function testBackspace(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcX\x08\x08YZ");

        $this->assertSame(['abYZ'], $this->plain($b));
    }

    public function testTabExpandsToStops(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\tb");

        $this->assertSame(['a       b'], $this->plain($b));
    }

    public function testColourCodesArePreserved(): void
    {
        $b = new TerminalBuffer();
        $b->feed("\x1b[31mred\x1b[0m plain");

        $this->assertStringContainsString("\x1b[31m", $b->getLines()[0]);
        $this->assertSame('red plain', AnsiUtils::stripAnsiCodes($b->getLines()[0]));
    }

    public function testColourInsertedAtCursorAfterCarriageReturn(): void
    {
        $b = new TerminalBuffer();
        // Write plain, CR to col 0, then colour + overwrite.
        $b->feed("old text\r\x1b[32mnew");

        $this->assertStringContainsString("\x1b[32mnew", $b->getLines()[0]);
        $this->assertSame('new text', AnsiUtils::stripAnsiCodes($b->getLines()[0]));
    }

    public function testEraseToLineEnd(): void
    {
        $b = new TerminalBuffer();
        $b->feed("longtext\r\x1b[Kshort");

        $this->assertSame(['short'], $this->plain($b));
    }

    public function testEraseEntireDisplayResetsBuffer(): void
    {
        $b = new TerminalBuffer();
        $b->feed("some content\nmore\n");
        $b->feed("\x1b[2J"); // the `clear` command
        $this->assertSame([''], $this->plain($b));

        $b->feed("again\n");
        $b->feed("\x1b[3J"); // erase scrollback too
        $this->assertSame([''], $this->plain($b));
    }

    public function testEraseToEndOfDisplayKeepsScrollback(): void
    {
        // Regression: shells emit ESC[J / ESC[0J when redrawing the prompt after a
        // command; it must only affect the current line, never wipe the scrollback.
        $b = new TerminalBuffer();
        $b->feed("file1\nfile2\n"); // command output
        $b->feed("prompt$ \x1b[J"); // prompt redraw: erase from the cursor (at EOL) onward
        $this->assertSame(['file1', 'file2', 'prompt$ '], $this->plain($b), 'scrollback survives');

        // With the cursor moved back, ESC[0J trims the current line's tail only.
        $b2 = new TerminalBuffer();
        $b2->feed("abcdef\r\x1b[0J");
        $this->assertSame([''], $this->plain($b2));
    }

    public function testCursorHomeDoesNotClear(): void
    {
        // ESC[H (cursor home) is a cursor move, not a clear — output must survive.
        $b = new TerminalBuffer();
        $b->feed("output line\n");
        $b->feed("\x1b[H");
        $b->feed("\x1b[1J"); // erase-above is also dropped (rows are not modelled)

        $this->assertSame(['output line', ''], $this->plain($b));
    }

    public function testCursorAddressingSequencesAreStripped(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\x1b[10;5Hb");

        // The cursor-move is dropped; text stays on one line.
        $this->assertSame(['ab'], $this->plain($b));
    }

    public function testBellAndOtherControlsIgnored(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\x07b\x00c");

        $this->assertSame(['abc'], $this->plain($b));
    }

    public function testScrollbackCapDropsOldestLines(): void
    {
        $b = new TerminalBuffer(maxLines: 3);
        $b->feed("l1\nl2\nl3\nl4\nl5\n");

        $lines = $this->plain($b);
        $this->assertCount(3, $lines);
        $this->assertSame(['l4', 'l5', ''], $lines);
    }

    public function testTailAndLineCount(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\nb\nc\nd");

        $this->assertSame(4, $b->lineCount());
        $this->assertSame(['c', 'd'], array_map(AnsiUtils::stripAnsiCodes(...), $b->tail(2)));
    }

    public function testEscapeSplitAcrossChunksIsBuffered(): void
    {
        $b = new TerminalBuffer();
        $b->feed("text\x1b[3"); // incomplete SGR
        $b->feed('1mred'); // completes it

        $this->assertStringContainsString("\x1b[31mred", $b->getLines()[0]);
        $this->assertSame('textred', AnsiUtils::stripAnsiCodes($b->getLines()[0]));
    }

    public function testClear(): void
    {
        $b = new TerminalBuffer();
        $b->feed("stuff\nmore");
        $b->clear();

        $this->assertSame([''], $this->plain($b));
        $this->assertSame(1, $b->lineCount());
    }

    public function testCrlfTreatedAsSingleNewline(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\r\nb\r\n");

        $this->assertSame(['a', 'b', ''], $this->plain($b));
    }
}
