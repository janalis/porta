<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\AutoPairService;
use Porta\Editor\Document;
use Porta\Editor\SelectionMode;

final class AutoPairServiceTest extends TestCase
{
    /**
     * A quote at column 0 pairs, whatever the line ends with.
     *
     * The "right after a word character" check reads the character before the
     * caret, and at column 0 there is none. Read as `$line[-1]`, PHP hands back
     * the LAST character of the line — so a line ending in a letter refused to
     * pair a quote typed at its start.
     */
    public function testAQuoteAtTheStartOfALineEndingInALetterStillPairs(): void
    {
        $doc = new Document(' abc'); // a space after the caret, a letter at the end
        $doc->setCursor(0, 0);

        $this->assertTrue(new AutoPairService()->handle($doc, "'"));
        $this->assertSame("'' abc", $doc->getText());
    }

    /** The word-character check looks at the character IMMEDIATELY before the caret, not further back. */
    public function testAQuoteRightAfterAWordCharacterDoesNotPairEvenWithASpaceBeforeIt(): void
    {
        $doc = new Document('x b');
        $doc->setCursor(0, 3);

        $this->assertFalse(new AutoPairService()->handle($doc, "'"), "b' is an apostrophe, not the start of a string");
    }

    /**
     * Typing through an auto-pair and over its closer is one run of typing:
     * the step back inside the pair moved the caret off where the typed text
     * ended, and every bracket split the run into another Ctrl+Z.
     */
    public function testTypingThroughAPairIsOneUndoStep(): void
    {
        $doc = new Document('');
        $pairs = new AutoPairService();
        foreach (\str_split('f($x)') as $key) {
            if ($pairs->handle($doc, $key)) {
                continue;
            }
            $doc->insert($key);
        }
        $this->assertSame('f($x)', $doc->getText());

        $doc->undo();
        $this->assertSame('', $doc->getText());
    }

    /** Wrapping a selection is one edit: a single undo takes both brackets back out. */
    public function testWrappingASelectionIsUndoneInOneStep(): void
    {
        $doc = new Document('abc');
        $doc->setCursor(0, 0);
        $doc->beginSelect();
        $doc->setCursor(0, 3, selection: SelectionMode::Keep);

        $this->assertTrue(new AutoPairService()->handle($doc, '('));
        $this->assertSame('(abc)', $doc->getText());
        $this->assertSame('abc', $doc->getSelectedText(), 'the wrapped text is re-selected');

        $doc->undo();
        $this->assertSame('abc', $doc->getText(), 'one undo, both brackets gone');
    }

    /**
     * The same, with markers long enough that the document would never merge
     * the two inserts on its own: short typed inserts coalesce into one undo
     * step anyway, which is why dropping the compound around a one-character
     * pair changed nothing a test could see.
     */
    public function testAWrapLongerThanTypingCoalesceIsStillOneUndoStep(): void
    {
        $doc = new Document('abc');

        new AutoPairService()->wrapSelection($doc, '<<<<<', '>>>>>', ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 3]);
        $this->assertSame('<<<<<abc>>>>>', $doc->getText());

        $doc->undo();
        $this->assertSame('abc', $doc->getText(), 'one undo, both markers gone');
    }

    /** The wrap's undo step is CLOSED: the next edit is an undo step of its own, not folded into the wrap. */
    public function testTheEditAfterAWrapUndoesSeparately(): void
    {
        $doc = new Document('abc');
        new AutoPairService()->wrapSelection($doc, '<<<<<', '>>>>>', ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 3]);
        $doc->setCursor(0, 13);
        $doc->insert(' tail');

        $doc->undo();
        $this->assertSame('<<<<<abc>>>>>', $doc->getText(), 'the first undo takes only the later edit');
    }
}
