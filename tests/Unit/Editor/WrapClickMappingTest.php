<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
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
    private DecorationStore $store;
    /** @var list<string> lenses run by a click, as "line:index" */
    private array $ran = [];

    private function mouse(): EditorMouse
    {
        // A 60-column first line — three segments at WIDTH — then short ones.
        $this->document = new Document(\str_repeat('x', times: 60)."\nsecond\nthird\nfourth\n");
        $noop = static function (): void {};

        return new EditorMouse(
            $this->document,
            new FoldModel($this->document),
            $this->store = new DecorationStore($noop),
            new MultiCaret($this->document, $noop, $noop),
            $noop,
            $noop,
            static function (int $line): void {},
            function (int $line, int $index): void {
                $this->ran[] = "{$line}:{$index}";
            },
        );
    }

    /** @return array{title: string, command: string, arguments: list<mixed>} */
    private static function lens(string $title): array
    {
        return ['title' => $title, 'command' => 'lens.run', 'arguments' => []];
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

        return [$this->document->cursorLine, $this->document->getCursorDisplayCol()];
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

    /**
     * A lens row shifts every row below it, so a click has to count them —
     * this is the same divergence wrapping causes, from the other direction,
     * and it is why both flow through one walk.
     */
    public function testLensRowsShiftTheLinesBelowThemForClicksToo(): void
    {
        $mouse = $this->mouse();
        $this->store->setCodeLenses([0 => [self::lens('3 usages')], 1 => [self::lens('1 usage'), self::lens('2 implementations')]]);

        $this->assertSame(0, $this->clickAt($mouse, 0, 3, null)[0], 'the lens above line 0');
        $this->assertSame(0, $this->clickAt($mouse, 1, 3, null)[0], 'then line 0 itself');
        $this->assertSame(1, $this->clickAt($mouse, 2, 3, null)[0], "line 1's first lens");
        $this->assertSame(1, $this->clickAt($mouse, 3, 3, null)[0], 'its second');
        $this->assertSame(1, $this->clickAt($mouse, 4, 3, null)[0], 'then line 1 itself');
        $this->assertSame(2, $this->clickAt($mouse, 5, 3, null)[0], 'and line 2 follows');
    }

    /** A click on a lens row means the line it describes, at its start. */
    public function testAClickOnALensRowResolvesToTheLineStart(): void
    {
        $mouse = $this->mouse();
        $this->store->setCodeLenses([1 => [self::lens('3 usages')]]);

        $this->assertSame([1, 0], $this->clickAt($mouse, 1, 7, null), 'the lens row carries none of the line\'s columns');
    }

    /** Lenses and wrapping compose: both add rows to the same line. */
    public function testLensesAndWrappingBothCount(): void
    {
        $mouse = $this->mouse();
        $this->store->setCodeLenses([0 => [self::lens('3 usages')]]);

        // Row 0 is the lens, rows 1..3 the three wrapped segments, row 4 'second'.
        $this->assertSame(0, $this->clickAt($mouse, 0, 3, self::WIDTH)[0]);
        $this->assertSame([0, 3], $this->clickAt($mouse, 1, 3, self::WIDTH), 'first segment, not offset by the lens');
        $this->assertSame([0, self::WIDTH + 3], $this->clickAt($mouse, 2, 3, self::WIDTH), 'second segment');
        $this->assertSame(1, $this->clickAt($mouse, 4, 3, self::WIDTH)[0], 'second');
    }

    /**
     * Clicking a lens runs THAT lens — the second one above a line is index 1,
     * not index 0 — and still moves the caret to the line it describes, since
     * clicking it is also a way of going there.
     */
    public function testClickingALensRunsTheOneClicked(): void
    {
        $mouse = $this->mouse();
        $this->store->setCodeLenses([1 => [self::lens('3 usages'), self::lens('2 implementations')]]);

        // Row 0 is line 0; rows 1 and 2 are line 1's two lenses.
        $this->clickAt($mouse, 1, 4, null);
        $this->assertSame(['1:0'], $this->ran, 'the first lens above line 1');

        $this->clickAt($mouse, 2, 4, null);
        $this->assertSame(['1:0', '1:1'], $this->ran, 'and the second');

        $this->assertSame(1, $this->document->cursorLine, 'the caret went to the line the lens describes');
    }

    /** A click on ordinary code runs nothing. */
    public function testClickingCodeRunsNoLens(): void
    {
        $mouse = $this->mouse();
        $this->store->setCodeLenses([1 => [self::lens('3 usages')]]);

        $this->clickAt($mouse, 2, 4, null); // line 1 itself, below its lens

        $this->assertSame([], $this->ran);
        $this->assertSame(1, $this->document->cursorLine);
    }

    /** A block row under a line shifts the lines below it, and a click on it lands on the line it is about. */
    public function testBlockRowsShiftTheLinesBelowAndAClickOnOneIsItsLine(): void
    {
        $mouse = $this->mouse();
        $this->store->setLineBlocks([1 => ['alice: rename', 'bob: why?']]);

        $this->assertSame(1, $this->clickAt($mouse, 1, 3, null)[0], 'second');
        $this->assertSame([1, 3], $this->clickAt($mouse, 2, 3, null), 'the first block row is its line, as the line\'s own last row');
        $this->assertSame(1, $this->clickAt($mouse, 3, 3, null)[0], 'and the second');
        $this->assertSame(2, $this->clickAt($mouse, 4, 3, null)[0], 'third follows the block');

        // Wrapped, a block row is the line's LAST segment, never a segment past it.
        $this->store->setLineBlocks([0 => ['note']]);
        $this->assertSame(0, $this->clickAt($mouse, 3, 3, self::WIDTH)[0], 'the block under the three segments');
        $this->assertSame(1, $this->clickAt($mouse, 4, 3, self::WIDTH)[0], 'then second');
    }
}
