<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditHistory;

/**
 * The join between a Document's mutations and the list of places you typed.
 * A Document does not know its path and the history needs one, so the pairing
 * is BufferManager's — and only a real edit may reach it.
 */
final class EditTrackingTest extends TestCase
{
    public function testEditingABufferRecordsWHEREItHappened(): void
    {
        $history = new EditHistory();
        $buffers = new BufferManager(editHistory: $history);
        $document = $buffers->open('/a.php', "one\ntwo\nthree\n")->document;

        $document->setCursor(1, 3);
        $document->insert('!');

        $this->assertSame(['path' => '/a.php', 'line' => 1], $history->walkBack());
    }

    public function testMOVINGTheCaretIsNotAnEdit(): void
    {
        // The hook hangs on the mutation funnel, not on the cursor: reading a
        // file from top to bottom must not fill the list with every line.
        $history = new EditHistory();
        $buffers = new BufferManager(editHistory: $history);
        $document = $buffers->open('/a.php', "one\ntwo\nthree\n")->document;

        $document->setCursor(2, 0);
        $document->setCursor(0, 0);

        $this->assertNull($history->walkBack());
    }

    public function testEveryFILESEditsAreKeptApart(): void
    {
        $history = new EditHistory();
        $buffers = new BufferManager(editHistory: $history);
        $a = $buffers->open('/a.php', "one\n")->document;
        $b = $buffers->open('/b.php', "two\n")->document;

        $a->insert('x');
        $b->insert('y');

        $this->assertSame(['path' => '/b.php', 'line' => 0], $history->walkBack());
        $this->assertSame(['path' => '/a.php', 'line' => 0], $history->walkBack());
    }

    public function testAVIEWERTabRecordsNothing(): void
    {
        // A pane's document is an empty stand-in nobody types into; its
        // "edits" would put a picture in the list of places you were writing.
        $history = new EditHistory();
        $buffers = new BufferManager(editHistory: $history);

        $buffers->openPane('/logo.png', 'image', payload: null)->document->insert('x');

        $this->assertNull($history->walkBack());
    }

    public function testWithNoHistoryWiredTheDocumentStillWorks(): void
    {
        // The hook is optional on the same footing as the caret hooks: a
        // BufferManager built without one is what every unit test uses.
        $buffers = new BufferManager();
        $document = $buffers->open('/a.php', "one\n")->document;

        $document->insert('x');

        $this->assertSame('xone', $document->getLine(0));
    }
}
