<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\EditorMouse;
use Porta\Editor\EditorViewport;
use Porta\Editor\FoldModel;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintMode;
use Porta\Editor\InlayLines;
use Porta\Editor\MultiCaret;

/**
 * A click, a drag and a hover land on the character the screen shows, with
 * inlay hints drawn into the line: right of a hint the text is shifted by its
 * width, and a press on the hint itself is the character it sits before.
 */
final class EditorMouseInlayTest extends TestCase
{
    private Document $document;
    private EditorDisplayOptions $display;
    private InlayHint $hint;

    /** `f(42, $y);` with `n: ` before the 4 — screen `f(n: 42, $y);`. */
    private function mouse(string $text = "f(42, \$y);\n", ?InlayHint $hint = null): EditorMouse
    {
        $this->document = new Document($text);
        $noop = static function (): void {};
        $store = new DecorationStore($noop);
        $this->hint = $hint ?? new InlayHint(2, 'n:', padRight: true);
        $store->setInlayHints([0 => [$this->hint]]);
        $this->display = new EditorDisplayOptions($noop);

        return new EditorMouse(
            $this->document,
            new FoldModel($this->document),
            $store,
            new InlayLines($store, $this->display),
            new MultiCaret($this->document, $noop, $noop),
            $noop,
            $noop,
            static function (int $line): void {},
            static function (int $line, int $index): void {},
        );
    }

    /** @param list<int> $sticky */
    private static function viewport(int $hScroll = 0, ?int $wrapWidth = null, array $sticky = []): EditorViewport
    {
        return new EditorViewport(0, $hScroll, gutterWidth: 2, foldCol: -1, stripeCol: 100, renderRows: 10, stickyLines: $sticky, wrapWidth: $wrapWidth);
    }

    public function testAClickRightOfAHintLandsOnTheCharacterDrawnThere(): void
    {
        $mouse = $this->mouse();

        $mouse->clickAt(0, 2 + 6, self::viewport()); // screen 6 is the 2 of 42
        $this->assertSame(3, $this->document->cursorCol);

        $mouse->clickAt(0, 2 + 1, self::viewport()); // left of the hint nothing moved
        $this->assertSame(1, $this->document->cursorCol);
    }

    public function testAClickOnTheHintLandsOnTheCharacterItSitsBefore(): void
    {
        $mouse = $this->mouse();

        foreach ([2, 3, 4] as $screen) {
            $mouse->clickAt(0, 2 + $screen, self::viewport());
            $this->assertSame(2, $this->document->cursorCol, "screen {$screen} is the hint, so the 4");
        }
    }

    public function testADragAcrossAHintSelectsExactlyTheText(): void
    {
        $mouse = $this->mouse();

        $mouse->beginSelectionAt(0, 2 + 1, self::viewport()); // the (
        $mouse->extendSelectionTo(0, 2 + 7, self::viewport()); // the , after 42

        $this->assertSame('(42', $this->document->getSelectedText());
    }

    public function testAHoverOnAHintFindsTheHintAndNoCharacter(): void
    {
        $mouse = $this->mouse();

        $this->assertSame($this->hint, $mouse->inlayHintAt(0, 2 + 3, self::viewport()));
        $this->assertNull($mouse->characterAt(0, 2 + 3, self::viewport()), 'there is no document character on a hint');
        $this->assertSame(['line' => 0, 'col' => 3], $mouse->characterAt(0, 2 + 6, self::viewport()));
        $this->assertNull($mouse->inlayHintAt(0, 2 + 6, self::viewport()));
        $this->assertNull($mouse->inlayHintAt(0, 0, self::viewport()), 'the gutter shows no hint');
    }

    public function testAHintOnlyShiftsWhatItIsDrawnInto(): void
    {
        $mouse = $this->mouse();
        $this->display->setInlayHints(InlayHintMode::Tooltip);

        $mouse->clickAt(0, 2 + 3, self::viewport());

        $this->assertSame(3, $this->document->cursorCol, 'a hint on a card moves nothing');
    }

    public function testScrolledTheScreenColumnStillAccountsForTheHint(): void
    {
        $mouse = $this->mouse();

        $mouse->clickAt(0, 2 + 3, self::viewport(hScroll: 3)); // screen 6
        $this->assertSame(3, $this->document->cursorCol);
    }

    public function testWrappedTheLastSegmentsHintShiftsOnlyItsOwnRow(): void
    {
        // Ten x then a hint before the last one: rows of 4 are 4 + 4 + 2 with
        // two cells free, so the hint is drawn on the third row.
        $mouse = $this->mouse(\str_repeat('x', times: 10)."\n", new InlayHint(9, 'ab'));

        $mouse->clickAt(1, 2 + 1, self::viewport(wrapWidth: 4));
        $this->assertSame(5, $this->document->cursorCol, 'the second row is plain text');

        $mouse->clickAt(2, 2, self::viewport(wrapWidth: 4));
        $this->assertSame(8, $this->document->cursorCol, 'the third row starts at its own offset');

        $mouse->clickAt(2, 2 + 3, self::viewport(wrapWidth: 4));
        $this->assertSame(9, $this->document->cursorCol, 'past the hint, the character after it');
    }

    public function testAStickyHeaderIsDrawnWithoutHints(): void
    {
        $mouse = $this->mouse();

        $mouse->clickAt(0, 2 + 3, self::viewport(sticky: [0]));

        $this->assertSame(3, $this->document->cursorCol, 'no shift on the pinned header');
    }
}
