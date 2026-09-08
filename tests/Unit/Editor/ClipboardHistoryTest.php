<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ClipboardRing;
use Porta\Editor\SelectionMode;
use Porta\Support\FlashSink;
use Porta\Tests\Support\EditorState;
use Porta\Widget\CodeEditorWidget;

/**
 * `Ctrl+Shift+V` — paste something you copied earlier.
 *
 * The ring itself is four small methods; what the tests are mostly about is
 * the two places it could be wired so that it works and means nothing: a
 * history that does not survive a BUFFER SWITCH (the copy you want back is
 * usually from the file you just left), and one that fills with duplicates of
 * whatever you pasted around four times, pushing what you wanted off the end.
 */
final class ClipboardHistoryTest extends TestCase
{
    public function testTheRingKeepsCopiesNewestFirst(): void
    {
        $ring = new ClipboardRing();
        $ring->push('one');
        $ring->push('two');

        $this->assertSame(['two', 'one'], $ring->entries());
        $this->assertSame('two', $ring->latest(), 'plain Ctrl+V pastes the newest');
    }

    public function testAnEmptyRingHasNothingToPaste(): void
    {
        $this->assertSame('', new ClipboardRing()->latest());
        $this->assertSame([], new ClipboardRing()->entries());
    }

    /**
     * Copying nothing is not a copy. Without this an empty selection would
     * push `''` to the top and plain `Ctrl+V` would paste nothing while the
     * real clipboard sat one row down.
     */
    public function testCopyingEmptyTextDisplacesNothing(): void
    {
        $ring = new ClipboardRing();
        $ring->push('kept');
        $ring->push('');

        $this->assertSame(['kept'], $ring->entries());
    }

    /**
     * Re-copying promotes rather than duplicates — the difference between a
     * history you can read and one full of the same snippet.
     */
    public function testRecopyingSomethingMovesItBackToTheTop(): void
    {
        $ring = new ClipboardRing();
        $ring->push('a');
        $ring->push('b');
        $ring->push('c');
        $ring->push('a');

        $this->assertSame(['a', 'c', 'b'], $ring->entries());
    }

    /**
     * The history is bounded by BYTES as well as by count.
     *
     * Depth alone is not a bound. The clipboard used to be one string, and
     * twenty of them is twenty times whatever you last copied — `Ctrl+A`,
     * `Ctrl+C` on a large file, twenty times, retained 38 MB in a process that
     * runs all day. That is a change in resource behaviour this class
     * introduced and no gate can see.
     */
    public function testABigCopyDoesNotLeaveTwentyOfItselfBehind(): void
    {
        $ring = new ClipboardRing();
        $big = str_repeat('x', times: 600_000);

        foreach (range(start: 1, end: 10) as $i) {
            $ring->push($big.$i);
        }

        $retained = array_sum(array_map(\strlen(...), $ring->entries()));
        $this->assertLessThanOrEqual(ClipboardRing::BUDGET, $retained, 'inside the budget');
        // EXACTLY one: ten copies of 600 kB against a 1 MB budget leave room
        // for a single entry. `assertLessThan(10, …)` was the first version and
        // it passes at NINE — which is what a subtraction turned into an
        // assignment produces, and it survived because of that.
        $this->assertCount(1, $ring->entries());
    }

    /**
     * One push can need SEVERAL entries dropped, and the running total has to
     * come down with each.
     *
     * Ten small copies fit; a big one arriving after them does not, and making
     * room takes nine drops rather than one. Equal-sized fixtures cannot show
     * this — the budget is re-applied on every push, so each one is a single
     * drop, and an arithmetic error inside the loop reaches the same answer.
     * Two mutants (`-=` written as `=`, and as `+=`) survived a four-equal-copy
     * fixture for exactly that reason.
     */
    public function testOnePushCanCostSeveralEntries(): void
    {
        $ring = new ClipboardRing();
        foreach (range(start: 0, end: 9) as $i) {
            $ring->push(str_repeat((string) $i, times: 100_000));
        }
        $this->assertCount(10, $ring->entries(), 'a megabyte of small copies all fit');

        $ring->push(str_repeat('Z', times: 900_000));

        $this->assertCount(2, $ring->entries(), 'the newcomer and one survivor');
        $this->assertLessThanOrEqual(ClipboardRing::BUDGET, array_sum(array_map(\strlen(...), $ring->entries())));
    }

