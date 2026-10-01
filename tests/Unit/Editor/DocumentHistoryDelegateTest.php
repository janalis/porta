<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;

/**
 * Undo handed to someone else — a shared session's own history — and the
 * snapshots forgotten on each hand-over, so neither side replays the other's.
 */
final class DocumentHistoryDelegateTest extends TestCase
{
    public function testUndoAndRedoAskTheDelegateWhileOneIsSet(): void
    {
        $document = new Document("a\n");
        $asked = [];
        $document->historyDelegate = [
            'undo' => static function () use (&$asked): bool {
                $asked[] = 'undo';

                return true;
            },
            'redo' => static function () use (&$asked): bool {
                $asked[] = 'redo';

                return false;
            },
        ];
        $document->insert('x');

        $this->assertTrue($document->undo());
        $this->assertFalse($document->redo());
        $this->assertSame(['undo', 'redo'], $asked);
        $this->assertSame("xa\n", $document->getText(), 'the document\'s own snapshots were not replayed');
    }

    public function testEachHandOverForgetsTheSnapshotsSoFar(): void
    {
        $document = new Document("a\n");
        $document->insert('x');
        $document->historyDelegate = ['undo' => static fn (): bool => true, 'redo' => static fn (): bool => true];
        $document->insert('y');
        $document->historyDelegate = null;

        $this->assertFalse($document->undo(), 'nothing from before or during the hand-over is replayed');
        $this->assertSame("xya\n", $document->getText());

        $document->insert('z');
        $this->assertTrue($document->undo(), 'an edit made after it is the document\'s own again');
        $this->assertSame("xya\n", $document->getText());
    }

    public function testBecomingReadOnlyOrWritableStartsAFreshHistory(): void
    {
        $document = new Document("a\n");
        $document->insert('x');
        $document->readOnly = false;

        $this->assertFalse($document->undo());
    }
}
