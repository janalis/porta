<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ClipboardRing;
use Porta\Editor\SelectionMode;
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
}
