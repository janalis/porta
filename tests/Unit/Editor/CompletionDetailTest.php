<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionItem;
use Porta\Editor\Document;
use Porta\Editor\EditorCompletion;
use Porta\Tests\Support\Probe;
use Porta\Widget\Render\PopupMetrics;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The popup's type column against servers that do not send a type.
 *
 * Measured on both bundled servers, and they disagree — which is the whole
 * reason this exists. phpantom_lsp answers `detail: "string"` in the completion
 * response; phpactor sends label, kind and insertText and NOTHING else, and
 * answers `pub greet(string $who): string` only to `completionItem/resolve`.
 * A type column fed by the first response alone is empty against the second.
 */
final class CompletionDetailTest extends TestCase
{
    /** @var list<array<string, mixed>> raw items the popup asked about */
    private array $asked = [];

    /** @var list<\Closure(string): void> how to answer each of them */
    private array $answer = [];

    private function completion(Document $document = new Document('')): EditorCompletion
    {
        $noop = static function (): void {};

        return new EditorCompletion(
            $document,
            $noop,
            $noop,
            static fn (): bool => true,
            $noop,
            $noop,
            function (array $item, \Closure $onDetail): void {
                $this->asked[] = $item;
                $this->answer[] = $onDetail;
            },
        );
    }

    /** @return list<CompletionItem> */
    private function itemsOf(EditorCompletion $completion): array
    {
        /** @var list<CompletionItem> */
        return Probe::get($completion, 'items');
    }

    /** @param non-empty-string $label */
    private function item(string $label, string $detail = ''): CompletionItem
    {
        return new CompletionItem(label: $label, insertText: $label, kind: 'fn', detail: $detail, raw: ['label' => $label]);
    }

    public function testAnItemWithNoTypeIsAskedAbout(): void
    {
        $completion = $this->completion();

        $completion->show([$this->item('greet')]);

        $this->assertSame([['label' => 'greet']], $this->asked, 'the raw server item goes back as the resolve request');
    }

    public function testAnItemThatALREADYCarriesItsTypeIsNotAsked(): void
    {
        // phpantom_lsp's answer. Asking anyway would be one request per row per
        // popup for something already on screen.
        $completion = $this->completion();

        $completion->show([$this->item('name', 'string')]);

        $this->assertSame([], $this->asked);
    }

    public function testAnAnswerPutsTheTypeOnTheItem(): void
    {
        $completion = $this->completion();
        $completion->show([$this->item('greet')]);

        $this->answer[0]('pub greet(string $who): string');

        $this->assertSame('pub greet(string $who): string', $this->itemsOf($completion)[0]->detail);
    }

    public function testOnlyTheVISIBLERowsAreAskedAbout(): void
    {
        // A completion list is routinely hundreds of entries — every class in
        // the project. Resolving all of them to fill eight visible rows is a
        // request storm nobody sees the result of.
        $items = [];
        foreach (range(1, end: 40) as $n) {
            $items[] = $this->item('item'.$n);
        }
        $completion = $this->completion();

        $completion->show($items);

        $this->assertCount(PopupMetrics::COMPLETION_ROWS, $this->asked);
        $this->assertSame('item1', $this->asked[0]['label']);
        $this->assertSame('item'.PopupMetrics::COMPLETION_ROWS, $this->asked[PopupMetrics::COMPLETION_ROWS - 1]['label']);
    }

    public function testAnItemIsAskedAboutONLYONCE(): void
    {
        $completion = $this->completion();
        $completion->show([$this->item('greet')]);

        $completion->show([$this->item('greet')]);
        $this->answer[0]('a type');
        $this->answer[1]('another type');

        $this->assertCount(2, $this->asked, 'a NEW list is a new question; the same item within one list is not');
    }

    public function testAnAnswerForAnItemThatIsGONEWritesNothing(): void
    {
        // The reply can outlive the popup: a newer completion request replaces
        // the list while the resolve is still in flight, and writing a type
        // onto whatever now sits at that position would put one item's
        // signature beside another item's name.
        $completion = $this->completion();
        $completion->show([$this->item('greet')]);
        $completion->show([$this->item('other')]);

        $this->answer[0]('pub greet(string $who): string');

        $this->assertSame('', $this->itemsOf($completion)[0]->detail, 'the item it was asked for is not in this list');
        $this->assertSame('other', $this->itemsOf($completion)[0]->label);
    }

