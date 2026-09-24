<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\Event\FindQueryUpdated;
use Porta\History\SearchHistoryStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\CompactScreen;
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
     * Not a public accessor added for the test — the Find box is what the user
     * reads, and asserting it keeps the recall's whole wire (field, repaint,
     * render) in the assertion rather than only the field.
     */
    private function shownQuery(FindBarWidget $bar): string
    {
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $bar->render(new RenderContext(120, 3)));
        // Between the Find box's rails on its value row, not `\S+`: an EMPTY
        // query leaves the box blank, and a greedy match would report the
        // next thing on the row as the query — every assertion about clearing
        // the field would pass against it.
        $m = [];
        $this->assertMatchesRegularExpression('/^[┏╭╔] Find /u', $rows[0] ?? '', 'the bar draws a Find field');
        $this->assertSame(1, \preg_match('/^[┃│║]([^┃│║]*)[┃│║]/u', $rows[1], $m), 'with its value between the rails');

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
        $this->assertSame([], $history->queries, 'typing records nothing');

        $bar->handleInput("\r");
        $this->assertSame(['foo'], $history->queries);
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

        $this->assertSame(1, $doc->cursorLine, 'nearest match at/after cursor');

        $bar->handleInput("\r"); // next
        $this->assertSame(2, $doc->cursorLine);
        $bar->handleInput("\r"); // wraps
        $this->assertSame(0, $doc->cursorLine);
        $bar->handleInput("\x1b[13;2~"); // shift+f3 prev, wraps back
        $this->assertSame(2, $doc->cursorLine);
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

        $rows = $bar->render(new RenderContext(160, 3));
        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $rows);

        $this->assertCount(3, $rows, 'two boxed fields side by side, three rows tall');
        foreach ($rows as $row) {
            $this->assertSame(160, AnsiUtils::visibleWidth($row));
        }
        $this->assertStringStartsWith('┏ Find ━', $plain[0], 'the focused field is heavy-bordered, its label a legend');
        $this->assertStringContainsString('╭ Replace ─', $plain[0], 'the other field rests rounded');
        $this->assertStringStartsWith('┃ needle ', $plain[1]);
        $this->assertStringContainsString('━ [1/1] ━┛', $plain[2], 'the match position rides the bottom border of the Find box');
        $this->assertStringContainsString('│ .* │', $plain[1], 'the toggles are boxes beside the fields');
        $this->assertStringContainsString('Alt+R replace', $plain[0], 'the hints pack whole into the strip past the buttons');
        $this->assertStringContainsString('Esc', $plain[2], 'and carry on along the bottom row');
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

        $this->assertSame(0, $doc->cursorLine, 'wrapped to the first match');
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
        $this->type($bar, \str_repeat('q', times: 100)); // overflow the 32-column box

        $line = $bar->render(new RenderContext(80, 3))[1];
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $this->assertSame(1, \substr_count($line, $marker), 'the caret scrolls into view');
        $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the value row stays the terminal width');
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
        // convention the find modal and the checkboxes already follow) —
        // the one-row form, which has no box to say it with.
        $compact = new FindBarWidget(new Translator('en'), density: CompactScreen::density());
        $compact->open(new FakeFindTarget($doc), mode: FindMode::Find);
        $compact->handleInput("\x1bw");
        $screen = AnsiUtils::stripAnsiCodes(\implode('', $compact->render(new RenderContext(120, 1))));
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

        // The toggles follow the 32-column box and its gutter, six columns
        // each boxed: .* spans 33-38, \b 39-44.
        $bar->handleClick(1, 41);
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'the click re-ran the search, exactly as Alt+W does');

        $bar->handleClick(1, 70); // past the buttons: no zone at all
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'the empty tail is inert');
        $bar->handleClick(1, 10); // the query box is not a tag
        $this->assertCount(2, \array_merge(...\array_values($bar->getMatchesByLine())), 'a click off the tags is inert');

        $bar->handleClick(2, 41);
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())), 'and toggles back off from its bottom border: a box answers on every row');

        // The other tag, on its own zone: regex mode makes the query a pattern.
        $bar->handleClick(1, 34);
        $this->type($bar, '+');
        $this->assertCount(3, \array_merge(...\array_values($bar->getMatchesByLine())), 'foo+ is a regex now, not a literal');
    }

    /**
     * A click anywhere in a box — its border rows included, as a browser's
     * input takes a click on its edge — makes it the field typing edits, and
     * the heavy border follows. A click beside the boxes changes nothing.
     */
    public function testClickingAFieldBoxMakesItTheFieldTypingEdits(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Replace, initialQuery: 'x');
        $bar->render(new RenderContext(80, 3)); // Find 0-31, gutter, Replace 33-64

        $bar->handleClick(0, 40); // the Replace box's top border
        $this->type($bar, 'z');
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $bar->render(new RenderContext(80, 3)));
        $this->assertStringContainsString('┃ z ', $rows[1], 'the typing landed in Replace');
        $this->assertStringStartsWith('╭ Find', $rows[0], 'and Find gave up the heavy border');

        $bar->handleClick(2, 5); // the Find box's bottom border
        $this->type($bar, 'y');
        $this->assertSame('xy', $this->shownQuery($bar), 'back in Find');

        $bar->handleClick(0, 79); // beside the boxes, above the tags and buttons
        $this->type($bar, 'w');
        $this->assertSame('xyw', $this->shownQuery($bar), 'a click on no zone leaves the field as it was');
    }

    /**
     * A pane just wide enough for the box and its tags still says where you
     * are: the position used to trail the tags on the value row, so it was
     * the first thing a narrow pane cut — the tags stayed, the count went.
     */
    public function testANarrowBarStillShowsTheMatchPosition(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("foo foo\n")), mode: FindMode::Find, initialQuery: 'foo');

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $bar->render(new RenderContext(40, 3)));

        $this->assertStringContainsString('[1/2]', $rows[2]);
        $this->assertStringEndsWith(' .*  \\b  Aa  ↑  ↓  × ', $rows[1], 'beside the tags and buttons, which still fit');
    }

    public function testHoverTintsTheTagUnderThePointer(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 1));

        $this->assertTrue($bar->hoverAt(1, 34), 'the pointer landed on the regex tag');
        $this->assertFalse($bar->hoverAt(1, 35), 'still the same tag → no change');
        $this->assertTrue($bar->hoverAt(1, 40), 'moved onto the whole-word tag');
        $this->assertTrue($bar->hoverAt(1, 10), 'the query field is not a tag: the tint clears');
        $this->assertFalse($bar->hoverAt(1, 10), 'still no tag → no change');
        $this->assertTrue($bar->hoverAt(1, 34), 'back onto a tag');
        $this->assertTrue($bar->clearHover(), 'the pointer left the bar');
        $this->assertFalse($bar->clearHover(), 'nothing left to clear');

        $bar->hoverAt(1, 34);
        $line = $bar->render(new RenderContext(80, 3))[1];
        $this->assertStringContainsString("\x1b[4m", $line, 'the hovered toggle underlines its caption, as a button does');
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

        $line = \implode('', $bar->render(new RenderContext(120, 3)));
        $this->assertSame(1, \substr_count($line, $marker), 'find field shows the caret');

        $bar->handleInput("\t"); // → replace field
        $line = \implode('', $bar->render(new RenderContext(120, 3)));
        $this->assertSame(1, \substr_count($line, $marker), 'replace field shows the caret');

        $bar->handleInput("\t"); // → back to find field
        $line = \implode('', $bar->render(new RenderContext(120, 3)));
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

    public function testTheToggleZonesAreTheBoxesAndAnswerOnEveryRow(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 3));

        // A boxed toggle is its caption plus the rails and padding: six
        // columns for .*, \b and Aa; a boxed button five. They follow the
        // 32-column box and its gutter: regex 33-38, word 39-44, case 45-50,
        // then ↑ 51-55, ↓ 56-60 and × 61-65.
        $this->assertFalse($bar->hoverAt(1, 32), 'the gutter before the regex zone: no tag');
        $this->assertTrue($bar->hoverAt(1, 33), 'the regex zone starts at 33');
        $this->assertFalse($bar->hoverAt(1, 38), 'still the regex zone (33-38): no change');
        $this->assertTrue($bar->hoverAt(1, 39), 'the word zone starts right after, at 39');
        $this->assertFalse($bar->hoverAt(1, 44), 'still the word zone (39-44): no change');
        $this->assertTrue($bar->hoverAt(1, 45), 'the case zone, 45-50');
        $this->assertTrue($bar->hoverAt(1, 51), 'the ↑ button, 51-55');
        $this->assertTrue($bar->hoverAt(1, 61), 'the × button, 61-65');
        $bar->clearHover();
        $this->assertFalse($bar->hoverAt(1, 66), 'past the last button: nothing, no change from already-cleared');
        $this->assertTrue($bar->hoverAt(0, 34), 'the top border is the toggle too');
        $this->assertFalse($bar->hoverAt(2, 36), 'as is the bottom one: the same toggle, no change');
        $this->assertTrue($bar->hoverAt(3, 34), 'below the bar is off it: the hover clears');
    }

    public function testOnACompactScreenTheTagsAreOneRow(): void
    {
        $bar = new FindBarWidget(new Translator('en'), density: CompactScreen::density());
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Replace);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $bar->render(new RenderContext(100, 3)));

        $this->assertCount(1, $rows, 'Find and Replace side by side on the one row');
        $this->assertStringContainsString('Replace ', $rows[0]);
        $this->assertStringContainsString(' .*  \b  Aa  ↑  ↓  × ', $rows[0]);
        $this->assertTrue($bar->hoverAt(0, 67), 'the regex tag, after two 32-column fields and their gutters: 66-69');
        $this->assertTrue($bar->hoverAt(1, 67), 'below the one row is off it: the hover clears');
        $this->assertFalse($bar->hoverAt(1, 67), 'and stays clear');
    }

    public function testFindNextAfterAStaleRefreshJumpsToTheFirstMatchNotTheSecond(): void
    {
        $doc = new Document("match one\n");
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $doc->moveToDocumentEnd();
        $doc->insert("match two\n"); // stale: recompute() resets currentIndex before find_next re-jumps

        $bar->handleInput("\r"); // find_next: refreshIfStale() then gotoMatch(currentIndex + 1)
        $this->assertSame(0, $doc->cursorLine, 'lands on the first match, not the second');
    }

    public function testJumpToNearestLandsExactlyOnAMatchStartingAtTheCursor(): void
    {
        $doc = new Document("xxx match\n");
        $doc->setCursor(0, 4); // exactly at the match's start column
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget($doc), mode: FindMode::Find, initialQuery: 'match');

        $this->assertSame(4, $doc->cursorCol, 'a match starting exactly at the cursor counts as "at/after", not skipped');
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

        $this->assertSame(11, $doc->cursorCol, 'jumps to the match after the cursor, not the one before it');
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
            $this->assertFalse($bar->hoverAt(1, $column), 'no tag is on screen at column '.$column);
        }
    }

    /** And where they DO fit, the box shrinks to seat them and their first zone starts after it. */
    public function testTheTagZonesSitWhereTheStripIsDrawn(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(40, 1));

        $this->assertFalse($bar->hoverAt(1, 18), 'the gutter after the 18-column box');
        $this->assertTrue($bar->hoverAt(1, 19), 'the tags and buttons fill the last 21 columns of forty');
        $this->assertTrue($bar->hoverAt(1, 39), 'through to the final column');
    }

    public function testHoveringOneTagDoesNotTintTheOtherTag(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("x\n")), mode: FindMode::Find);
        $bar->render(new RenderContext(80, 1));

        $bar->hoverAt(1, 34); // the regex tag's column
        $line = $bar->render(new RenderContext(80, 3))[1];
        // The value row carries the captions; the hints (which mention ".*"
        // and "\b" too) sit on the rows above and below it.
        $tint = \strrpos($line, needle: "\x1b[4m");
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
     * The tags sit after a FIXED-width box, never after the typed text: the
     * old one-row bar scrolled to follow the caret, so a growing query carried
     * the tags' columns with it — and a click zone that moves as you type
     * points at whatever the query pushed under it.
     */
    public function testTheTagZonesDoNotMoveAsTheQueryGrows(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->setFocused(true);
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find);

        $zones = null;
        foreach (['', 'foo', \str_repeat('long-query-', times: 8)] as $query) {
            $this->type($bar, $query);
            foreach ($bar->render(new RenderContext(80, 3)) as $line) {
                $this->assertSame(80, AnsiUtils::visibleWidth($line), 'the bar always fills its rows');
            }

            $hit = [];
            for ($col = 0; $col < 80; ++$col) {
                if ($bar->hoverAt(1, $col)) {
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

    /** A query that cannot match draws the Find box double — bad pattern or no hit — and a match clears it. */
    public function testAFailingQueryDrawsTheFindBoxDouble(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find, initialQuery: 'zzz');
        $top = static fn (): string => AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(120, 3))[0]);

        $this->assertStringStartsWith('╔ Find', $top(), 'no hit');
        $this->assertStringContainsString('║ zzz', AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(30, 3))[0]), 'the one-row form of a narrow pane says it too');
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Replace, initialQuery: 'zzz');
        $this->assertStringContainsString('╭ Replace', $top(), 'the Replace box is not what failed');
        $bar->open(new FakeFindTarget(new Document("foo\n")), mode: FindMode::Find, initialQuery: 'zzz');
        foreach (\str_split('zzz') as $_) {
            $bar->handleInput("\x7f");
        }
        $bar->handleInput('f');
        $this->assertStringStartsNotWith('╔', $top(), 'a hit: the ordinary frame');
        $bar->handleInput("\x1bx"); // regex on
        $bar->handleInput('(');
        $this->assertStringStartsWith('╔ Find', $top(), 'a bad pattern');
    }

    /** Aa matches case exactly where smart case would not; Alt+C and a click both toggle it. */
    public function testMatchCaseOverridesSmartCase(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("foo Foo\n")), mode: FindMode::Find, initialQuery: 'foo');
        $this->assertCount(2, $this->allMatches($bar), 'lower case: smart case matches both');

        $bar->handleInput("\x1bc");
        $this->assertCount(1, $this->allMatches($bar), 'Aa on: only the exact case');
        $bar->render(new RenderContext(80, 3));
        $bar->handleClick(1, 47); // the Aa toggle
        $this->assertCount(2, $this->allMatches($bar), 'clicked off again');
    }

    /** ↑ and ↓ step through the matches as Shift+F3 and F3 do; × closes as Esc does. */
    public function testTheButtonsStepAndClose(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("a a a\n")), mode: FindMode::Find, initialQuery: 'a');
        $bar->render(new RenderContext(80, 3));
        $position = static fn (): string => AnsiUtils::stripAnsiCodes($bar->render(new RenderContext(80, 3))[2]);
        $this->assertStringContainsString('[1/3]', $position());

        $bar->handleClick(1, 58); // ↓
        $this->assertStringContainsString('[2/3]', $position());
        $bar->handleClick(1, 53); // ↑
        $bar->handleClick(0, 53); // its top border: a button is its whole box
        $this->assertStringContainsString('[3/3]', $position(), 'wrapping');
        $this->assertTrue($bar->hoverAt(1, 63));
        $this->assertStringContainsString("\e[4m", $bar->render(new RenderContext(80, 3))[1], 'the × under the pointer underlines');
        $bar->handleClick(1, 63); // ×
        $this->assertFalse($bar->isOpen());
    }

    /** The query's caret moves: a typo mid-word is fixed where it is. */
    public function testTheQueryCaretMoves(): void
    {
        $bar = new FindBarWidget(new Translator('en'));
        $bar->open(new FakeFindTarget(new Document("barbaz\n")), mode: FindMode::Find, initialQuery: 'bxz');
        $bar->handleInput("\x1b[D");
        $bar->handleInput("\x1b[D");
        $bar->handleInput("\x1b[3~"); // delete the x
        $bar->handleInput('a');

        $this->assertSame('baz', $this->shownQuery($bar));
        $this->assertCount(1, $this->allMatches($bar));
    }

    /** @return list<array{start: int, end: int, current: bool}> every match, lines flattened */
    private function allMatches(FindBarWidget $bar): array
    {
        return \array_merge(...\array_values($bar->getMatchesByLine()));
    }
}
