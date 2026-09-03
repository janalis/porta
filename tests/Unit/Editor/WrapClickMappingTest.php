<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\EditorMouse;
use Porta\Editor\EditorViewport;
use Porta\Editor\FoldModel;
use Porta\Editor\MultiCaret;

/**
 * A click below a wrapped line must land on the line it looks like it is on.
 * Unwrapped, rows and lines run one to one; wrapped they diverge by a row for
 * every extra segment above the click, so the error grows the further down the
 * pane you go — which is exactly the kind of bug that reads as "clicking is
 * off by a bit" rather than as a mapping that was never told about wrapping.
 */
final class WrapClickMappingTest extends TestCase
{
    private const int WIDTH = 20;

    private Document $document;

    private function mouse(): EditorMouse
    {
        // A 60-column first line — three segments at WIDTH — then short ones.
        $this->document = new Document(str_repeat('x', times: 60)."\nsecond\nthird\nfourth\n");
        $noop = static function (): void {};

        return new EditorMouse(
            $this->document,
            new FoldModel($this->document),
            new MultiCaret($this->document, $noop, $noop),
            $noop,
            $noop,
            static function (int $line): void {},
        );
    }

    private function viewport(?int $wrapWidth): EditorViewport
    {
        return new EditorViewport(
            vScroll: 0,
            hScroll: 0,
            gutterWidth: 0,
            foldCol: -1,
            stripeCol: 100, // beyond every click below, so none is read as a stripe jump
            renderRows: 12,
            stickyLines: [],
            wrapWidth: $wrapWidth,
        );
    }

    /** @return array{int, int} line, display column */
    private function clickAt(EditorMouse $mouse, int $row, int $col, ?int $wrapWidth): array
    {
        $mouse->clickAt($row, $col, $this->viewport($wrapWidth));

        return [$this->document->getCursorLine(), $this->document->getCursorDisplayCol()];
    }

    public function testUnwrappedEveryRowIsItsOwnLine(): void
    {
        $mouse = $this->mouse();

        $this->assertSame(0, $this->clickAt($mouse, 0, 3, null)[0]);
        $this->assertSame(1, $this->clickAt($mouse, 1, 3, null)[0], 'second');
        $this->assertSame(2, $this->clickAt($mouse, 2, 3, null)[0], 'third');
    }

    /** Wrapped, the long line holds three rows and the short lines follow it. */
    public function testWrappedTheRowsBelowALongLineStillHitTheRightLines(): void
    {
        $mouse = $this->mouse();

        $this->assertSame(0, $this->clickAt($mouse, 0, 3, self::WIDTH)[0], 'first segment');
        $this->assertSame(0, $this->clickAt($mouse, 1, 3, self::WIDTH)[0], 'second segment, same line');
        $this->assertSame(0, $this->clickAt($mouse, 2, 3, self::WIDTH)[0], 'third segment, still the same line');

        $this->assertSame(1, $this->clickAt($mouse, 3, 3, self::WIDTH)[0], 'second');
        $this->assertSame(2, $this->clickAt($mouse, 4, 3, self::WIDTH)[0], 'third');
        $this->assertSame(3, $this->clickAt($mouse, 5, 3, self::WIDTH)[0], 'fourth');
    }

    /** The column resolves within its own segment, not from the line's start. */
    public function testAContinuationRowResolvesItsOwnColumns(): void
    {
        $mouse = $this->mouse();

        $this->assertSame([0, 3], $this->clickAt($mouse, 0, 3, self::WIDTH), 'first segment: the column as clicked');
        $this->assertSame([0, self::WIDTH + 3], $this->clickAt($mouse, 1, 3, self::WIDTH), 'second segment: one width further in');
        $this->assertSame([0, (2 * self::WIDTH) + 3], $this->clickAt($mouse, 2, 3, self::WIDTH), 'third segment: two');
    }

    /** Past the last row, the click lands on the last line rather than nowhere. */
    public function testAClickBelowEverythingLandsOnTheLastLine(): void
    {
        $mouse = $this->mouse();

        $this->assertSame(4, $this->clickAt($mouse, 11, 0, self::WIDTH)[0]);
    }
}