    /** Exactly at the budget is inside it, so nothing is dropped. */
    public function testEntriesSummingToExactlyTheBudgetAreAllKept(): void
    {
        $ring = new ClipboardRing();
        $half = intdiv(ClipboardRing::BUDGET, num2: 2);
        $ring->push(str_repeat('a', times: $half));
        $ring->push(str_repeat('b', times: $half));

        $this->assertCount(2, $ring->entries());
        $this->assertSame(ClipboardRing::BUDGET, array_sum(array_map(\strlen(...), $ring->entries())));
    }

    /**
     * And the newest is kept whatever its size, because it is what `Ctrl+V`
     * pastes — which worked for any copy before there was a history at all.
     * A budget that could drop it would be a regression dressed as a bound.
     */
    public function testTheNewestCopySurvivesEvenAloneOverBudget(): void
    {
        $ring = new ClipboardRing();
        $huge = str_repeat('y', times: 4_000_000);

        $ring->push('small');
        $ring->push($huge);

        $this->assertSame($huge, $ring->latest(), 'still pastes');
        $this->assertSame([$huge], $ring->entries(), 'and it is the only thing left');
    }

    public function testItForgetsPastItsDepth(): void
    {
        $ring = new ClipboardRing();
        foreach (range(1, ClipboardRing::DEPTH + 5) as $i) {
            $ring->push("entry{$i}");
        }

        $entries = $ring->entries();
        $this->assertCount(ClipboardRing::DEPTH, $entries);
        $this->assertSame('entry'.(ClipboardRing::DEPTH + 5), $entries[0], 'newest kept');
        $this->assertSame('entry6', $entries[ClipboardRing::DEPTH - 1], 'and the first five dropped off the end');
    }

    // --- through a running editor -------------------------------------------

    private function editor(string $text = "alpha\nbeta\ngamma\n"): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', $text);
        // Focused, or the widget composites no floating surface onto its frame
        // and every assertion about the picker's CONTENT reads a bare buffer.
        $editor->setFocused(true);

