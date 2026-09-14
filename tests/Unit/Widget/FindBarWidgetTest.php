<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\Event\FindQueryUpdated;
use Porta\History\SearchHistoryStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeFindTarget;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FindBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;

final class FindBarWidgetTest extends TestCase
{
    private function type(FindBarWidget $bar, string $text): void
    {
        foreach (\str_split($text) as $ch) {
            $bar->handleInput($ch);
        }
    }

    /**
     * The query as the BAR DRAWS IT.
     *
     * Not a public accessor added for the test — `Find: …` is what the user
     * reads, and asserting it keeps the recall's whole wire (field, repaint,
     * render) in the assertion rather than only the field.
     */
    private function shownQuery(FindBarWidget $bar): string
    {
        $plain = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        // Up to the `[n/m]` counter, not `\S+`: an EMPTY query leaves the field
        // blank and the counter is the next non-space thing on the row, so a
        // greedy match reports `[0/0]` as the query and every assertion about
        // clearing the field passes against it.
        $m = [];
        $this->assertSame(1, \preg_match('/Find:([^\[]*)/', $plain, $m), 'the bar draws a Find field');

        return \trim($m[1]);
    }

    /**
     * `Alt+↑`/`Alt+↓` recall past queries — from the PROJECT search's history,
     * shared rather than a second store.
     *
     * The in-file bar had no history at all while find-in-files persisted one
     * to `.porta/search-history.json` and recalled it with the same chords.
     * Sharing means what you just found in a file is one keystroke away when
     * you next want it across the project.
     */
    public function testTheQueryHistoryIsRecalledAndIsTheProjectSearchs(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);
        $history->add('earlier');

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("nothing here\n")), mode: FindMode::Find);

        $bar->handleInput("\x1b[1;3A"); // alt+up
        $this->assertSame('earlier', $this->shownQuery($bar), 'a query the PROJECT search recorded');

        $bar->handleInput("\x1b[1;3B"); // alt+down, back past the newest
        $this->assertSame('', $this->shownQuery($bar), 'and back to an empty field, not stuck on the newest');
    }

    /**
     * The cursor walks BOTH ways and keeps its place.
     *
     * Up-up-down-up must land back on the older entry. A recall that reset the
     * cursor on its way — which is what dropping the handler's `return` does,
     * by falling through to the plain-typing path that clears it — passes every
     * single-step assertion and then starts the walk over on the next press.
     */
    public function testTheHistoryCursorSurvivesAStepBackTheOtherWay(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);
        $history->add('older');
        $history->add('newer');

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);

        $bar->handleInput("\x1b[1;3A");
        $bar->handleInput("\x1b[1;3A");
        $this->assertSame('older', $this->shownQuery($bar));

        $bar->handleInput("\x1b[1;3B"); // back towards the newest
        $this->assertSame('newer', $this->shownQuery($bar));

        $bar->handleInput("\x1b[1;3A"); // and onwards again, from where it was
        $this->assertSame('older', $this->shownQuery($bar), 'the walk continued rather than restarting');
    }

    /**
     * Recall targets the QUERY field only. Dropping a past search into the
     * REPLACEMENT is never what the chord means — and it is the field that
     * would silently rewrite the file on the next Alt+A.
     */
    public function testRecallDoesNothingWhileTheReplaceFieldIsActive(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);
        $history->add('earlier');

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Replace);
        $bar->handleInput("\t"); // to the replacement field

        $bar->handleInput("\x1b[1;3A");

        $plain = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringNotContainsString('earlier', $plain, 'neither field took it');
    }

    /**
     * A recalled query is SEARCHED FOR, not merely shown.
     *
     * The field and the render would look right either way; what tells them
     * apart is the match count, and a recall that skipped the re-run would
     * leave the bar reporting the previous query's hits under the new text.
     */
    public function testARecalledQueryReRunsTheSearch(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);
        $history->add('beta');

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("alpha\nbeta\nbeta\n")), mode: FindMode::Find);
        $this->type($bar, 'alpha');
        $this->assertCount(1, $bar->getMatchesByLine(), 'one line holds `alpha`');

        $bar->handleInput("\x1b[1;3A");

        $this->assertSame('beta', $this->shownQuery($bar));
        $this->assertCount(2, $bar->getMatchesByLine(), 'and two hold `beta`');
    }

    /**
     * Enter records the query, and typing does not.
     *
     * Recording as you type would put every PREFIX of every query in the list
     * — `e`, `ea`, `ear` — and push the one you meant twenty entries down.
     */
    public function testOnlyASubmittedQueryJoinsTheHistory(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find);

        $this->type($bar, 'foo');
        $this->assertSame([], $history->all(), 'typing records nothing');

        $bar->handleInput("\r");
        $this->assertSame(['foo'], $history->all());
    }

    /**
     * Typing leaves history-browsing, so the next `Alt+↑` starts from the top
     * rather than from wherever the cursor was left three searches ago.
     */
    public function testTypingResetsTheHistoryCursor(): void
    {
        $project = new TempProject();
        $history = new SearchHistoryStore($project->dir);
        $history->add('older');
        $history->add('newer');

        $bar = new FindBarWidget(new Translator('en'), history: $history);
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);

        $bar->handleInput("\x1b[1;3A");
        $bar->handleInput("\x1b[1;3A");
        $this->assertSame('older', $this->shownQuery($bar), 'two steps back');

        $this->type($bar, 'z');
        $bar->handleInput("\x1b[1;3A");
        $this->assertSame('newer', $this->shownQuery($bar), 'and the walk starts over');
    }

    /** With no history wired at all the chords are inert, not fatal. */
    public function testABarWithNoHistoryStoreIgnoresTheRecallChords(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);

        $bar->handleInput("\x1b[1;3A");

        $this->assertSame('', $this->shownQuery($bar));
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
        $events = new EventDispatcher();
        $doc = new Document("Foo bar\nfoo BAR\nbaz\n");
        $bar = new FindBarWidget(new Translator('en'), events: $events);
        $bar->setFocused(true);
        $updates = 0;
        $events->addListener(FindQueryUpdated::class, static function () use (&$updates): void {
            ++$updates;
        });
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);

        $this->type($bar, 'foo');
        $matches = $bar->getMatchesByLine();
        $this->assertCount(2, $matches, 'lowercase query is case-insensitive');
        $this->assertGreaterThan(0, $updates);

        // Exact case when query has uppercase
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'BAR');
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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'aaa');
        $bar->handleInput("\t"); // switch to replace field
        $this->type($bar, 'XX');

        $bar->handleInput("\x1br"); // alt+r replace current
        $this->assertSame('XX bbb aaa', $doc->getLine(0));

        $bar->handleInput("\x1ba"); // alt+a replace all remaining
        $this->assertSame("XX bbb XX\nccc XX\n", $doc->getText());
        $this->assertSame([], $bar->getMatchesByLine());
        $bar->handleInput("\x1ba"); // replace all with no matches: no-op
    }

    public function testReplaceBailsOnAReadOnlyTarget(): void
    {
        // A docs-style target returns no editable document; replace must no-op.
        $doc = new Document("aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc, readOnly: true), mode: FindMode::Replace, initialQuery: 'aaa');
        $bar->handleInput("\t");
        $this->type($bar, 'XX');

        $bar->handleInput("\x1br"); // replace current: bails, no document
        $this->assertSame('aaa', $doc->getLine(0), 'a read-only target is never edited');
        $bar->handleInput("\x1ba"); // replace all: also bails
        $this->assertSame('aaa', $doc->getLine(0));
    }

    public function testMatchesMirrorToTheTargetAndClearOnClose(): void
    {
        $doc = new Document("foo foo\n");
        $target = new FakeFindTarget($doc);
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open($target, mode: FindMode::Find);
        $this->type($bar, 'foo');

        $this->assertCount(2, $target->lastMatches[0] ?? [], 'both matches mirror to the target for highlighting');
        $bar->close();
        $this->assertSame([], $target->lastMatches, 'closing clears the target highlight');
    }

    public function testSwitchFieldIgnoredWithoutReplaceMode(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'x');

        $bar->handleInput("\t");
        $this->type($bar, 'y'); // still edits the find field
        $this->assertSame([], $bar->getMatchesByLine(), '"xy" matches nothing');
    }

    public function testReplaceIgnoredWithoutReplaceMode(): void
    {
        $doc = new Document("aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'aaa');

        $bar->handleInput("\x1br");
        $this->assertSame('aaa', $doc->getLine(0));
    }

    public function testRefreshIfStaleRecomputesAfterExternalEdit(): void
    {
        $doc = new Document("match one\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $doc->moveToDocumentEnd();
        $doc->insert("match two\n"); // document changed behind the bar's back

        $bar->handleInput("\r"); // find_next refreshes first
        $flat = \array_merge(...\array_values($bar->getMatchesByLine()));
        $this->assertCount(2, $flat);
    }

    public function testRenderShowsQueryCounterAndReplaceHints(): void
    {
        $doc = new Document("needle\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'needle');

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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'x');

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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->onInput(static fn (string $data): bool => 'Z' === $data);

        $bar->handleInput('Z'); // consumed
        $bar->handleInput('x');
        $flat = \array_merge(...\array_values($bar->getMatchesByLine()));
        $this->assertCount(1, $flat, 'query is "x", not "Zx"');
    }

    public function testJumpWrapsToFirstMatchWhenCursorIsPastAll(): void
    {
        $doc = new Document("match early\nnothing here\n");
        $doc->setCursor(1, 5); // after every match
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $this->assertSame(0, $doc->getCursorLine(), 'wrapped to the first match');
    }

    public function testMatchCap(): void
    {
        $doc = new Document(\str_repeat("x\n", times: 6000));
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'x');

        $flat = \array_merge(...\array_values($bar->getMatchesByLine()));
        $this->assertCount(5000, $flat);
    }

    public function testLongQueryScrollsTheBarToKeepTheCaretVisible(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $this->type($bar, \str_repeat('q', times: 100)); // overflow the 80-column bar

        $line = $bar->render(new RenderContext(80, 1))[0];
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $this->assertSame(1, \substr_count($line, $marker), 'the caret scrolls into view');
        $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the bar stays the terminal width');
    }

    public function testRegexToggleMatchesPatterns(): void
    {
        $doc = new Document("aaa b\nab c\nxyz\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $this->type($bar, 'a+');

        $this->assertSame([], $bar->getMatchesByLine(), 'literal mode: no "a+" in the text');

        $bar->handleInput("\x1bx"); // alt+x → regex mode
        $matches = $bar->getMatchesByLine();
        $this->assertCount(2, $matches, 'regex matches on two lines');
        $this->assertSame([['start' => 0, 'end' => 3]], \array_map(static fn (array $m): array => ['start' => $m['start'], 'end' => $m['end']], $matches[0]), 'greedy run of a\'s');

        $bar->handleInput("\x1bx"); // back to literal
        $this->assertSame([], $bar->getMatchesByLine());
    }

    /**
     * A pattern that COMPILES but gives up mid-line says so.
     *
     * The validity check runs the pattern against an empty subject, which only
     * asks whether it compiles. `^(a|a?)+$` compiles fine and then exhausts
     * PCRE's backtrack limit on a line of twenty `a`s — in two milliseconds, on
     * an input this small. `preg_match_all()` answers `false`, and the line was
     * silently skipped: the bar reported 0/0 and the user had no way to tell
     * "there is nothing here" from "the engine gave up".
     *
     * Catastrophic backtracking is something people write by accident — this
     * exact shape is the textbook one — so the honest answer is to say the
     * pattern was too much, not to report an empty result as if it were one.
     */
    public function testAPatternThatExhaustsTheEngineSaysSoRatherThanReportingNothing(): void
    {
        $doc = new Document(\str_repeat('a', times: 20)."!\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, '^(a|a?)+$');

        $this->assertSame([], $bar->getMatchesByLine(), 'nothing matched, which is true as far as it goes');
        $screen = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringContainsString('too complex', $screen, 'and the bar says why it is empty');

        // And it is not sticky: a state set on a bad pattern that outlives it
        // is the same lie in the other direction.
        foreach (\range(1, end: 9) as $_) {
            $bar->handleInput("\x7f"); // backspace out the whole pattern
        }
        $this->type($bar, 'a+');

        $recovered = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringNotContainsString('too complex', $recovered, 'a workable pattern clears it');
        $this->assertNotSame([], $bar->getMatchesByLine(), 'and matches again');
    }

    public function testWholeWordToggleNarrowsMatches(): void
    {
        $doc = new Document("foo foobar\nfoo\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $this->type($bar, 'foo');
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())), 'substring mode hits foobar too');

        $bar->handleInput("\x1bw"); // alt+w → whole word
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'foobar no longer matches');

        // Bracketed = enabled, so the tags read without colour (the toggle
        // convention the find modal and the checkboxes already follow).
        $screen = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringContainsString('[\b]', $screen, 'the enabled whole-word tag is bracketed');
        $this->assertStringNotContainsString('[.*]', $screen, 'the disabled regex tag is not');
        $this->assertStringContainsString(' .* ', $screen, 'but it still occupies its zone');
    }

    public function testInvalidRegexShowsAnInlineIndicatorAndMatchesNothing(): void
    {
        $doc = new Document("abc\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, 'a(');

        $this->assertSame([], $bar->getMatchesByLine(), 'a half-typed regex matches nothing');
        $screen = AnsiUtils::stripAnsiCodes(\implode('', $bar->render(new RenderContext(120, 1))));
        $this->assertStringContainsString('bad regex', $screen);

        $this->type($bar, ')'); // completing it recovers
        $this->assertCount(1, \array_merge(...\array_values($bar->getMatchesByLine())));
    }

    public function testZeroWidthRegexMatchesAreSkipped(): void
    {
        $doc = new Document("abc\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'z*');

        $this->assertSame([], $bar->getMatchesByLine(), 'zero-width hits highlight nothing (and never loop)');
    }

    public function testCatastrophicRegexIsSkippedPerLineWithoutMatches(): void
    {
        // A pattern valid against '' but that exhausts the PCRE backtrack limit
        // on a long line: preg_match_all returns false for that line, which the
        // per-line scan skips — so the final match set is empty.
        $doc = new Document(\str_repeat('a', times: 30)."!\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, '(a+)+$');

        $this->assertSame([], $bar->getMatchesByLine(), 'the un-matchable long line is skipped, not matched');
    }

    public function testSmartCaseAppliesInRegexMode(): void
    {
        $doc = new Document("Foo\nfoo\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'fo+');
        $this->assertCount(2, $bar->getMatchesByLine(), 'lowercase regex is case-insensitive');

        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'Fo+');
        $bar->handleInput("\x1bx"); // toggles OFF (state survived) — toggle back on
        $bar->handleInput("\x1bx");
        $matches = $bar->getMatchesByLine();
        $this->assertArrayHasKey(0, $matches);
        $this->assertArrayNotHasKey(1, $matches, 'uppercase regex is exact');
    }

    public function testRegexModeRespectsTheMatchCap(): void
    {
        // 5001 single-char matches across two lines exceed MAX_MATCHES (5000).
        $doc = new Document(\str_repeat('a', times: 4000)."\n".\str_repeat('a', times: 1001)."\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'a');

        $this->assertCount(5000, \array_merge(...\array_values($bar->getMatchesByLine())), 'the scan stops at the cap');
    }

    public function testRegexReplaceExpandsBackreferences(): void
    {
        $doc = new Document("name: alpha\nname: beta\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace);
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
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace);
        $this->type($bar, 'value');
        $bar->handleInput("\t");
        $this->type($bar, '$1');

        $bar->handleInput("\x1br");
        $this->assertSame("\$1\n", $doc->getText(), 'literal mode inserts $1 verbatim');
    }

    public function testClickingAnOptionTagTogglesItLikeItsKey(): void
    {
        $doc = new Document("foo foobar foo\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $this->type($bar, 'foo');
        $bar->render(new RenderContext(80, 1)); // the zones come from the last render
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())));

        // The tags are right-aligned: at 80 columns [.*] spans 72-75, [\b] 76-79.
        $bar->handleClick(77);
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'the click re-ran the search, exactly as Alt+W does');

        $bar->handleClick(10); // the query field is not a tag
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'a click off the tags is inert');

        $bar->handleClick(77);
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())), 'and it toggles back off');

        // The other tag, on its own zone: regex mode makes the query a pattern.
        $bar->handleClick(73);
        $this->type($bar, '+');
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())), 'foo+ is a regex now, not a literal');
    }

    public function testHoverTintsTheTagUnderThePointer(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 1));

        $this->assertTrue($bar->hoverAt(73), 'the pointer landed on the regex tag');
        $this->assertFalse($bar->hoverAt(74), 'still the same tag → no change');
        $this->assertTrue($bar->hoverAt(77), 'moved onto the whole-word tag');
        $this->assertTrue($bar->hoverAt(10), 'the query field is not a tag: the tint clears');
        $this->assertFalse($bar->hoverAt(10), 'still no tag → no change');
        $this->assertTrue($bar->hoverAt(73), 'back onto a tag');
        $this->assertTrue($bar->clearHover(), 'the pointer left the bar');
        $this->assertFalse($bar->clearHover(), 'nothing left to clear');

        $bar->hoverAt(73);
        $line = $bar->render(new RenderContext(80, 1))[0];
        $this->assertStringContainsString("\x1b[48;5;236m", $line, 'the hovered tag tints');
        $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the tint does not change the bar width');
    }

    public function testClosedBarIgnoresInputEvenAfterHavingBeenOpened(): void
    {
        // Once open() has run, $target stays non-null forever (close() never
        // clears it) — handleInput's closed-guard must key off $open alone, not
        // fall through just because a stale target is still sitting there. A
        // fall-through would silently corrupt the query field even though
        // render() (its own, independent !open guard) still shows nothing.
        $doc = new Document("match here\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');
        $bar->close();

        $bar->handleInput('x'); // must no-op: the bar is closed

        // Query is session-lifetime: reopening without a fresh initialQuery
        // keeps whatever the field already held.
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $this->assertNotSame([], $bar->getMatchesByLine(), 'the query is still "match", not corrupted into "matchx"');
    }

    public function testCurrentMatchFlagOnlyMarksTheActualCurrentIndex(): void
    {
        $doc = new Document("match one\nmatch two\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');
        $bar->handleInput("\r"); // find_next → currentIndex becomes 1

        $matches = $bar->getMatchesByLine();
        $this->assertFalse($matches[0][0]['current'], 'match 0 is not the current one');
        $this->assertTrue($matches[1][0]['current'], 'match 1 is');
    }

    public function testSwitchFieldTogglesBothWaysAndHidesTheCaretForNeitherField(): void
    {
        $doc = new Document("x\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'x');
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $line = $bar->render(new RenderContext(120, 1))[0];
        $this->assertSame(1, \substr_count($line, $marker), 'find field shows the caret');

        $bar->handleInput("\t"); // → replace field
        $line = $bar->render(new RenderContext(120, 1))[0];
        $this->assertSame(1, \substr_count($line, $marker), 'replace field shows the caret');

        $bar->handleInput("\t"); // → back to find field
        $line = $bar->render(new RenderContext(120, 1))[0];
        $this->assertSame(1, \substr_count($line, $marker), 'back on the find field');
    }

    public function testDeleteCharBackwardDoesNotFallThroughToInsertTheDeletedByte(): void
    {
        $doc = new Document("bar\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $this->type($bar, 'ba');

        $bar->handleInput("\x7f"); // backspace: query becomes "b" — must not also insert the \x7f byte

        $flat = \array_merge(...\array_values($bar->getMatchesByLine()));
        $this->assertCount(1, $flat, '"b" matches once');
        $this->assertSame(1, $flat[0]['end'] - $flat[0]['start'], 'the query is "b" (1 char), not "b\x7f" or longer');
    }

    public function testTagZoneColumnsAreExactlyGlyphPlusTwoWide(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 1));

        // [.*] is 2 glyph cols + 2 padding = 4 wide; same for [\b]. Right-aligned
        // into an 8-wide strip: regex spans 72-75, word spans 76-79.
        $this->assertFalse($bar->hoverAt(71), 'just before the regex zone: no tag');
        $this->assertTrue($bar->hoverAt(72), 'the regex zone starts at 72');
        $this->assertFalse($bar->hoverAt(75), 'still the regex zone (72-75): no change');
        $this->assertTrue($bar->hoverAt(76), 'the word zone starts right after, at 76');
        $this->assertFalse($bar->hoverAt(79), 'still the word zone (76-79): no change');
        $bar->clearHover();
        $this->assertFalse($bar->hoverAt(80), 'past the last column: no tag, no change from already-cleared');
    }

    public function testFindNextAfterAStaleRefreshJumpsToTheFirstMatchNotTheSecond(): void
    {
        $doc = new Document("match one\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $doc->moveToDocumentEnd();
        $doc->insert("match two\n"); // stale: recompute() resets currentIndex before find_next re-jumps

        $bar->handleInput("\r"); // find_next: refreshIfStale() then gotoMatch(currentIndex + 1)
        $this->assertSame(0, $doc->getCursorLine(), 'lands on the first match, not the second');
    }

    public function testJumpToNearestLandsExactlyOnAMatchStartingAtTheCursor(): void
    {
        $doc = new Document("xxx match\n");
        $doc->setCursor(0, 4); // exactly at the match's start column
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $this->assertSame(4, $doc->getCursorCol(), 'a match starting exactly at the cursor counts as "at/after", not skipped');
    }

    public function testJumpToNearestSkipsAnEarlierMatchOnTheSameLineAsTheCursor(): void
    {
        // Two matches on line 0, cursor sitting strictly between them: "at or
        // after the cursor" must only accept the second one, on column, not
        // "same line as the cursor" alone (which would wrongly grab the first).
        $doc = new Document("match aaaa match\n");
        $doc->setCursor(0, 6);
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $this->assertSame(11, $doc->getCursorCol(), 'jumps to the match after the cursor, not the one before it');
    }

    public function testReplaceCurrentRepositionsTheCursorRegardlessOfWhereItCurrentlyIs(): void
    {
        $doc = new Document("aaa bbb\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'bbb');
        $doc->setCursor(0, 0); // move away from the match after the jump

        $bar->handleInput("\t");
        $this->type($bar, 'X');
        $bar->handleInput("\x1br"); // replace current must still target the match, not the stray cursor

        $this->assertSame('aaa X', $doc->getLine(0));
    }

    public function testReplaceAllOnTwoMatchesInTheSameLineAppliesBottomUp(): void
    {
        $doc = new Document("a a\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'a');
        $bar->handleInput("\t");
        $this->type($bar, 'XX');

        $bar->handleInput("\x1ba"); // replace all

        $this->assertSame("XX XX\n", $doc->getText(), 'both are replaced correctly only if the rightmost goes first');
    }

    public function testTypingInTheReplaceFieldInvalidatesTheRenderCache(): void
    {
        // Typing in the replacement field never calls recompute() (only the
        // query field does) — editActiveField's own trailing invalidate() is
        // the only thing that bumps the render revision for that path.
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Replace);
        $bar->handleInput("\t"); // → replace field

        $before = $bar->getRenderRevision();
        $bar->handleInput('y');
        $this->assertGreaterThan($before, $bar->getRenderRevision());
    }

    /**
     * A bar too narrow for the tag strip records no tag zones at all.
     *
     * This used to assert the opposite — that the first zone clamped to column
     * 0 — which described a real `max(0, …)` in the widget and a tag strip
     * drawn from column 0 to 7 inside a three-column pane. The zone was honest
     * about where the tag was; the tag was five columns outside the pane, and
     * drawing it threw `RenderException` out of the render loop. The strip is
     * dropped at that width now, so there is nothing left to point at.
     */
    public function testABarTooNarrowForTheTagsOffersNoTagZones(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(3, 1)); // narrower than the 8-column tag strip

        foreach ([-1, 0, 1, 2] as $column) {
            $this->assertFalse($bar->hoverAt($column), 'no tag is on screen at column '.$column);
        }
    }

    /** And where they DO fit, the strip is right-aligned and its first zone starts there. */
    public function testTheTagZonesSitWhereTheStripIsDrawn(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(40, 1));

        $this->assertFalse($bar->hoverAt(31), 'one column before the strip');
        $this->assertTrue($bar->hoverAt(32), 'the strip is the last eight columns of forty');
        $this->assertTrue($bar->hoverAt(39), 'through to the final column');
    }

    public function testHoveringOneTagDoesNotTintTheOtherTag(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 1));

        $bar->hoverAt(73); // the regex tag's column
        $line = $bar->render(new RenderContext(80, 1))[0];
        // The hints text also mentions ".*" and "\b" literally, earlier in the
        // line; the tags strip is appended last, so its own occurrences are
        // the *last* ones in the string.
        $tint = \strrpos($line, needle: "\x1b[48;5;236m");
        $star = \strrpos($line, needle: '.*');
        $backslashB = \strrpos($line, needle: '\b');
        $this->assertNotFalse($tint);
        $this->assertLessThan($star, $tint, 'the tint wraps the hovered [.*] tag');
        $this->assertLessThan($backslashB, $star, 'and .* still comes before \b in the strip');
    }

    public function testEmptyLineIsSkippedNotAbortingTheRestOfTheDocument(): void
    {
        $doc = new Document("a\n\na\n"); // a match, then a blank line, then another match
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx"); // regex
        $this->type($bar, 'a');

        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'the blank line is skipped, not treated as end-of-document');
    }

    public function testAnUnmatchableLineDoesNotAbortTheRestOfTheDocument(): void
    {
        // A catastrophic-backtrack line makes preg_match_all return false for
        // just that line; the scan must move on to the next line, not stop.
        $doc = new Document(\str_repeat('a', times: 30)."!\naaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, '(a+)+$');

        $this->assertCount(1, \array_merge(...\array_values($bar->getMatchesByLine())), 'line 2 still matches after line 1 fails to scan');
    }

    public function testAZeroWidthHitDoesNotAbortTheRestOfTheLine(): void
    {
        $doc = new Document("abzc\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'z*');

        $this->assertCount(1, \array_merge(...\array_values($bar->getMatchesByLine())), 'the real "z" hit after the zero-width ones is still found');
    }

    public function testRegexMatchStartUsesTheByteOffsetNotTheMatchedText(): void
    {
        $doc = new Document("xx match\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'match');

        $flat = \array_merge(...\array_values($bar->getMatchesByLine()));
        $this->assertSame(3, $flat[0]['start'], 'the offset is 3, not (int) of the matched text');
    }

    public function testRegexModeMatchCapStopsImmediatelyAcrossLines(): void
    {
        // Cap hit mid-way through line 0's own hits: the scan must stop right
        // there, not merely stop the current line and move to the next one.
        $doc = new Document(\str_repeat('a', times: 5002)."\naaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $bar->handleInput("\x1bx");
        $this->type($bar, 'a');

        $this->assertCount(5000, \array_merge(...\array_values($bar->getMatchesByLine())), 'stops exactly at the cap, not 3 over from line 2');
    }

    public function testRegexReplacementExpandsOnlyTheMatchedSubstring(): void
    {
        $doc = new Document("prefix name: alpha suffix\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace);
        $bar->handleInput("\x1bx"); // regex mode
        $this->type($bar, 'name: (\w+)');
        $bar->handleInput("\t");
        $this->type($bar, '[$1]');

        $bar->handleInput("\x1br");
        $this->assertSame('prefix [alpha] suffix', $doc->getLine(0), 'only the matched span is replaced, the rest of the line is untouched');
    }

    /**
     * The reason the tags are right-aligned rather than sitting after the
     * counter: the rest of the bar scrolls to follow the caret, so a growing
     * query used to carry the tags' columns with it — and a click zone that
     * moves as you type points at whatever the query pushed under it.
     */
    public function testTheTagZonesDoNotMoveAsTheQueryGrows(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find);

        $zones = null;
        foreach (['', 'foo', \str_repeat('long-query-', times: 8)] as $query) {
            $this->type($bar, $query);
            $line = $bar->render(new RenderContext(80, 1))[0];
            $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the bar always fills its row');

            $hit = [];
            for ($col = 0; $col < 80; ++$col) {
                if ($bar->hoverAt($col)) {
                    $hit[] = $col;
                }
                $bar->clearHover();
            }
            $zones ??= $hit;
            $this->assertSame($zones, $hit, "the tag zones moved once the query was {$query}");
        }
        $this->assertNotSame([], $zones, 'the tags are hit-testable at all');
    }

    /**
     * Replace All is ONE undo step, not one per match.
     *
     * `Document::insert()` coalesces only with no selection and four characters
     * or fewer, and every insert in the loop replaces a selection — so a
     * forty-match replace pushed forty entries and undoing it took forty
     * Ctrl+Z. The project-wide replace has been a single undoable edit all
     * along, so the same verb behaved differently depending on which one you
     * reached for.
     */
    public function testReplaceAllUndoesInOneStep(): void
    {
        $doc = new Document("aaa bbb aaa\nccc aaa\n");
        $before = $doc->getText();
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'aaa');
        $bar->handleInput("\t");
        $this->type($bar, 'XX');

        $bar->handleInput("\x1ba"); // replace all three
        $this->assertSame("XX bbb XX\nccc XX\n", $doc->getText());

        $doc->undo();

        $this->assertSame($before, $doc->getText(), 'one undo restored every match at once');
    }

    /**
     * The compound has to be CLOSED, not merely opened. An unbalanced
     * `beginCompound()` still collapses the replace into one undo entry, so the
     * case above passes either way — what it leaves behind is a compound that
     * never ends, swallowing every edit the user makes next. Typing one
     * character after a Replace All and undoing must give back the replaced
     * text, not the text from before the replace.
     */
    public function testAnEditAfterReplaceAllIsItsOwnUndoStep(): void
    {
        $doc = new Document("aaa bbb aaa\nccc aaa\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Replace, initialQuery: 'aaa');
        $bar->handleInput("\t");
        $this->type($bar, 'XX');
        $bar->handleInput("\x1ba");
        $replaced = $doc->getText();

        $doc->setCursor(0, 0);
        $doc->insert('Z');
        $this->assertSame('Z'.$replaced, $doc->getText());

        $doc->undo();

        $this->assertSame($replaced, $doc->getText(), 'the typing undid on its own, without taking the replace with it');
    }
}
