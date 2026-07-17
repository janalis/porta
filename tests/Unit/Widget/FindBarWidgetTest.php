<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\I18n\Translator;
use Porta\Widget\FindBarWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;

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
        $bar->onUpdate(static function () use (&$updates): void {
            ++$updates;
        });
        $bar->open($doc, mode: FindMode::Find);

        $this->type($bar, 'foo');
        $matches = $bar->getMatchesByLine();
        $this->assertCount(2, $matches, 'lowercase query is case-insensitive');
        $this->assertGreaterThan(0, $updates);

        // Exact case when query has uppercase
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'BAR');
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
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'match');

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
        $bar->open($doc, mode: FindMode::Find);
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
        $bar->open($doc, mode: FindMode::Replace, initialQuery: 'aaa');
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
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'x');

        $bar->handleInput("\t");
        $this->type($bar, 'y'); // still edits the find field
        $this->assertSame([], $bar->getMatchesByLine(), '"xy" matches nothing');
    }

    public function testReplaceIgnoredWithoutReplaceMode(): void
    {
        $doc = new Document("aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'aaa');

        $bar->handleInput("\x1br");
        $this->assertSame('aaa', $doc->getLine(0));
    }

    public function testRefreshIfStaleRecomputesAfterExternalEdit(): void
    {
        $doc = new Document("match one\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'match');

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
        $bar->open($doc, mode: FindMode::Replace, initialQuery: 'needle');

        $line = $bar->render(new RenderContext(120, 1))[0];
        $plain = AnsiUtils::stripAnsiCodes($line);

        $this->assertSame(120, AnsiUtils::visibleWidth($line));
        $this->assertStringContainsString('Find: needle', $plain);
        $this->assertStringContainsString('[1/1]', $plain);
        $this->assertStringContainsString('Replace:', $plain);
        $this->assertStringContainsString('Alt+R replace', $plain);
    }

    public function testCloseNotifiesAndClearsMatches(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $closed = false;
        $bar->onClose(static function () use (&$closed): void {
            $closed = true;
        });
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'x');

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
        $bar->open($doc, mode: FindMode::Find);
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
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'match');

        $this->assertSame(0, $doc->getCursorLine(), 'wrapped to the first match');
    }

    public function testMatchCap(): void
    {
        $doc = new Document(str_repeat("x\n", times: 6000));
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($doc, mode: FindMode::Find, initialQuery: 'x');

        $flat = array_merge(...array_values($bar->getMatchesByLine()));
        $this->assertCount(5000, $flat);
    }

    public function testLongQueryScrollsTheBarToKeepTheCaretVisible(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new Document("x\n"), mode: FindMode::Find);
        $this->type($bar, str_repeat('q', times: 100)); // overflow the 80-column bar

        $line = $bar->render(new RenderContext(80, 1))[0];
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $this->assertSame(1, substr_count($line, $marker), 'the caret scrolls into view');
        $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the bar stays the terminal width');
    }

    public function testRegexToggleMatchesPatterns(): void
    {
        $doc = new Document("aaa b\nab c\nxyz\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $this->type($bar, 'a+');

        $this->assertSame([], $bar->getMatchesByLine(), 'literal mode: no "a+" in the text');

        $bar->handleInput("\x1bx"); // alt+x → regex mode
        $matches = $bar->getMatchesByLine();
        $this->assertCount(2, $matches, 'regex matches on two lines');
        $this->assertSame([['start' => 0, 'end' => 3]], array_map(static fn (array $m): array => ['start' => $m['start'], 'end' => $m['end']], $matches[0]), 'greedy run of a\'s');

        $bar->handleInput("\x1bx"); // back to literal
        $this->assertSame([], $bar->getMatchesByLine());
    }

    public function testWholeWordToggleNarrowsMatches(): void
    {
        $doc = new Document("foo foobar\nfoo\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $this->type($bar, 'foo');
        $this->assertCount(3, array_merge(...array_values($bar->getMatchesByLine())), 'substring mode hits foobar too');

        $bar->handleInput("\x1bw"); // alt+w → whole word
        $this->assertCount(2, array_merge(...array_values($bar->getMatchesByLine())), 'foobar no longer matches');

        $screen = AnsiUtils::stripAnsiCodes(implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringContainsString('[\b]', $screen, 'the whole-word tag renders');
        $this->assertStringContainsString('[.*]', $screen, 'the regex tag renders (off)');
    }

    public function testInvalidRegexShowsAnInlineIndicatorAndMatchesNothing(): void
    {
        $doc = new Document("abc\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, 'a(');

        $this->assertSame([], $bar->getMatchesByLine(), 'a half-typed regex matches nothing');
        $screen = AnsiUtils::stripAnsiCodes(implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringContainsString('bad regex', $screen);

        $this->type($bar, ')'); // completing it recovers
        $this->assertCount(1, array_merge(...array_values($bar->getMatchesByLine())));
    }

    public function testZeroWidthRegexMatchesAreSkipped(): void
    {
        $doc = new Document("abc\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'z*');

        $this->assertSame([], $bar->getMatchesByLine(), 'zero-width hits highlight nothing (and never loop)');
    }

    public function testCatastrophicRegexIsSkippedPerLineWithoutMatches(): void
    {
        // A pattern valid against '' but that exhausts the PCRE backtrack limit
        // on a long line: preg_match_all returns false for that line, which the
        // per-line scan skips — so the final match set is empty.
        $doc = new Document(str_repeat('a', times: 30)."!\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, '(a+)+$');

        $this->assertSame([], $bar->getMatchesByLine(), 'the un-matchable long line is skipped, not matched');
    }

    public function testSmartCaseAppliesInRegexMode(): void
    {
        $doc = new Document("Foo\nfoo\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'fo+');
        $this->assertCount(2, $bar->getMatchesByLine(), 'lowercase regex is case-insensitive');

        $bar->open($doc, mode: FindMode::Find, initialQuery: 'Fo+');
        $bar->handleInput("\x1bx"); // toggles OFF (state survived) — toggle back on
        $bar->handleInput("\x1bx");
        $matches = $bar->getMatchesByLine();
        $this->assertArrayHasKey(0, $matches);
        $this->assertArrayNotHasKey(1, $matches, 'uppercase regex is exact');
    }

    public function testRegexModeRespectsTheMatchCap(): void
    {
        // 5001 single-char matches across two lines exceed MAX_MATCHES (5000).
        $doc = new Document(str_repeat('a', times: 4000)."\n".str_repeat('a', times: 1001)."\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'a');

        $this->assertSame(5000, \count(array_merge(...array_values($bar->getMatchesByLine()))), 'the scan stops at the cap');
    }

    public function testRegexReplaceExpandsBackreferences(): void
    {
        $doc = new Document("name: alpha\nname: beta\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Replace);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, 'name: (\w+)');
        $bar->handleInput("\t"); // → replace field
        $this->type($bar, '$1!');

        $bar->handleInput("\x1br"); // replace current
        $this->assertSame("alpha!\nname: beta\n", $doc->getText(), '$1 expanded to the captured group');

        $bar->handleInput("\x1ba"); // replace all (the remaining match)
        $this->assertSame("alpha!\nbeta!\n", $doc->getText());
    }

    public function testLiteralModeReplacementKeepsDollarSigns(): void
    {
        $doc = new Document("value\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open($doc, mode: FindMode::Replace);
        $this->type($bar, 'value');
        $bar->handleInput("\t");
        $this->type($bar, '$1');

        $bar->handleInput("\x1br");
        $this->assertSame("\$1\n", $doc->getText(), 'literal mode inserts $1 verbatim');
    }
}
