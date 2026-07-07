<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use Porta\I18n\Translator;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Widget\FindBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class FindBarWidgetTest extends TestCase
{
    private function type(FindBarWidget $bar, string $text): void
    {
        foreach (str_split($text) as $ch) {
            $bar->handleInput($ch);
        }
    }

    public function testClosedBarRendersNothingAndIgnoresInput(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $this->assertSame([], $bar->render(new RenderContext(80, 1)));
        $bar->handleInput('x'); // no crash while closed
        $this->assertFalse($bar->isOpen());
    }

    public function testIncrementalSmartCaseSearch(): void
    {
        $doc = new Document("Foo bar\nfoo BAR\nbaz\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $updates = 0;
        $bar->onUpdate(function () use (&$updates): void {
            ++$updates;
        });
        $bar->open($doc, withReplace: false);

        $this->type($bar, 'foo');
        $matches = $bar->getMatchesByLine();
        $this->assertCount(2, $matches, 'lowercase query is case-insensitive');
        $this->assertGreaterThan(0, $updates);

        // Exact case when query has uppercase
        $bar->open($doc, withReplace: false, initialQuery: 'BAR');
        $matches = $bar->getMatchesByLine();
        $this->assertArrayHasKey(1, $matches);
        $this->assertCount(1, $matches[1] ?? []);
        $this->assertArrayNotHasKey(0, $matches);
    }

    public function testJumpToNearestFromCursorAndCycling(): void
    {
        $doc = new Document("a match\nb match\nc match\n");
        $doc->setCursor(1, 0);
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'match');

        $this->assertSame(1, $doc->getCursorLine(), 'nearest match at/after cursor');

        $bar->handleInput("\r"); // next
        $this->assertSame(2, $doc->getCursorLine());
        $bar->handleInput("\r"); // wraps
        $this->assertSame(0, $doc->getCursorLine());
        $bar->handleInput("\x1b[13;2~"); // shift+f3 prev, wraps back
        $this->assertSame(2, $doc->getCursorLine());
    }

    public function testBackspaceNarrowsAndEmptyQueryClearsMatches(): void
    {
        $doc = new Document("alpha\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false);
        $this->type($bar, 'al');
        $this->assertNotSame([], $bar->getMatchesByLine());

        $bar->handleInput("\x7f");
        $bar->handleInput("\x7f");
        $this->assertSame([], $bar->getMatchesByLine());
        $bar->handleInput("\x7f"); // backspace on empty query: no-op
        $bar->handleInput("\r"); // find_next with no matches: no-op
        $this->assertSame([], $bar->getMatchesByLine());
    }

    public function testReplaceOneAndAll(): void
    {
        $doc = new Document("aaa bbb aaa\nccc aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: true, initialQuery: 'aaa');
        $bar->handleInput("\t"); // switch to replace field
        $this->type($bar, 'XX');

        $bar->handleInput("\x1br"); // alt+r replace current
        $this->assertSame('XX bbb aaa', $doc->getLine(0));

        $bar->handleInput("\x1ba"); // alt+a replace all remaining
        $this->assertSame("XX bbb XX\nccc XX\n", $doc->getText());
        $this->assertSame([], $bar->getMatchesByLine());
        $bar->handleInput("\x1ba"); // replace all with no matches: no-op
    }

    public function testSwitchFieldIgnoredWithoutReplaceMode(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'x');

        $bar->handleInput("\t");
        $this->type($bar, 'y'); // still edits the find field
        $this->assertSame([], $bar->getMatchesByLine(), '"xy" matches nothing');
    }

    public function testReplaceIgnoredWithoutReplaceMode(): void
    {
        $doc = new Document("aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'aaa');

        $bar->handleInput("\x1br");
        $this->assertSame('aaa', $doc->getLine(0));
    }

    public function testRefreshIfStaleRecomputesAfterExternalEdit(): void
    {
        $doc = new Document("match one\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'match');

        $doc->moveToDocumentEnd();
        $doc->insert("match two\n"); // document changed behind the bar's back

        $bar->handleInput("\r"); // find_next refreshes first
        $flat = array_merge(...array_values($bar->getMatchesByLine()));
        $this->assertCount(2, $flat);
    }

    public function testRenderShowsQueryCounterAndReplaceHints(): void
    {
        $doc = new Document("needle\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, withReplace: true, initialQuery: 'needle');

        $line = $bar->render(new RenderContext(120, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertSame(120, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('Find: needle', $plain);
        $this->assertStringContainsString('[1/1]', $plain);
        $this->assertStringContainsString('Replace:', $plain);
        $this->assertStringContainsString('⌥R replace', $plain);
    }

    public function testCloseNotifiesAndClearsMatches(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $closed = false;
        $bar->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $bar->open($doc, withReplace: false, initialQuery: 'x');

        $bar->handleInput("\x1b"); // escape
        $this->assertTrue($closed);
        $this->assertFalse($bar->isOpen());
        $this->assertSame([], $bar->getMatchesByLine());

        $bar->close(); // double close is a no-op
        $this->assertFalse($bar->isOpen());
    }

    public function testOnInputHookConsumes(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false);
        $bar->onInput(static fn (string $data): bool => 'Z' === $data);

        $bar->handleInput('Z'); // consumed
        $bar->handleInput('x');
        $flat = array_merge(...array_values($bar->getMatchesByLine()));
        $this->assertCount(1, $flat, 'query is "x", not "Zx"');
    }

    public function testJumpWrapsToFirstMatchWhenCursorIsPastAll(): void
    {
        $doc = new Document("match early\nnothing here\n");
        $doc->setCursor(1, 5); // after every match
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'match');

        $this->assertSame(0, $doc->getCursorLine(), 'wrapped to the first match');
    }

    public function testMatchCap(): void
    {
        $doc = new Document(str_repeat("x\n", 6000));
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, withReplace: false, initialQuery: 'x');

        $flat = array_merge(...array_values($bar->getMatchesByLine()));
        $this->assertCount(5000, $flat);
    }
}
