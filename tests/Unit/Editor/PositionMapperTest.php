<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\IndentKind;
use Porta\Editor\PositionMapper;

final class PositionMapperTest extends TestCase
{
    public function testPositionAtClampsANegativeOffsetToTheStart(): void
    {
        $mapper = new PositionMapper(new Document("abc\ndef"));

        $this->assertSame(['line' => 0, 'col' => 0], $mapper->positionAt(-5));
    }

    public function testPositionAtZeroIsTheVeryStart(): void
    {
        $mapper = new PositionMapper(new Document("abc\ndef"));

        $this->assertSame(['line' => 0, 'col' => 0], $mapper->positionAt(0));
    }

    public function testPositionAtWalksForwardThroughMultipleLines(): void
    {
        $mapper = new PositionMapper(new Document("aa\nbb\ncc"));

        // Offsets: 'a'=0,'a'=1,'\n'=2,'b'=3,'b'=4,'\n'=5,'c'=6,'c'=7.
        $this->assertSame(['line' => 0, 'col' => 0], $mapper->positionAt(0));
        $this->assertSame(['line' => 0, 'col' => 1], $mapper->positionAt(1));
        $this->assertSame(['line' => 1, 'col' => 0], $mapper->positionAt(3));
        $this->assertSame(['line' => 1, 'col' => 1], $mapper->positionAt(4));
        $this->assertSame(['line' => 2, 'col' => 0], $mapper->positionAt(6));
        $this->assertSame(['line' => 2, 'col' => 1], $mapper->positionAt(7));
    }

    public function testPositionAtOnTheLastLineDoesNotOverrun(): void
    {
        $mapper = new PositionMapper(new Document("aa\nbb\ncc"));

        // Past the end of the text: clamps to the end of the last line.
        $this->assertSame(['line' => 2, 'col' => 2], $mapper->positionAt(8));
        $this->assertSame(['line' => 2, 'col' => 2], $mapper->positionAt(100));
    }

    public function testPositionAtRightAtALineBoundary(): void
    {
        $mapper = new PositionMapper(new Document("aa\nbb\ncc"));

        // Offset 2 is the newline right after "aa" — still counted as
        // (owned by) line 0, at the position right past its last real byte.
        $this->assertSame(['line' => 0, 'col' => 2], $mapper->positionAt(2));
        // Offset 5 is the newline after "bb".
        $this->assertSame(['line' => 1, 'col' => 2], $mapper->positionAt(5));
    }

    public function testVisualLinesIsMemoizedUntilTheVersionChanges(): void
    {
        $document = new Document("a\tb");
        $mapper = new PositionMapper($document);

        $first = $mapper->visualLines();
        $this->assertSame($first, $mapper->visualLines(), 'same version: same (cached) array');

        $document->insert('x');
        $second = $mapper->visualLines();
        $this->assertNotSame($first, $second, 'version bumped: cache must be rebuilt, not reused');
    }

    public function testInvalidateVisualLinesForcesARebuildOnTheNextCall(): void
    {
        $document = new Document("a\tb");
        $mapper = new PositionMapper($document);
        $this->assertSame(['a   b'], $mapper->visualLines());

        // Widen the tab stop and invalidate the cache directly: the document's
        // own version never changes, so only invalidateVisualLines() (not the
        // version check) can force the next call to re-expand at the new width.
        $document->setIndent(IndentKind::Spaces, 2);
        $mapper->invalidateVisualLines();

        $this->assertSame(['a b'], $mapper->visualLines(), 'invalidated: must rebuild even at the same document version');
    }
}
