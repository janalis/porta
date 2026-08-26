<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Terminal;

use PHPUnit\Framework\TestCase;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\RendersWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TerminalBufferTest extends TestCase
{
    use RendersWidget;

    /** @return string[] plain-text view of the buffer */
    public function testNewlinesSplitLines(): void
    {
        $b = new TerminalBuffer();
        $b->feed("hello\nworld\n");

        $this->assertSame(['hello', 'world', ''], $this->rows($b->lines));
    }

    public function testCarriageReturnOverwrites(): void
    {
        $b = new TerminalBuffer();
        $b->feed("Progress: 10%\rProgress: 99%");

        $this->assertSame(['Progress: 99%'], $this->rows($b->lines));
    }

    public function testCarriageReturnShorterOverwriteKeepsTail(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcdef\rXYZ");

        $this->assertSame(['XYZdef'], $this->rows($b->lines));
    }

    public function testWritingRightAfterAShorterOverwriteStaysAnOverwrite(): void
    {
        // The tracked line width must not shrink to the overwrite's end: the
        // line is still 5 wide after "abc" lands on "12345", so the next run
        // at column 3 OVERWRITES the "4" — a width tracked too small would
        // route it to the append path and produce "abc45X".
        $b = new TerminalBuffer();
        $b->feed("12345\rabc");
        $b->feed('X');

        $this->assertSame(['abcX5'], $this->rows($b->lines));
    }

    public function testBackspace(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcX\x08\x08YZ");

        $this->assertSame(['abYZ'], $this->rows($b->lines));
    }

    public function testTabExpandsToStops(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\tb");

        $this->assertSame(['a       b'], $this->rows($b->lines));
    }

    public function testColourCodesArePreserved(): void
    {
        $b = new TerminalBuffer();
        $b->feed("\x1b[31mred\x1b[0m plain");

        $this->assertStringContainsString("\x1b[31m", $b->lines[0]);
        $this->assertSame('red plain', AnsiUtils::stripAnsiCodes($b->lines[0]));
    }

    public function testColourInsertedAtCursorAfterCarriageReturn(): void
    {
        $b = new TerminalBuffer();
        // Write plain, CR to col 0, then colour + overwrite.
        $b->feed("old text\r\x1b[32mnew");

        $this->assertStringContainsString("\x1b[32mnew", $b->lines[0]);
        $this->assertSame('new text', AnsiUtils::stripAnsiCodes($b->lines[0]));
    }

    public function testCursorColumnTracksWritesReturnsBackspacesAndNewlines(): void
    {
        $buffer = new TerminalBuffer();
        $this->assertSame(0, $buffer->cursorColumn(), 'starts at column 0');

        $buffer->feed('prompt$ ');
        $this->assertSame(8, $buffer->cursorColumn());

        $buffer->feed("\x08");
        $this->assertSame(7, $buffer->cursorColumn(), 'backspace steps left');

        $buffer->feed("\r");
        $this->assertSame(0, $buffer->cursorColumn(), 'carriage return rewinds');

        $buffer->feed("\tx");
        $this->assertSame(9, $buffer->cursorColumn(), 'tab expands to the next stop');

        $buffer->feed("\n");
        $this->assertSame(0, $buffer->cursorColumn(), 'a new line starts at column 0');

        $buffer->feed("abc\x1b[K");
        $this->assertSame(3, $buffer->cursorColumn(), 'erase-to-end keeps the column');
    }

    public function testEraseToLineEnd(): void
    {
        $b = new TerminalBuffer();
        $b->feed("longtext\r\x1b[Kshort");

        $this->assertSame(['short'], $this->rows($b->lines));
    }

    public function testEraseEntireDisplayResetsBuffer(): void
    {
        $b = new TerminalBuffer();
        $b->feed("some content\nmore\n");
        $b->feed("\x1b[2J"); // the `clear` command
        $this->assertSame([''], $this->rows($b->lines));

        $b->feed("again\n");
        $b->feed("\x1b[3J"); // erase scrollback too
        $this->assertSame([''], $this->rows($b->lines));
    }

    public function testEraseToEndOfDisplayKeepsScrollback(): void
    {
        // Regression: shells emit ESC[J / ESC[0J when redrawing the prompt after a
        // command; it must only affect the current line, never wipe the scrollback.
        $b = new TerminalBuffer();
        $b->feed("file1\nfile2\n"); // command output
        $b->feed("prompt$ \x1b[J"); // prompt redraw: erase from the cursor (at EOL) onward
        $this->assertSame(['file1', 'file2', 'prompt$ '], $this->rows($b->lines), 'scrollback survives');

        // With the cursor moved back, ESC[0J trims the current line's tail only.
        $b2 = new TerminalBuffer();
        $b2->feed("abcdef\r\x1b[0J");
        $this->assertSame([''], $this->rows($b2->lines));
    }

    public function testCursorHomeDoesNotClear(): void
    {
        // ESC[H (cursor home) is a cursor move, not a clear — output must survive.
        $b = new TerminalBuffer();
        $b->feed("output line\n");
        $b->feed("\x1b[H");
        $b->feed("\x1b[1J"); // erase-above is also dropped (rows are not modelled)

        $this->assertSame(['output line', ''], $this->rows($b->lines));
    }

    public function testCursorAddressingSequencesAreStripped(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\x1b[10;5Hb");

        // The cursor-move is dropped; text stays on one line.
        $this->assertSame(['ab'], $this->rows($b->lines));
    }

    public function testBellAndOtherControlsIgnored(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\x07b\x00c");

        $this->assertSame(['abc'], $this->rows($b->lines));
    }

    public function testScrollbackCapTrimsInAmortizedBatches(): void
    {
        // The trim is amortized: the buffer runs up to slack (maxLines/16,
        // floor 1) past the cap, then one batch trim lands it back at exactly
        // maxLines with the NEWEST lines kept — never a shift per line.
        $b = new TerminalBuffer(maxLines: 3); // slack 1: trims once count exceeds 4
        $b->feed("l1\nl2\nl3\nl4\n");
        $this->assertSame(['l3', 'l4', ''], $this->rows($b->lines), 'the overshoot trimmed back to the cap, newest kept');

        $b->feed("l5\n"); // 4 lines: inside the slack, untrimmed
        $this->assertSame(['l3', 'l4', 'l5', ''], $this->rows($b->lines), 'within the slack nothing is dropped yet');

        $b->feed("l6\n"); // exceeds cap+slack again
        $this->assertSame(['l5', 'l6', ''], $this->rows($b->lines), 'the next batch trim lands on the cap again');
    }

    public function testTailAndLineCount(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\nb\nc\nd");

        $this->assertSame(4, $b->lineCount());
        $this->assertSame(['c', 'd'], array_map(AnsiUtils::stripAnsiCodes(...), \array_slice($this->rows($b->lines), -2)));
    }

    public function testEscapeSplitAcrossChunksIsBuffered(): void
    {
        $b = new TerminalBuffer();
        $b->feed("text\x1b[3"); // incomplete SGR
        $b->feed('1mred'); // completes it

        $this->assertStringContainsString("\x1b[31mred", $b->lines[0]);
        $this->assertSame('textred', AnsiUtils::stripAnsiCodes($b->lines[0]));
    }

    public function testClear(): void
    {
        $b = new TerminalBuffer();
        $b->feed("stuff\nmore");
        $b->clear();

        $this->assertSame([''], $this->rows($b->lines));
        $this->assertSame(1, $b->lineCount());
    }

    public function testCrlfTreatedAsSingleNewline(): void
    {
        $b = new TerminalBuffer();
        $b->feed("a\r\nb\r\n");

        $this->assertSame(['a', 'b', ''], $this->rows($b->lines));
    }

    public function testClearResetsCursorColumnToExactlyZero(): void
    {
        $b = new TerminalBuffer();
        $b->feed('abc');
        $b->clear();

        $this->assertSame(0, $b->cursorColumn());
    }

    public function testBackspaceAtColumnZeroStaysAtZero(): void
    {
        $b = new TerminalBuffer();
        $b->feed("\r\x08"); // carriage return puts col at 0, then backspace
        $this->assertSame(0, $b->cursorColumn());

        $b->feed('x');
        $this->assertSame(1, $b->cursorColumn(), 'a stray backspace at col 0 must not push the next write off-column');
    }

    public function testSingleBackspaceFromColumnOneReachesZero(): void
    {
        $b = new TerminalBuffer();
        $b->feed('a');
        $this->assertSame(1, $b->cursorColumn());

        $b->feed("\x08");
        $this->assertSame(0, $b->cursorColumn());
    }

    public function testTabAtColumnSevenAddsOneSpaceToReachTheNextStop(): void
    {
        $b = new TerminalBuffer();
        $b->feed('1234567'); // 7 chars: col=7, one short of the 8-column stop
        $b->feed("\t");

        $this->assertSame(8, $b->cursorColumn());
        $this->assertSame(['1234567 '], $this->rows($b->lines));
    }

    public function testTabExactlyOnAStopAdvancesAFullEightColumns(): void
    {
        $b = new TerminalBuffer();
        $b->feed('12345678'); // 8 chars: col=8, exactly on a tab stop
        $b->feed("\t");

        $this->assertSame(16, $b->cursorColumn());
    }

    public function testAccumulatingSgrCodesConcatenateInOrderInsteadOfOverwriting(): void
    {
        $b = new TerminalBuffer();
        // Two SGR codes back to back, no text between: both must survive, in order.
        $b->feed("\x1b[1m\x1b[31mtext");

        $this->assertStringStartsWith("\x1b[1m\x1b[31mtext", $b->lines[0]);
    }

    public function testResetClearsAnyAccumulatedSgrRatherThanAppendingToIt(): void
    {
        $b = new TerminalBuffer();
        $b->feed("\x1b[1m\x1b[0mtext"); // bold, then a real reset before any text

        // Only the reset code should prefix the text — the bold must not survive it.
        $this->assertSame("\x1b[0mtext", $b->lines[0]);
    }

    public function testEraseFromCursorToEndOfDisplayActuallyTrimsWhenNotAtEndOfLine(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcdef\r\x1b[J"); // rewind to col 0, then erase-to-end

        $this->assertSame([''], $this->rows($b->lines), 'ESC[J at col 0 must erase the whole line, not leave it untouched');
    }

    public function testUnhandledEscapeCodesNeverTriggerAnErase(): void
    {
        $b = new TerminalBuffer();
        $b->feed("abcdef\r"); // col back to 0, content still present
        $b->feed("\x1b[H"); // cursor-home: a dropped sequence, not an erase

        $this->assertSame(['abcdef'], $this->rows($b->lines), 'a dropped escape must never erase the line');
    }
}