        return $editor;
    }

    private function copyLine(CodeEditorWidget $editor, int $line): void
    {
        $doc = $editor->getDocument();
        $doc->setCursor($line, 0);
        $doc->beginSelect();
        $doc->setCursor($line, \strlen($doc->getLine($line)), SelectionMode::Keep);
        $editor->handleInput("\x03"); // ctrl+c
    }

    public function testCtrlShiftVOffersEveryCopyAndPastingOneInsertsIt(): void
    {
        $editor = $this->editor();
        $this->copyLine($editor, 0); // alpha
        $this->copyLine($editor, 1); // beta

        $editor->getDocument()->setCursor(2, 5);
        $editor->handleInput("\x1b[118;6u"); // ctrl+shift+v
        $this->assertTrue(EditorState::pickerOpen($editor), 'the history opened');

        // `beta` is newest and selected; one Down reaches `alpha`.
        $editor->handleInput("\x1b[B");
        $editor->handleInput("\r");

        $this->assertSame('gammaalpha', $editor->getDocument()->getLine(2));
    }

    /**
     * The pick is promoted, so the plain `Ctrl+V` after it repeats what you
     * just pasted rather than the entry you reached past. Without this the two
     * keys disagree about what "the clipboard" is one keystroke apart.
     */
    public function testPastingFromHistoryMakesThatEntryTheClipboard(): void
    {
        $editor = $this->editor();
        $this->copyLine($editor, 0); // alpha
        $this->copyLine($editor, 1); // beta

        $editor->getDocument()->setCursor(2, 5);
        $editor->handleInput("\x1b[118;6u");
        $editor->handleInput("\x1b[B"); // alpha
        $editor->handleInput("\r");
        $this->assertSame('gammaalpha', $editor->getDocument()->getLine(2));

        $editor->handleInput("\x16"); // ctrl+v
        $this->assertSame('gammaalphaalpha', $editor->getDocument()->getLine(2));
    }

    /**
     * With several carets, `Ctrl+Shift+V` lands where `Ctrl+V` would.
     *
     * The two keys differ in WHICH text, never in where — and they very nearly
     * differed in both. `Ctrl+V` is intercepted by the multi-caret path before
     * the action table ever sees it, while the history pick arrives later from
     * an overlay callback, long after that interception could happen; so the
     * plain key pasted at every caret and this one at exactly one. Found by
     * asking which COMBINATION of this session's features was untested, which
     * is the only way it would have been found.
     */
    public function testPastingFromHistoryReachesEveryCaretJustAsPlainPasteDoes(): void
    {
        // Each case is the FULL key sequence, so the two paths differ only in
        // what is typed — an `if` inside the loop would let them drift.
        $expected = null;
        foreach (['plain' => ["\x16"], 'history' => ["\x1b[118;6u", "\r"]] as $how => $keys) {
            $editor = $this->editor("one\ntwo\nthree\n");
            $this->copyLine($editor, 0); // `one`

            $doc = $editor->getDocument();
            $doc->setCursor(1, 3);
            $editor->handleInput("\x1b[1;7B"); // ctrl+alt+down: a second caret

            foreach ($keys as $key) {
                $editor->handleInput($key);
            }

            $lines = $editor->getDocument()->getLines();
            $expected ??= $lines;
            $this->assertSame($expected, $lines, "{$how} paste");
        }

        $this->assertSame(['one', 'twoone', 'throneee', ''], $expected, 'and both reached BOTH carets');
    }

    /**
     * The history crosses buffers, which is the whole point and the thing a
     * per-document field could never do. `EditorActions` is rebuilt on every
     * switch; the ring is handed over by HANDLE, so this passes only because
     * it is an object.
     */
    public function testTheHistorySurvivesABufferSwitch(): void
    {
        $editor = $this->editor();
        $this->copyLine($editor, 0); // alpha

        $editor->openFile('other.php', "one\ntwo\n");
        $this->copyLine($editor, 0); // one

        $editor->getDocument()->setCursor(1, 3);
        $editor->handleInput("\x1b[118;6u");
        $editor->handleInput("\x1b[B"); // past `one`, to `alpha`
        $editor->handleInput("\r");

        $this->assertSame('twoalpha', $editor->getDocument()->getLine(1), 'a copy from the previous buffer');
    }

    /** Nothing copied yet: the key does nothing rather than opening an empty box. */
    public function testWithNothingCopiedThereIsNoPicker(): void
    {
        $editor = $this->editor();

        $editor->handleInput("\x1b[118;6u");

        $this->assertFalse(EditorState::pickerOpen($editor));
    }

    public function testTheHistoryKeystrokeSaysSoWhenNothingHasBeenCopied(): void
    {
        // The first-run state, and the moment someone tries the shortcut to see
        // what it does: an empty ring used to swallow the keystroke whole,
        // which reads as a broken binding rather than as an empty list.
        $flash = new class implements FlashSink {
            /** @var list<string> */
            public array $said = [];

            public function flash(string $message, float $seconds = 2.5): void
            {
                $this->said[] = $message;
            }
        };
        $editor = new CodeEditorWidget(flash: $flash);
        $editor->openFile('demo.php', "alpha\n");
        $editor->setFocused(true);

        $editor->handleInput("\x1b[118;6u"); // ctrl+shift+v with nothing copied

        $this->assertFalse(EditorState::pickerOpen($editor), 'no picker over an empty ring');
        $this->assertSame(['Nothing copied yet'], $flash->said);
    }
}
