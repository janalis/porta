<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\State\OverlayHost;
use Porta\Overlay\State\WindowControls;
use Porta\Tests\Support\Probe;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The pickers are windows like every other: dragged by the title bar,
 * resized from the edges, lit under the pointer — and still placed at their
 * tallest, so a narrowing list shrinks from the bottom wherever the user put
 * it. Bounds are a 24 × 100 workspace.
 */
final class PickerWindowTest extends TestCase
{
    private const array BOUNDS = [0, 0, 24, 100];

    /** @return list<array{label: string, hint: string, value: string}> */
    private static function items(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = ['label' => "item {$i}", 'hint' => '', 'value' => (string) $i];
        }

        return $items;
    }

    private function host(?WindowControls $controls = null): OverlayHost
    {
        $host = new OverlayHost(styles: Styles::dark(), controls: $controls);
        $host->openList('Pick', self::items(8), static function (): void {});
        $host->layout(self::BOUNDS);

        return $host;
    }

    private static function box(OverlayHost $host, string $slot = 'overlayBox'): ModalBox
    {
        $box = Probe::get($host, $slot);
        self::assertInstanceOf(ModalBox::class, $box);

        return $box;
    }

    public function testTheTitleBarMovesTheList(): void
    {
        $host = $this->host();
        $box = self::box($host);

        $this->assertTrue($host->pickerClickAt($box->startRow, $box->col + 4));
        $this->assertTrue($host->continueDragTo($box->startRow + 3, $box->col - 6, release: true));
        $host->layout(self::BOUNDS);

        $moved = self::box($host);
        $this->assertSame([$box->startRow + 3, $box->col - 10, $box->width, $box->rows], [$moved->startRow, $moved->col, $moved->width, $moved->rows]);
        $this->assertNotNull(Probe::get($host, 'overlay'), 'a drag never dismisses it');
    }

    public function testAnEdgeResizesTheListAndItStillShrinksFromTheBottom(): void
    {
        $host = $this->host();
        $box = self::box($host);
        $bottom = $box->startRow + $box->rows - 1;

        $host->pickerClickAt($bottom, $box->col + $box->width - 1); // the bottom-right corner
        $host->continueDragTo($bottom + 2, $box->col + $box->width + 9, release: true);
        $host->layout(self::BOUNDS);
        $grown = self::box($host);
        $this->assertSame([$box->startRow, $box->width + 10], [$grown->startRow, $grown->width], 'wider, the top where it was');

        // Narrow the list to one row: the box keeps its top and its width and
        // loses rows from the bottom — nothing under the reader's eye moves.
        foreach (\str_split('item 7') as $char) {
            $host->handleInput($char, new Keybindings([]));
        }
        $host->layout(self::BOUNDS);
        $narrowed = self::box($host);
        $this->assertSame([$grown->startRow, $grown->width], [$narrowed->startRow, $narrowed->width]);
        $this->assertLessThan($grown->rows, $narrowed->rows, 'it shrank from the bottom');
    }

    public function testTheResizeHandleLightsUnderThePointer(): void
    {
        $host = $this->host();
        $box = self::box($host);

        $this->assertTrue($host->pickerHoverAt($box->startRow + 2, $box->col + $box->width - 1), 'onto the right edge');
        $placed = $host->layout(self::BOUNDS);

        $this->assertStringContainsString(Glyphs::MODAL_GRAB_VERTICAL, \implode('', $placed[0]['rows']));
    }

    public function testThePromptWidensButKeepsItsOneField(): void
    {
        $host = new OverlayHost(styles: Styles::dark());
        $host->openPrompt('Go to line', '', static function (): void {});
        $host->layout(self::BOUNDS);
        $box = self::box($host, 'promptBox');
        $bottom = $box->startRow + $box->rows - 1;

        $host->pickerClickAt($bottom, $box->col + $box->width - 1);
        $host->continueDragTo($bottom + 5, $box->col + $box->width + 7, release: true);
        $host->layout(self::BOUNDS);

        $resized = self::box($host, 'promptBox');
        $this->assertSame([$box->width + 8, $box->rows], [$resized->width, $resized->rows], 'wider, never taller than its field');
    }

    public function testAFreshOpenForgetsWhereTheLastListWasPut(): void
    {
        $host = $this->host();
        $box = self::box($host);
        $host->pickerClickAt($box->startRow, $box->col + 4);
        $host->continueDragTo($box->startRow + 3, $box->col + 4, release: true);

        $host->closeList();
        $host->openList('Pick', self::items(8), static function (): void {});
        $host->layout(self::BOUNDS);

        $this->assertSame($box->startRow, self::box($host)->startRow, 'back where lists open');
    }

    public function testCancelDropsADragOnEverySurface(): void
    {
        $host = $this->host();
        $box = self::box($host);
        $host->pickerClickAt($box->startRow, $box->col + 4);

        $host->cancelDrag();

        $this->assertFalse($host->continueDragTo(5, 5, release: false));
    }

    public function testNothingIsDraggedWithNoPickerOpen(): void
    {
        $this->assertFalse(new OverlayHost()->continueDragTo(5, 5, release: false));
    }

    public function testOnMacOsTheRedButtonLeadsEveryPicker(): void
    {
        $host = $this->host(new WindowControls('Darwin'));
        $box = self::box($host);
        $placed = $host->layout(self::BOUNDS);

        $this->assertStringStartsWith('╭'.Glyphs::WINDOW_CLOSE_MAC, AnsiUtils::stripAnsiCodes($placed[0]['rows'][0]));
        $this->assertTrue($host->pickerClickAt($box->startRow, $box->col + 1), 'a press on it');
        $this->assertNull(Probe::get($host, 'overlay'), 'closes the list');
    }

    public function testAResizeStopsAtTheFloors(): void
    {
        $host = $this->host();
        $box = self::box($host);

        $host->pickerClickAt($box->startRow, $box->col); // the top-left corner
        $host->continueDragTo($box->startRow + 50, $box->col + 90, release: true);
        $host->layout(self::BOUNDS);

        $this->assertSame(20 + 4, self::box($host)->width, 'a list keeps room for a label: 20 inside, the frame around it');
        $this->assertSame(1, self::box($host)->count, 'and one row of it');
    }

    public function testThePromptIsFlooredAndNeverTallerThanItsField(): void
    {
        $host = new OverlayHost(styles: Styles::dark());
        $host->openPrompt('Go to line', '', static function (): void {});
        $host->layout(self::BOUNDS);
        $box = self::box($host, 'promptBox');

        $host->pickerClickAt($box->startRow + $box->rows - 1, $box->col); // the bottom-left corner
        $host->continueDragTo($box->startRow - 5, $box->col + 90, release: true);
        $host->layout(self::BOUNDS);

        $resized = self::box($host, 'promptBox');
        $this->assertSame([24, $box->rows], [$resized->width, $resized->rows], 'floored at 20 inside; its one field, whatever the drag asked');
    }

    public function testEachPickerOpensFreshAndCancelReachesEveryOne(): void
    {
        $host = new OverlayHost(styles: Styles::dark());
        $host->openSearch('Search', static function (): void {}, static function (): void {}, static function (): void {});
        $host->layout(self::BOUNDS);
        $search = self::box($host, 'searchBox');
        $host->pickerClickAt($search->startRow, $search->col + 4);
        $host->continueDragTo($search->startRow + 3, $search->col + 4, release: true);
        $host->openSearch('Search', static function (): void {}, static function (): void {}, static function (): void {});
        $host->layout(self::BOUNDS);
        $this->assertSame($search->startRow, self::box($host, 'searchBox')->startRow, 'a fresh search opens where searches open');

        $host->openPrompt('Rename', '', static function (): void {});
        $host->layout(self::BOUNDS);
        $prompt = self::box($host, 'promptBox');
        $host->pickerClickAt($prompt->startRow, $prompt->col + 4);
        $host->continueDragTo($prompt->startRow + 2, $prompt->col + 4, release: true);
        $host->openPrompt('Rename', '', static function (): void {});
        $host->layout(self::BOUNDS);
        $this->assertSame($prompt->startRow, self::box($host, 'promptBox')->startRow, 'so does a fresh prompt');

        $host->pickerClickAt($prompt->startRow, $prompt->col + 4); // the prompt is topmost: arm its move
        $host->cancelDrag();
        $this->assertFalse($host->continueDragTo(5, 5, release: false), 'the prompt\'s drag was dropped');
        $host->pickerClickAt(0, 0); // a press outside the prompt closes it
        $host->pickerClickAt($search->startRow, $search->col + 4);
        $host->cancelDrag();
        $this->assertFalse($host->continueDragTo(5, 5, release: false), 'and the search\'s');
    }

    /** A press on a handle resizes; it never reaches the list under it. */
    public function testAHandlePressNeverActsOnTheList(): void
    {
        $host = new OverlayHost(styles: Styles::dark());
        $host->openList('Pick', self::items(40), static function (): void {});
        $host->layout(self::BOUNDS);
        $box = self::box($host);
        $before = Probe::get($host, 'overlay')['selected'] ?? null;

        $host->pickerClickAt($box->startRow + $box->rows - 1, $box->col + 6); // the bottom border, over the ▼ cue's row
        $host->pickerClickAt($box->startRow + 4, $box->col + $box->width - 1); // the right rail

        $this->assertSame($before, Probe::get($host, 'overlay')['selected'] ?? null, 'the selection did not page');
    }
}
