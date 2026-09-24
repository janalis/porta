<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\CompletionBox;
use Porta\Editor\CompletionItem;
use Porta\Editor\CompletionNote;
use Porta\Editor\Document;
use Porta\Editor\EditorCompletion;
use Porta\Widget\Render\PopupMetrics;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The popup's answers to Ctrl+Space when there is no list to show, its paging,
 * its docblock scroll, and a click on its frame.
 */
final class CompletionPopupStateTest extends TestCase
{
    private int $redraws = 0;

    /** @param \Closure(int, int): ?bool $request */
    private function completion(\Closure $request, Document $document = new Document("<?php\n\$a")): EditorCompletion
    {
        $noop = static function (): void {};
        $document->setCursor(1, 2);

        return new EditorCompletion(
            $document,
            function (): void {
                ++$this->redraws;
            },
            $noop,
            static fn (): bool => true,
            $request,
            $noop,
            $noop,
        );
    }

    private function keys(): Keybindings
    {
        return new Keybindings([
            'select_cancel' => [Key::ESCAPE],
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'accept_completion' => [Key::ENTER],
        ]);
    }

    /** @return list<CompletionItem> */
    private static function items(int $count, string $documentation = ''): array
    {
        return \array_map(static fn (int $i): CompletionItem => new CompletionItem("\$a{$i}", "\$a{$i}", 'var', '', documentation: $documentation), \range(0, end: $count - 1));
    }

    /** Ctrl+Space is answered at once — "Loading…" — and then by the list, or by saying there is none. */
    public function testAnAskedForPopupSaysLoadingThenAnswers(): void
    {
        $completion = $this->completion(static fn (): bool => true);

        $completion->requestExplicitly();
        $this->assertSame(CompletionNote::Loading, $completion->note());
        $this->assertGreaterThan(0, $this->redraws, 'drawn at once');

        $completion->show([]);
        $this->assertSame(CompletionNote::Empty, $completion->note(), 'an answer of nothing says so');

        $completion->requestExplicitly();
        $completion->show(self::items(2));
        $this->assertNull($completion->note(), 'a list replaces the note');
        $this->assertTrue($completion->isOpen());

        $completion->requestExplicitly();
        $this->assertNull($completion->note(), 'asked again over an open list: the list is the answer on screen');
    }

    public function testNobodyToAskSaysSo(): void
    {
        $this->assertSame(CompletionNote::NoServer, $this->completionAsking(false)->note());
        $this->assertSame(CompletionNote::NoServer, $this->completionAsking(null)->note(), 'no handler at all');
    }

    private function completionAsking(?bool $asked): EditorCompletion
    {
        $completion = $this->completion(static fn (): ?bool => $asked);
        $completion->requestExplicitly();

        return $completion;
    }

    /** The automatic trigger never shows a note: a note on ordinary typing would flicker in every buffer. */
    public function testTheAutomaticTriggerStaysSilent(): void
    {
        $completion = $this->completion(static fn (): bool => true);

        $completion->requestCompletion();
        $completion->show([]);

        $this->assertNull($completion->note());
    }

    /** Any key takes a note away and still does what it does; Esc is spent on it. */
    public function testAKeyDismissesTheNote(): void
    {
        $completion = $this->completion(static fn (): bool => false);
        $completion->requestExplicitly();

        $this->assertFalse($completion->dismissNote('x', $this->keys()), 'the x still types');
        $this->assertNull($completion->note());
        $this->assertFalse($completion->dismissNote('x', $this->keys()), 'nothing to dismiss');

        $completion->requestExplicitly();
        $this->assertTrue($completion->dismissNote("\x1b", $this->keys()), 'Esc is consumed');
        $completion->requestExplicitly();
        $completion->close();
        $this->assertNull($completion->note(), 'closing takes it too');
    }

    /** PgUp/PgDn move a popup's worth of rows and stop at the ends. */
    public function testPagingMovesAPopupfulAndStopsAtTheEnds(): void
    {
        $completion = $this->completion(static fn (): bool => true);
        $completion->show(self::items(30));

        $completion->handleInput("\x1b[6~", $this->keys());
        $this->assertSame(PopupMetrics::COMPLETION_ROWS, $completion->popupState()['selected'] ?? null);
        foreach (\range(1, end: 5) as $_) {
            $completion->handleInput("\x1b[6~", $this->keys());
        }
        $this->assertSame(29, $completion->popupState()['selected'] ?? null, 'the last, no wrap');
        $completion->handleInput("\x1b[5~", $this->keys());
        $this->assertSame(29 - PopupMetrics::COMPLETION_ROWS, $completion->popupState()['selected'] ?? null);
    }

    /** Ctrl+↓/↑ scroll the docblock within what it has, and a new selection starts it from the top. */
    public function testTheDocblockScrollsWithinItsLength(): void
    {
        $completion = $this->completion(static fn (): bool => true);
        $completion->show(self::items(3, 'doc'));
        $completion->setBox(new CompletionBox(1, 10, 1, 3, 0, doc: [0, 12, 20, 5], docMaxScroll: 2));

        $this->assertTrue($completion->handleInput("\x1b[1;5B", $this->keys()));
        $completion->handleInput("\x1b[1;5B", $this->keys());
        $completion->handleInput("\x1b[1;5B", $this->keys());
        $this->assertSame(2, $completion->popupState()['docScroll'] ?? null, 'held at the end');
        $completion->handleInput("\x1b[1;5A", $this->keys());
        $this->assertSame(1, $completion->popupState()['docScroll'] ?? null);

        $completion->handleInput("\x1b[B", $this->keys());
        $this->assertSame(0, $completion->popupState()['docScroll'] ?? null, 'the next item starts at its top');
        $completion->handleInput("\x1b[1;5B", $this->keys());
        $completion->handleInput("\x1b[A", $this->keys());
        $this->assertSame(0, $completion->popupState()['docScroll'] ?? null);
        $completion->handleInput("\x1b[1;5B", $this->keys());
        $completion->handleInput("\x1b[6~", $this->keys());
        $this->assertSame(0, $completion->popupState()['docScroll'] ?? null);
        $completion->handleInput("\x1b[1;5A", $this->keys());
        $this->assertSame(0, $completion->popupState()['docScroll'] ?? null, 'and never above it');
    }

    /** A click on the frame or the docblock is the popup's, not the code's; off the popup it is not. */
    public function testAClickOnTheFrameOrTheDocIsSwallowed(): void
    {
        $completion = $this->completion(static fn (): bool => true);
        $completion->show(self::items(3));
        $completion->setBox(new CompletionBox(5, 10, 3, 3, 0, doc: [2, 17, 20, 4]));

        $this->assertTrue($completion->handleClick(2, 8), 'the top border');
        $this->assertTrue($completion->handleClick(4, 4), 'the left rail');
        $this->assertTrue($completion->handleClick(4, 15), 'the right rail');
        $this->assertTrue($completion->handleClick(6, 8), 'the bottom border');
        $this->assertTrue($completion->handleClick(3, 20), 'the docblock');
        $this->assertTrue($completion->isOpen(), 'and none of them accepted anything');
        $this->assertFalse($completion->handleClick(10, 30), 'the code beneath');
        $this->assertFalse($completion->handleClick(2, 37), 'just past the docblock');
    }
}
