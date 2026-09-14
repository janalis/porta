<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\AutoPairService;
use Porta\Editor\Document;
use Porta\Editor\EditorActions;
use Porta\Editor\EditorCompletion;
use Porta\Editor\EditorStructural;
use Porta\Editor\FoldModel;
use Porta\Editor\MultiCaret;
use Porta\Input\TextInput;
use Porta\Tests\Support\FakeEditorHost;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Column selection from the keyboard.
 *
 * The rectangle itself was always real — `MultiCaret::setColumnSelection()`
 * builds one caret per row — but reachable only by Alt+Shift+drag, because the
 * mouse supplies all four corners on every event. What the keyboard needed is
 * an anchor that survives keystrokes, and a VIRTUAL column: stepping down
 * through a short line must not narrow the rectangle for good.
 */
final class ColumnModeTest extends TestCase
{
    private Document $doc;
    private MultiCaret $multiCaret;

    /** @var list<string> host methods called, in order */
    private array $hostCalls = [];

    private static function keys(): Keybindings
    {
        return new Keybindings([
            'toggle_column_mode' => ['c'],
            'select_down' => ['d'],
            'select_up' => ['u'],
            'select_right' => ['r'],
            'select_left' => ['l'],
            'select_cancel' => ['x'],
        ]);
    }

    private function actions(string $text): EditorActions
    {
        $this->doc = new Document($text);
        $noop = static function (): void {};
        $this->hostCalls = [];
        $record = function (string $method, array $args): void {
            $this->hostCalls[] = $method;
        };
        $this->multiCaret = new MultiCaret($this->doc, $noop, $noop);
        $autoPair = new AutoPairService();
        $completion = new EditorCompletion($this->doc, $noop, $noop, TextInput::isPrintable(...), $noop, $noop, $noop);
        $structural = new EditorStructural($this->doc, $autoPair, $noop, $noop, $noop);

        return new EditorActions(
            $this->doc,
            $this->multiCaret,
            $completion,
            $structural,
            new FoldModel($this->doc),
            $autoPair,
            new FakeEditorHost($record),
        );
    }

    /** @return list<string> each caret's selected text, top to bottom */
    private function selections(): array
    {
        $text = $this->multiCaret->selectedBlockText();

        return '' === $text ? [] : \explode("\n", $text);
    }

    private function press(EditorActions $actions, string ...$keys): void
    {
        foreach ($keys as $key) {
            $actions->handleKey($key, self::keys());
        }
    }

    public function testTheModeIsOffUntilToggled(): void
    {
        $actions = $this->actions("abcd\nefgh\n");

        $this->assertFalse($actions->columnMode());

        $this->press($actions, 'c');
        $this->assertTrue($actions->columnMode());

        $this->press($actions, 'c');
        $this->assertFalse($actions->columnMode());
    }

    public function testDownAndRightBuildARectangleOneCaretPerRow(): void
    {
        $actions = $this->actions("abcd\nefgh\nijkl\n");
        $this->doc->setCursor(0, 1); // on 'b'

        $this->press($actions, 'c', 'd', 'd', 'r');

        // Rows 0..2, columns 1..2 — one character per row.
        $this->assertSame(['b', 'f', 'j'], $this->selections());
    }

    public function testTheRectangleIsVISUALAcrossALineContainingATab(): void
    {
        // Byte columns would drift here: the tab is one byte and several
        // display columns, which is why setColumnSelection works in display
        // space and the anchor is kept there too.
        $actions = $this->actions("abcdefgh\n\tXY\n");
        $this->doc->setCursor(0, 4); // display column 4

        $this->press($actions, 'c', 'd', 'r');

        $selections = $this->selections();
        $this->assertSame('e', $selections[0], 'the first row takes the character at display column 4');
        $this->assertCount(2, $selections, 'and the tab line contributes its own cell, not a byte-shifted one');
    }

    public function testAShortLineDoesNotNarrowTheRectangleForGood(): void
    {
        // The virtual column. Passing over a short line clamps what that ROW
        // can select, but the remembered column has to survive so the rows
        // below it are full width again.
        $actions = $this->actions("abcdef\nxy\nabcdef\n");
        $this->doc->setCursor(0, 4);

        $this->press($actions, 'c', 'r', 'd', 'd');

        $selections = $this->selections();
        $this->assertSame('e', $selections[0]);
        $this->assertSame('e', \array_last($selections), 'the last row is as wide as the first');
    }

    public function testPlainSelectionIsUntouchedWhileTheModeIsOff(): void
    {
        // The mode intercepts the selection verbs; with it off they must reach
        // the ordinary linear path exactly as before.
        $actions = $this->actions("abcd\nefgh\n");
        $this->doc->setCursor(0, 0);

        $this->press($actions, 'd');

        $this->assertFalse($this->multiCaret->hasMultiple(), 'a linear selection makes no extra carets');
    }

    public function testAKeyTheModeDoesNotOwnFallsThroughToTheRestOfTheCascade(): void
    {
        // Column mode claims the selection verbs and nothing else; every other
        // key must still reach the handlers below it.
        $actions = $this->actions("abcd\nefgh\n");
        $this->doc->setCursor(0, 0);
        $this->press($actions, 'c');

        $actions->handleKey('z', new Keybindings(['toggle_column_mode' => ['c'], 'undo' => ['z']]));

        $this->assertTrue($actions->columnMode(), 'an unrelated key leaves the mode alone');
    }

    public function testEnteringTheModeAsksForARepaint(): void
    {
        // The status bar is where the mode announces itself; without a repaint
        // the COLUMN cell does not appear until something else redraws.
        $actions = $this->actions("abcd\n");
        $this->hostCalls = [];

        $this->press($actions, 'c');

        $this->assertContains('invalidate', $this->hostCalls);
    }

    public function testEachRectangleMoveAsksForARepaint(): void
    {
        $actions = $this->actions("abcd\nefgh\n");
        $this->doc->setCursor(0, 1);
        $this->press($actions, 'c');
        $this->hostCalls = [];

        $this->press($actions, 'd');

        $this->assertContains('invalidate', $this->hostCalls);
    }

    public function testEscapeLeavesTheModeAsWellAsTheSelection(): void
    {
        $actions = $this->actions("abcd\nefgh\n");
        $this->doc->setCursor(0, 1);
        $this->press($actions, 'c', 'd');
        $this->assertTrue($actions->columnMode());

        $this->press($actions, 'x');

        $this->assertFalse($actions->columnMode(), 'the key that gets you out of everything else gets you out of this');
        // Escape must reach the ordinary cancel too, or the rectangle's carets
        // survive a mode nobody is in any more.
        $this->assertFalse($this->multiCaret->hasMultiple(), 'and the carets go with it');
    }

    public function testTogglingOffLeavesTheCaretsAloneRatherThanCollapsingThem(): void
    {
        // Leaving the mode ends the RECTANGLE, not the multi-caret edit you
        // built with it — that is the whole point of building one.
        $actions = $this->actions("abcd\nefgh\n");
        $this->doc->setCursor(0, 1);
        $this->press($actions, 'c', 'd', 'r');
        $this->assertTrue($this->multiCaret->hasMultiple());

        $this->press($actions, 'c');

        $this->assertTrue($this->multiCaret->hasMultiple());
    }
}