    public function testMovingPastTheWINDOWAsksAboutTheRowThatScrolledIn(): void
    {
        // The window is what the whole "only what is on screen" rule is for,
        // and it only ever moves when the selection does. Every test above
        // leaves the selection at 0, where the window arithmetic cannot be
        // wrong.
        $items = [];
        foreach (range(1, end: 40) as $n) {
            $items[] = $this->item('item'.$n);
        }
        $completion = $this->completion();
        $completion->show($items);
        $this->asked = [];

        $completion->moveSelection(PopupMetrics::COMPLETION_ROWS); // one row past the first screen

        $this->assertSame(
            ['item'.(PopupMetrics::COMPLETION_ROWS + 1)],
            array_column($this->asked, 'label'),
            'exactly the row that scrolled in, and none of the ones already asked about',
        );
    }

    public function testAMIXEDListAsksOnlyAboutTheRowsThatNeedIt(): void
    {
        // The real shape of a server's answer is not uniform: phpantom_lsp
        // fills `detail` for some kinds and not others, and a locally-built
        // item (a live template) has no server item to resolve at all. A
        // fixture where every row is the same cannot tell "skip this one" from
        // "stop here".
        $completion = $this->completion();

        $completion->show([
            $this->item('typed', 'string'),
            $this->item('untyped'),
            new CompletionItem(label: 'template', insertText: 'template', kind: 'snippet', detail: ''),
            $this->item('alsoUntyped'),
        ]);

        $this->assertSame(['untyped', 'alsoUntyped'], array_column($this->asked, 'label'), 'the local item has no raw server item to ask about');
    }

    public function testAnAnswerForAnItemFURTHERDownTheListLandsOnTheRightRow(): void
    {
        $completion = $this->completion();
        $completion->show([$this->item('first'), $this->item('second')]);

        $this->answer[1]('the second type');

        $items = $this->itemsOf($completion);
        $this->assertSame('', $items[0]->detail, 'the row before it is untouched');
        $this->assertSame('the second type', $items[1]->detail);
    }

    public function testATypeArrivingASKSFORAReDraw(): void
    {
        // The answer comes back on the event loop, long after the frame that
        // showed the popup. Without a redraw the column stays empty until
        // something else happens to repaint.
        $painted = 0;
        $noop = static function (): void {};
        $completion = new EditorCompletion(
            new Document(''),
            static function () use (&$painted): void {
                ++$painted;
            },
            $noop,
            static fn (): bool => true,
            $noop,
            $noop,
            function (array $item, \Closure $onDetail): void {
                $this->answer[] = $onDetail;
            },
        );
        $completion->show([$this->item('greet')]);
        $painted = 0;

        $this->answer[0]('pub greet(): void');

        $this->assertSame(1, $painted);
    }

    public function testARROWINGDownPastTheWindowAsksToo(): void
    {
        // The keyboard is how the selection actually moves; the wheel path
        // above is the other door to the same room, and each was wired
        // separately.
        $items = [];
        foreach (range(1, end: 40) as $n) {
            $items[] = $this->item('item'.$n);
        }
        $completion = $this->completion();
        $completion->show($items);
        $this->asked = [];
        $keys = new Keybindings(['cursor_down' => ['j'], 'cursor_up' => ['k']]);

        foreach (range(1, end: PopupMetrics::COMPLETION_ROWS) as $_) {
            $completion->handleInput('j', $keys);
        }

        $this->assertSame(['item'.(PopupMetrics::COMPLETION_ROWS + 1)], array_column($this->asked, 'label'));
    }

    public function testARROWINGBackUpAsksAboutTheRowThatScrollsInAtTheTop(): void
    {
        $items = [];
        foreach (range(1, end: 40) as $n) {
            $items[] = $this->item('item'.$n);
        }
        $completion = $this->completion();
        $completion->show($items);
        $keys = new Keybindings(['cursor_down' => ['j'], 'cursor_up' => ['k']]);

        // Up from the top wraps to the LAST row, which is a screen nobody has
        // asked about.
        $this->asked = [];
        $completion->handleInput('k', $keys);

        $this->assertSame('item40', end($this->asked)['label'], 'the wrap lands on the last row and asks about it');
    }
}
