<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\ReadOnlyInput;
use Porta\Editor\SelectionMode;

/**
 * A read-only document takes every key and gives back what a key changed in
 * the text — and only that: the caret and the selection a key moved stay
 * moved, and the undo history does not learn of the edit.
 */
final class ReadOnlyInputTest extends TestCase
{
    public function testAWritableDocumentTakesTheEdit(): void
    {
        $document = new Document("abc\n");

        $this->assertFalse(ReadOnlyInput::refuse($document, static fn () => $document->insert('X')));
        $this->assertSame("Xabc\n", $document->getText());
    }

    public function testAReadOnlyDocumentRefusesTheKeyWhole(): void
    {
        $document = new Document("abc\ndef\n");
        $document->setCursor(1, 3);
        $document->beginSelect();
        $document->setCursor(1, 1, SelectionMode::Keep); // "ef", made backwards
        $document->readOnly = true;

        $this->assertTrue(ReadOnlyInput::refuse($document, static function () use ($document): void {
            $document->insert("XYZ\n");
        }));

        $this->assertSame("abc\ndef\n", $document->getText(), 'the text is what it was');
        $this->assertSame([1, 1], [$document->cursorLine, $document->cursorCol], 'the caret too');
        $this->assertSame('ef', $document->getSelectedText(), 'and the selection');
        $this->assertFalse($document->isDirty(), 'a clean buffer reads clean again');
        $this->assertFalse($document->undo(), 'and there is no step to undo');
        $this->assertTrue($document->readOnly, 'still read-only');
    }

    public function testASelectionMadeForwardsComesBackTheSameWay(): void
    {
        $document = new Document("abcdef\n");
        $document->setCursor(0, 1);
        $document->beginSelect();
        $document->setCursor(0, 4, SelectionMode::Keep);
        $document->readOnly = true;

        $this->assertTrue(ReadOnlyInput::refuse($document, static fn () => $document->insert('Q')));
        $this->assertSame('bcd', $document->getSelectedText());
        $this->assertSame(4, $document->cursorCol);
    }

    public function testAMoveThatChangesNothingIsNotRefused(): void
    {
        $document = new Document("abc\ndef\n");
        $document->readOnly = true;

        $this->assertFalse(ReadOnlyInput::refuse($document, static function () use ($document): void {
            $document->setCursor(1, 0);
            $document->beginSelect();
            $document->setCursor(1, 2, SelectionMode::Keep);
        }));
        $this->assertSame('de', $document->getSelectedText(), 'a selection is not an edit');
    }
}
