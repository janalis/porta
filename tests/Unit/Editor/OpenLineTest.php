<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\LinePlacement;
use Porta\Editor\SelectionMode;
use Porta\Widget\CodeEditorWidget;

/**
 * `Shift+Enter` and `Ctrl+Alt+Enter` — start a new line below or above,
 * whatever column the caret is in.
 *
 * The whole reason they are separate verbs from Enter is the column: Enter
 * SPLITS the line at the caret, so starting a statement from the middle of one
 * costs an End first, and starting one above costs Home, Enter, Up. Almost
 * every test below therefore puts the caret mid-line, because a test written
 * with it at the end passes just as well against plain Enter.
 */
final class OpenLineTest extends TestCase
{
    public function testBelowOpensALineWithoutSplittingThisOne(): void
    {
        $doc = new Document("    return one();\n");
        $doc->setCursor(0, 8); // inside `return`

        $doc->openLine(LinePlacement::Below);

        $this->assertSame(['    return one();', '    ', ''], $doc->lines);
        $this->assertSame(1, $doc->cursorLine);
        $this->assertSame(4, $doc->cursorCol, 'the caret lands past the copied indent');
    }

    public function testAboveOpensALineWithoutSplittingThisOne(): void
    {
        $doc = new Document("    return one();\n");
        $doc->setCursor(0, 8);

        $doc->openLine(LinePlacement::Above);

        $this->assertSame(['    ', '    return one();', ''], $doc->lines);
        $this->assertSame(0, $doc->cursorLine);
        $this->assertSame(4, $doc->cursorCol);
    }

    /**
     * Below a line that OPENS a block, the new line goes one level in — the
     * same rule Enter follows, so the two keys do not disagree about the same
     * position.
     */
    public function testBelowAnOpenerTakesOneMoreLevel(): void
    {
        $doc = new Document("    if (x) {\n    }\n");
        $doc->setCursor(0, 6);

        $doc->openLine(LinePlacement::Below);

        $this->assertSame(['    if (x) {', '        ', '    }', ''], $doc->lines);
        $this->assertSame(8, $doc->cursorCol);
    }

    /**
     * And ABOVE one does not, deliberately. A line above a `}` is inside the
     * block while a line above an `if` is outside it, and the line itself does
     * not say which — so copying the indent is the neutral answer and adding a
     * level would be wrong half the time.
     */
    public function testAboveAnOpenerDoesNotGuessAtALevel(): void
    {
        $doc = new Document("    if (x) {\n");
        $doc->setCursor(0, 6);

        $doc->openLine(LinePlacement::Above);

        $this->assertSame(['    ', '    if (x) {', ''], $doc->lines);
    }

    /**
     * A selection is dropped rather than replaced. These verbs are not typing:
     * the point is to leave the line alone, and deleting the selected text
     * would be the one thing the user did not ask for.
     */
    public function testASelectionIsCollapsedAndNotDeleted(): void
    {
        $doc = new Document("alpha beta\n");
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 5, SelectionMode::Keep);

        $doc->openLine(LinePlacement::Below);

        $this->assertSame(['alpha beta', '', ''], $doc->lines, 'the selected text survives');
        $this->assertFalse($doc->hasSelection());
    }

    public function testTabIndentedLinesCopyTheirTabs(): void
    {
        $doc = new Document("\t\tvalue();\n");
        $doc->setCursor(0, 3);

        $doc->openLine(LinePlacement::Below);

        $this->assertSame("\t\t", $doc->getLine(1));
    }

    public function testOneKeypressIsOneUndoStep(): void
    {
        $doc = new Document("  a\n  b\n");
        $doc->setCursor(1, 3);

        $doc->openLine(LinePlacement::Above);
        $this->assertSame(['  a', '  ', '  b', ''], $doc->lines);

        $this->assertTrue($doc->undo());
        $this->assertSame(['  a', '  b', ''], $doc->lines);
    }

    /**
     * The chords, through a running widget — and this one is the reason the
     * test exists at all: `shift+enter` was an ALIAS for `new_line`, so this
     * verb had to take a chord away from another one, and a keymap that still
     * routed it to Enter would look correct in every assertion above.
     */
    public function testTheChordsReachTheVerbsAndShiftEnterNoLongerSplits(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "    return one();\n");
        $editor->getDocument()->setCursor(0, 8);

        $editor->handleInput("\x1b[13;2u"); // shift+enter
        $this->assertSame('    return one();', $editor->getDocument()->getLine(0), 'the line is intact, not split at column 8');
        $this->assertSame('    ', $editor->getDocument()->getLine(1));

        $editor->handleInput("\x1b[13;7u"); // ctrl+alt+enter
        $this->assertSame(['    return one();', '    ', '    ', ''], $editor->getDocument()->lines);
        $this->assertSame(1, $editor->getDocument()->cursorLine);

        // The control: plain Enter still splits, so the rebinding took the
        // chord and not the behaviour.
        $editor->getDocument()->setCursor(0, 8);
        $editor->handleInput("\r");
        $this->assertSame('    retu', $editor->getDocument()->getLine(0));
    }

    /** The verbs mark the buffer dirty, like every other edit. */
    public function testTheBufferIsDirtyAfterwards(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "a\n");
        $this->assertFalse($editor->getDocument()->isDirty(), 'clean to start with');

        $editor->handleInput("\x1b[13;2u");

        $this->assertTrue($editor->getDocument()->isDirty());
    }
}
