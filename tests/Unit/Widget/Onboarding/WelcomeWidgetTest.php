<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Onboarding\WelcomeStep;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\PagesThroughRows;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Porta\Widget\Onboarding\WelcomeWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The Welcome screen's step cards: layout, running, and the row map that turns
 * a pointer row back into a step.
 *
 * Layout at the default 60×12 content window (the modal frame's title bar is
 * NOT part of this widget): the three-row hero block (rows 0–2, present
 * because 12 ≥ HERO_MIN_ROWS), then per step a title row, its wrapped detail
 * rows and a spacer. With the short one-line details used here, step N owns
 * rows `3 + 3N` and `4 + 3N`.
 */
final class WelcomeWidgetTest extends TestCase
{
    use PagesThroughRows;
    use RendersWidget;

    private const int FIRST_STEP_ROW = 3;

    /**
     * The paging sweep left this widget alone on the argument that its page
     * size cannot matter: a handful of cards, `moveDown()` clamps to the count,
     * so Page Down is an End key and nothing can be stepped over. That is an
     * argued rule, and this is the test of it.
     */
    public function testPagingDownShowsEveryStepCard(): void
    {
        $labels = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        $widget = $this->widget(...\array_map($this->step(...), $labels));

        // Every height from "one card fits" upward: the page size only stops
        // mattering once the whole list is on screen at once.
        foreach ([4, 5, 6, 7, 9, 12, 20] as $rows) {
            $widget = $this->widget(...\array_map($this->step(...), $labels));
            self::assertPagingShowsEveryRow(
                fn (): string => $this->plain($widget->render(new RenderContext(60, $rows))),
                static fn () => $widget->handleInput("\x1b[6~"),
                $labels,
            );
        }
    }

    /**
     * The other half. A page that is too SMALL skips nothing, so it passes the
     * case above while doing no paging at all — Page Down becomes ↓.
     *
     * The geometry is the one this file's header sets out: a three-row hero
     * (only at HERO_MIN_ROWS = 10 and above) and three rows per card with the
     * one-line details used here. So the cards that FIT are
     * `floor((rows - hero) / 3)`, and one Page Down from the top lands on
     * exactly that index.
     */
    public function testPageDownMovesByTheCardsThatFit(): void
    {
        $labels = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
        // 11 rows is the boundary case on purpose: the hero leaves 8, and two
        // three-row cards fit in 8 while three do not.
        foreach ([6 => 2, 9 => 3, 11 => 2, 12 => 3, 15 => 4] as $rows => $expected) {
            $widget = $this->widget(...\array_map($this->step(...), $labels));
            $widget->render(new RenderContext(60, $rows));

            $widget->handleInput("\x1b[6~");

            $this->assertStringContainsString(
                $labels[$expected],
                $this->selectedStep($widget),
                "at {$rows} rows a page is {$expected} cards",
            );
        }
    }

    /**
     * The SECOND page has to be measured from where the list now sits, not from
     * the top — the count runs over the cards from the current scroll.
     */
    public function testTheSecondPageIsMeasuredFromTheNewScroll(): void
    {
        $labels = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf'];
        $widget = $this->widget(...\array_map($this->step(...), $labels));
        $widget->render(new RenderContext(60, 9)); // no hero: three cards fit

        $widget->handleInput("\x1b[6~");
        $widget->render(new RenderContext(60, 9));
        $widget->handleInput("\x1b[6~");

        $this->assertStringContainsString('golf', $this->selectedStep($widget), 'three cards, then three more');
    }

    /** The title of the selected card, found by the accent bar down its left edge. */
    private function selectedStep(WelcomeWidget $widget): string
    {
        foreach ($this->rows($widget->render(new RenderContext(60, 40))) as $row) {
            if (\str_contains($row, Glyphs::CARD_ACCENT)) {
                return \trim(\str_replace(Glyphs::CARD_ACCENT, replace: '', subject: $row));
            }
        }

        return '';
    }

    private function widget(WelcomeStep ...$steps): WelcomeWidget
    {
        $widget = new WelcomeWidget(new Translator('en'));
        $widget->setSteps(\array_values($steps));

        return $widget;
    }

    /** @param \Closure(): void|null $run */
    private function step(string $title, ?\Closure $run = null, string $detail = 'why it matters'): WelcomeStep
    {
        return $this->build($title, done: false, run: $run, detail: $detail);
    }

    /** @param \Closure(): void|null $run */
    private function doneStep(string $title, ?\Closure $run = null): WelcomeStep
    {
        return $this->build($title, done: true, run: $run);
    }

    /** @param \Closure(): void|null $run */
    private function linkStep(string $title, ?\Closure $run = null): WelcomeStep
    {
        return new WelcomeStep($title, 'where it leads', 'Open', null, $run ?? static function (): void {});
    }

    /** @param \Closure(): void|null $run */
    private function build(string $title, bool $done, ?\Closure $run, string $detail = 'why it matters'): WelcomeStep
    {
        return new WelcomeStep(
            $title,
            $detail,
            'Do it',
            static fn (): bool => $done,
            $run ?? static function (): void {},
        );
    }

    /** @return list<string> */
    private function rendered(WelcomeWidget $widget, int $rows = 12, int $columns = 60): array
    {
        return $this->rows($widget->render(new RenderContext($columns, $rows)));
    }

    public function testRendersAHeroThenACardPerStep(): void
    {
        $lines = $this->rendered($this->widget($this->step('Syntax highlighting'), $this->step('Language server')));

        $this->assertStringContainsString('Welcome to Porta', $lines[1], 'the hero greeting');
        $this->assertStringContainsString('Syntax highlighting', $lines[self::FIRST_STEP_ROW]);
        $this->assertStringContainsString('why it matters', $lines[self::FIRST_STEP_ROW + 1], 'the detail sits on its own line');
        $this->assertStringContainsString('Language server', $lines[self::FIRST_STEP_ROW + 3]);
    }

    /** The hero is centred by construction: equal margins up to the odd column. */
    public function testTheHeroIsCentred(): void
    {
        $lines = $this->rendered($this->widget($this->step('First')));

        // 'Welcome to Porta' is 16 columns; (60 - 16) / 2 = 22.
        $this->assertSame(22, \strpos($lines[1], needle: 'Welcome to Porta'));
    }

    /** Below HERO_MIN_ROWS the greeting cedes its rows to the steps. */
    public function testTheHeroIsDroppedOnAShortPane(): void
    {
        $lines = $this->rendered($this->widget($this->step('First')), rows: 8);

        $this->assertStringNotContainsString('Welcome to Porta', \implode("\n", $lines));
        $this->assertStringContainsString('First', $lines[0], 'the first card moves up to row 0');
    }

    /** The title is the modal frame's bar text now, not a rendered row. */
    public function testTheTitleCountsProgressAndHintsTheKeys(): void
    {
        $widget = $this->widget($this->doneStep('Finished'), $this->step('Pending'));
        $widget->render(new RenderContext(60, 12));

        $this->assertStringContainsString('1 of 2 done', $widget->title());
        $this->assertStringContainsString('run', $widget->title(), 'the keys are advertised');
    }

    /** A link step has no done state, so it must not count toward the progress. */
    public function testLinkStepsAreExcludedFromTheProgressCount(): void
    {
        $widget = $this->widget($this->step('Pending'), $this->linkStep('Guide'));

        $this->assertStringContainsString('0 of 1 done', $widget->title());
    }

    /** With nothing checkable there is no progress to count — the bar says just Welcome. */
    public function testAnAllLinkListGetsThePlainTitle(): void
    {
        $widget = $this->widget($this->linkStep('Guide'));
        $lines = $this->rendered($widget);

        $this->assertStringContainsString('Welcome', $widget->title());
        $this->assertStringNotContainsString('done', $widget->title());
        $this->assertStringNotContainsString('All set', \implode("\n", $lines), 'nothing was completed, so no banner');
    }

    /** A pending step offers its action; a done one does not. */
    public function testPendingStepsShowTheirActionAndDoneStepsDoNot(): void
    {
        $lines = $this->rendered($this->widget($this->step('Pending'), $this->doneStep('Finished')));

        $this->assertStringContainsString('[Do it]', $lines[self::FIRST_STEP_ROW]);
        $this->assertStringNotContainsString('[Do it]', $lines[self::FIRST_STEP_ROW + 3]);
    }

    /**
     * State must survive with no colour: under `--no-ansi` and high contrast a
     * Role resolves to nothing, so done vs pending vs link can only be the glyph.
     */
    public function testStepKindsAreDistinguishableWithoutColour(): void
    {
        $lines = $this->rendered($this->widget($this->doneStep('Finished'), $this->step('Pending'), $this->linkStep('Guide')));

        $this->assertStringContainsString('[✓]', $lines[self::FIRST_STEP_ROW]);
        $this->assertStringContainsString('[ ]', $lines[self::FIRST_STEP_ROW + 3]);
        $this->assertStringContainsString('[→]', $lines[self::FIRST_STEP_ROW + 6]);
    }

    /** The detail aligns under the title text, not under the marker. */
    public function testTheDetailIndentMatchesTheMarkerPrefix(): void
    {
        // Asserted on the SECOND card: the first is selected, and its accent
        // bar (a 3-byte glyph) would shift these byte-based offsets while the
        // visible columns stay put — the bar tests pin that geometry.
        $lines = $this->rendered($this->widget($this->step('First'), $this->step('Second', detail: 'more prose')));

        // Margin (2) plus '[ ]  ' (5): both texts start at column 7.
        $this->assertSame(7, \strpos($lines[self::FIRST_STEP_ROW + 3], needle: 'Second'));
        $this->assertSame(7, \strpos($lines[self::FIRST_STEP_ROW + 4], needle: 'more prose'));
    }

    /** The bar marks every row of the selected card — and only that card. */
    public function testTheSelectedCardShowsTheAccentBarOnEveryRow(): void
    {
        $lines = $this->rendered($this->widget($this->step('First'), $this->step('Second')));

        $this->assertSame(0, \strpos($lines[self::FIRST_STEP_ROW], needle: Glyphs::CARD_ACCENT), 'the title row starts with the bar');
        $this->assertSame(0, \strpos($lines[self::FIRST_STEP_ROW + 1], needle: Glyphs::CARD_ACCENT), 'so does the detail row');
        $this->assertStringNotContainsString(Glyphs::CARD_ACCENT, $lines[self::FIRST_STEP_ROW + 2], 'the spacer stays clean');
        $this->assertStringNotContainsString(Glyphs::CARD_ACCENT, $lines[self::FIRST_STEP_ROW + 3], 'the unselected card has no bar');
    }

    /** The bar replaces margin columns, so the marker and title do not shift. */
    public function testTheBarDoesNotShiftTheSelectedCardsColumns(): void
    {
        $lines = $this->rendered($this->widget($this->step('First'), $this->step('Second')));

        // Same visible columns as an unselected card: marker at 2, title at 7.
        $this->assertSame(2, AnsiUtils::visibleWidth(\substr($lines[self::FIRST_STEP_ROW], offset: 0, length: (int) \strpos($lines[self::FIRST_STEP_ROW], needle: '['))));
        $this->assertSame(2, \strpos($lines[self::FIRST_STEP_ROW + 3], needle: '['), 'the unselected card for comparison');
    }

    public function testMovingTheSelectionMovesTheBar(): void
    {
        $widget = $this->widget($this->step('First'), $this->step('Second'));
        $widget->handleInput("\x1b[B");
        $lines = $this->rendered($widget);

        $this->assertStringNotContainsString(Glyphs::CARD_ACCENT, $lines[self::FIRST_STEP_ROW]);
        $this->assertSame(0, \strpos($lines[self::FIRST_STEP_ROW + 3], needle: Glyphs::CARD_ACCENT), 'the bar followed the selection');
    }

    /** The selected card's rows keep the exact pane width, wrapped details included. */
    public function testTheBarKeepsEveryLineExactlyThePaneWidth(): void
    {
        $detail = 'this explanation is deliberately long enough that it cannot fit on a single detail row of a forty column pane';
        $lines = $this->widget($this->step('First', detail: $detail))->render(new RenderContext(40, 20));

        foreach ($lines as $row => $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line), "row {$row}");
        }
    }

    /** The full styling: accent bar, emphasised title, tint — resolved through the themed sheet. */
    public function testTheSelectionStylesTheBarTintAndTitle(): void
    {
        $widget = $this->widget($this->step('First'), $this->step('Second'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $lines = $widget->render(new RenderContext(60, 12));

        $title = $lines[self::FIRST_STEP_ROW];
        $this->assertStringContainsString("\x1b[38;5;110m", $title, 'the TabAccent bar colour');
        $this->assertStringContainsString("\x1b[1m", $title, 'bold — the bar and the title emphasis');
        $this->assertStringContainsString("\x1b[38;5;231m", $title, 'the Bright title');
        $this->assertStringContainsString("\x1b[48;5;238m", $title, 'the focused .card tint');
        $detail = $lines[self::FIRST_STEP_ROW + 1];
        $this->assertStringContainsString("\x1b[38;5;110m", $detail, 'the bar runs down the card');
        $this->assertStringContainsString("\x1b[48;5;238m", $detail);
        $this->assertStringNotContainsString("\x1b[38;5;231m", $detail, 'only the title is emphasised');
    }

    /** Losing pane focus dims the tint but keeps the bar — the selection stays legible. */
    public function testAnUnfocusedSelectionKeepsTheBarWithTheDimmerTint(): void
    {
        $widget = $this->widget($this->step('First'), $this->step('Second'));
        AttachedStyles::attach($widget);
        $widget->setFocused(false);
        $title = $widget->render(new RenderContext(60, 12))[self::FIRST_STEP_ROW];

        $this->assertStringContainsString(Glyphs::CARD_ACCENT, $title);
        $this->assertStringContainsString("\x1b[48;5;237m", $title, 'the unfocused .card tint');
        $this->assertStringNotContainsString("\x1b[48;5;238m", $title);
    }

    public function testHoverTintsAnUnselectedCardAndLosesToTheSelection(): void
    {
        $widget = $this->widget($this->step('First'), $this->step('Second'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        $widget->render(new RenderContext(60, 12));

        $widget->hoverRow(self::FIRST_STEP_ROW + 3);
        $second = $widget->render(new RenderContext(60, 12))[self::FIRST_STEP_ROW + 3];
        $this->assertStringContainsString("\x1b[48;5;236m", $second, 'hover tints the unselected card');

        $widget->hoverRow(self::FIRST_STEP_ROW);
        $first = $widget->render(new RenderContext(60, 12))[self::FIRST_STEP_ROW];
        $this->assertStringContainsString("\x1b[48;5;238m", $first, 'selection styling holds');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $first, 'no hover tint on the selected card');
    }

    /** A long detail wraps into further rows rather than being clipped away. */
    public function testALongDetailWrapsInsteadOfTruncating(): void
    {
        $detail = 'this explanation is deliberately long enough that it cannot fit on a single detail row of a forty column pane';
        $lines = $this->rendered($this->widget($this->step('First', detail: $detail)), rows: 20, columns: 40);

        $text = \implode("\n", \array_map(trim(...), $lines));
        foreach (\explode(' ', $detail) as $word) {
            $this->assertStringContainsString($word, $text, 'no word of the detail is lost');
        }
        $this->assertStringContainsString('this explanation', $lines[self::FIRST_STEP_ROW + 1]);
        $this->assertStringContainsString('deliberately', $lines[self::FIRST_STEP_ROW + 2], 'the detail continues on a second row');
    }

    /**
     * The action label is the affordance, so the *title* truncates first and
     * the button survives a narrow pane.
     */
    public function testANarrowPaneTruncatesTheTitleBeforeTheAction(): void
    {
        $lines = $this->rendered($this->widget($this->step('A very long step title indeed')), rows: 8, columns: 30);

        $this->assertStringContainsString('[Do it]', $lines[0]);
        $this->assertStringContainsString('…', $lines[0], 'the title took the cut');
    }

    /** Too narrow for even marker + action: the whole line clips rather than garbles. */
    public function testATinyPaneFallsBackToWholeLineTruncation(): void
    {
        $lines = $this->rendered($this->widget($this->step('A very long step title indeed')), rows: 8, columns: 12);

        $this->assertStringContainsString('[ ]', $lines[0]);
        $this->assertStringNotContainsString('[Do it]', $lines[0]);
        $this->assertStringContainsString('…', $lines[0]);
    }

    public function testEnterRunsTheSelectedStep(): void
    {
        $ran = 0;
        $widget = $this->widget($this->step('First', static function () use (&$ran): void {
            ++$ran;
        }));

        $widget->handleInput("\r");

        $this->assertSame(1, $ran);
    }

    /** A link step is never done, so it stays runnable forever. */
    public function testALinkStepRunsEveryTime(): void
    {
        $ran = 0;
        $widget = $this->widget($this->linkStep('Guide', static function () use (&$ran): void {
            ++$ran;
        }));

        $widget->handleInput("\r");
        $widget->handleInput("\r");

        $this->assertSame(2, $ran);
    }

    public function testArrowKeysMoveTheSelectionAndClampAtBothEnds(): void
    {
        $ran = [];
        $widget = $this->widget(
            $this->step('First', static function () use (&$ran): void {
                $ran[] = 'first';
            }),
            $this->step('Second', static function () use (&$ran): void {
                $ran[] = 'second';
            }),
        );

        $widget->handleInput("\x1b[A"); // already at the top
        $widget->handleInput("\r");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // clamps at the last step
        $widget->handleInput("\r");

        $this->assertSame(['first', 'second'], $ran);
    }

    /** Running a finished step must not act — a stray Enter cannot undo anything. */
    public function testRunningADoneStepIsANoOp(): void
    {
        $ran = 0;
        $widget = $this->widget($this->doneStep('Finished', static function () use (&$ran): void {
            ++$ran;
        }));

        $widget->handleInput("\r");

        $this->assertSame(0, $ran);
    }

    public function testRunningAStepNotifiesTheView(): void
    {
        $refreshed = 0;
        $widget = $this->widget($this->step('First'));
        $widget->onRan(static function () use (&$refreshed): void {
            ++$refreshed;
        });

        $widget->handleInput("\r");

        $this->assertSame(1, $refreshed);
    }

    /**
     * A click selects and nothing more. Running a step installs software, and
     * no peer list executes on a bare click either.
     */
    public function testClickingAStepSelectsItWithoutRunningIt(): void
    {
        $ran = 0;
        $widget = $this->widget(
            $this->step('First'),
            $this->step('Second', static function () use (&$ran): void {
                ++$ran;
            }),
        );
        $widget->render(new RenderContext(60, 12)); // the row map is built at render

        $widget->handleClick(self::FIRST_STEP_ROW + 3); // the second step's title row
        $this->assertSame(0, $ran, 'a click never runs a step');

        $widget->handleInput("\r");
        $this->assertSame(1, $ran, 'but it did select it');
    }

    /**
     * The row map's reason for existing: a step owns several rows, so every one
     * of its detail rows must select that step and not the next one.
     */
    public function testClickingAnyOfAStepsDetailRowsSelectsThatStep(): void
    {
        $ran = [];
        $detail = 'this explanation is deliberately long enough that it cannot fit on a single detail row of a forty column pane';
        $widget = $this->widget(
            $this->step(
                'First',
                static function () use (&$ran): void {
                    $ran[] = 'first';
                },
                detail: $detail,
            ),
            $this->step('Second', static function () use (&$ran): void {
                $ran[] = 'second';
            }),
        );
        $widget->render(new RenderContext(40, 20));

        $widget->handleClick(self::FIRST_STEP_ROW + 2); // the FIRST step's second detail row
        $widget->handleInput("\r");

        $this->assertSame(['first'], $ran);
    }

    /** The title bar, the hero and the spacer rows own no step. */
    public function testClickingChromeIsInert(): void
    {
        $ran = 0;
        $widget = $this->widget(
            $this->step('First'),
            $this->step('Second', static function () use (&$ran): void {
                ++$ran;
            }),
        );
        $widget->render(new RenderContext(60, 12));
        $widget->handleClick(self::FIRST_STEP_ROW + 3); // select the second step

        $widget->handleClick(0); // the title bar
        $widget->handleClick(2); // the hero greeting
        $widget->handleClick(self::FIRST_STEP_ROW + 2); // the spacer between cards
        $widget->handleInput("\r");

        $this->assertSame(1, $ran, 'the selection was never moved by the chrome clicks');
    }

    public function testHoverTintsOnlyStepRows(): void
    {
        $widget = $this->widget($this->step('First'));
        $widget->render(new RenderContext(60, 12));

        $this->assertTrue($widget->hoverRow(self::FIRST_STEP_ROW), 'a step row');
        $this->assertTrue($widget->hoverRow(0), 'the title bar clears the tint');
        $this->assertFalse($widget->clearHover(), 'already cleared');
    }

    public function testEscapeFiresTheExitCallback(): void
    {
        $exited = 0;
        $widget = $this->widget($this->step('First'));
        $widget->onExit(static function () use (&$exited): void {
            ++$exited;
        });

        $widget->handleInput("\x1b");

        $this->assertSame(1, $exited);
    }

    /** Every rendered line pads to the pane width, or the layout garbles. */
    public function testEveryLineIsExactlyThePaneWidth(): void
    {
        $widget = $this->widget($this->step('First'), $this->doneStep('Second'));

        foreach ($widget->render(new RenderContext(40, 6)) as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    /** The width invariant must also hold when details wrap and the hero shows. */
    public function testEveryLineIsExactlyThePaneWidthWhenDetailsWrap(): void
    {
        $detail = 'this explanation is deliberately long enough that it cannot fit on a single detail row of a forty column pane';
        $widget = $this->widget($this->step('First', detail: $detail), $this->linkStep('Guide'));

        foreach ($widget->render(new RenderContext(40, 20)) as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
    }

    /**
     * The gap the old tests missed: more steps than fit must not overflow the
     * pane, and the selection must stay visible when it moves past the fold.
     */
    public function testMoreStepsThanFitScrollInsteadOfOverflowing(): void
    {
        $steps = [];
        for ($i = 0; $i < 12; ++$i) {
            $steps[] = $this->step('Step '.$i);
        }
        $widget = $this->widget(...$steps);

        $this->assertCount(8, $widget->render(new RenderContext(60, 8)), 'exactly the pane height');

        for ($i = 0; $i < 11; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $lines = $this->rendered($widget, rows: 8);

        $this->assertStringContainsString('Step 11', \implode("\n", $lines), 'the selection scrolled into view');
        $this->assertCount(8, $lines);
    }

    /** All done is a banner above the cards, not a replacement for them. */
    public function testAllSetIsSaidOutLoudWhenEveryStepIsDone(): void
    {
        $lines = $this->rendered($this->widget($this->doneStep('First'), $this->linkStep('Guide')));

        $this->assertStringContainsString('All set', $lines[self::FIRST_STEP_ROW]);
        $this->assertStringContainsString('Help', $lines[self::FIRST_STEP_ROW], 'and how to get back');
        $this->assertStringContainsString('[✓]', $lines[self::FIRST_STEP_ROW + 2], 'the done card is still shown');
        $this->assertStringContainsString('Guide', $lines[self::FIRST_STEP_ROW + 5], 'the link step stays reachable');
    }

    public function testNoStepsRendersAMessageRatherThanABlankPane(): void
    {
        $lines = $this->rendered($this->widget());

        $this->assertStringContainsString('Nothing to set up', $lines[self::FIRST_STEP_ROW]);
    }

    /** Replacing the steps with a shorter list must not strand the selection. */
    public function testSelectionClampsWhenTheStepListShrinks(): void
    {
        $ran = [];
        $widget = $this->widget($this->step('a'), $this->step('b'), $this->step('c'));
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // on the third

        $widget->setSteps([$this->step('only', static function () use (&$ran): void {
            $ran[] = 'only';
        })]);
        $widget->handleInput("\r");

        $this->assertSame(['only'], $ran);
    }

    /** A fresh open lands on the first step still needing the user. */
    public function testResetViewSelectsTheFirstPendingStepAndClearsHover(): void
    {
        $ran = [];
        $widget = $this->widget(
            $this->doneStep('Finished'),
            $this->step('Second', static function () use (&$ran): void {
                $ran[] = 'second';
            }),
            $this->step('Third', static function () use (&$ran): void {
                $ran[] = 'third';
            }),
        );
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // parked on the third from a previous visit
        $widget->render(new RenderContext(60, 12));
        $widget->hoverRow(self::FIRST_STEP_ROW);

        $widget->resetView();

        $this->assertFalse($widget->clearHover(), 'the hover tint was already cleared');
        $widget->handleInput("\r");
        $this->assertSame(['second'], $ran, 'the first pending step, not the parked one');
    }

    /** With everything done the reset parks at the top rather than hunting. */
    public function testResetViewWithEverythingDoneScrollsBackToTheTop(): void
    {
        $steps = [];
        for ($i = 0; $i < 12; ++$i) {
            $steps[] = $this->doneStep('Step '.$i);
        }
        $widget = $this->widget(...$steps);
        for ($i = 0; $i < 11; ++$i) {
            $widget->handleInput("\x1b[B"); // scrolled deep into the list
        }

        $widget->resetView();
        $lines = $this->rendered($widget, rows: 8);

        $this->assertStringContainsString('Step 0', \implode("\n", $lines), 'the window is back at the top');
    }

    /**
     * The input hook (the menu overlay's key grab) takes precedence over every
     * binding, so a step must not run while a menu is open over the pane.
     */
    public function testSetStepsInvalidatesTheRenderRevision(): void
    {
        $widget = new WelcomeWidget(new Translator('en'));
        $widget->setSteps([$this->step('a')]);
        $revision = $widget->getRenderRevision();

        $widget->setSteps([$this->step('b')]);

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testResetViewInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget($this->step('a'));
        $revision = $widget->getRenderRevision();

        $widget->resetView();

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testMovingSelectionInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget($this->step('a'), $this->step('b'));
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\x1b[B");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testClickSelectingAStepInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget($this->step('a'), $this->step('b'));
        $widget->render(new RenderContext(60, 12));
        $revision = $widget->getRenderRevision();

        $widget->handleClick(self::FIRST_STEP_ROW + 3);

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    public function testRunningAStepInvalidatesTheRenderRevision(): void
    {
        $widget = $this->widget($this->step('a'));
        $revision = $widget->getRenderRevision();

        $widget->handleInput("\r");

        $this->assertGreaterThan($revision, $widget->getRenderRevision());
    }

    /** With no context row count, render() falls back to a 12-row default. */
    public function testAZeroRowContextFallsBackToTwelveDefaultRows(): void
    {
        $lines = $this->widget($this->step('a'))->render(new RenderContext(60, 0));

        $this->assertCount(12, $lines);
    }

    public function testMessageRowsPutTheMarginBeforeTheText(): void
    {
        $line = $this->widget()->render(new RenderContext(60, 12))[self::FIRST_STEP_ROW];

        // The message is dimmed (styled) but the margin is plain leading
        // spaces; a concat-order mutant would put the (unstyled) text first.
        $this->assertMatchesRegularExpression('/^  +\x1b\[/', $line, 'plain margin, then the styled text');
    }

    public function testHeroCentersWithNoNegativePadAtAnyWidth(): void
    {
        // 'Welcome to Porta' is 16 columns wide — a width narrower than that
        // must not go negative (str_repeat rejects a negative count).
        $lines = $this->rendered($this->widget($this->step('a')), columns: 10);

        $this->assertNotSame([], $lines);
    }

    public function testWideEnoughPaneCentersTheHeroPastTheFloorMargin(): void
    {
        // At width 90 the content column caps at 72, leaving 18 spare columns
        // — intdiv(18, 2) = 9, well past the H_MARGIN=2 floor, so an intdiv
        // divisor mutant (÷3 = 6) is observable here where it isn't at 60.
        // Asserted on the SECOND (unselected) card — the first's accent bar
        // is a multi-byte glyph that would shift a byte-based strpos.
        $lines = $this->rendered($this->widget($this->step('First'), $this->step('Second')), columns: 90);

        $this->assertSame(9, \strpos($lines[self::FIRST_STEP_ROW + 3], needle: '['), 'the card margin is 9, not the H_MARGIN floor');
    }

    public function testAnExtremelyNarrowPaneStillProducesAtLeastOneContentColumn(): void
    {
        // width=5: content = min(5-4, 72) = 1. A max(2, …) mutant would need
        // 2 content columns out of a 5-wide pane, which cannot fit the H_MARGIN
        // floor (2) twice — the render must not throw or garble.
        $lines = $this->widget($this->step('a'))->render(new RenderContext(5, 6));

        $this->assertNotSame([], $lines);
        foreach ($lines as $line) {
            $this->assertSame(5, AnsiUtils::visibleWidth($line));
        }
    }

    /** The all-done banner's own two rows count toward the scroll's chrome budget. */
    public function testAllSetBannerConsumesScrollBudgetSoLateStepsStillScrollIntoView(): void
    {
        $steps = [$this->doneStep('done')];
        for ($i = 0; $i < 12; ++$i) {
            $steps[] = $this->step('Step '.$i);
        }
        $widget = $this->widget(...$steps);
        for ($i = 0; $i < 12; ++$i) {
            $widget->handleInput("\x1b[B"); // select the very last step
        }

        $lines = $this->rendered($widget, rows: 8);

        $this->assertStringContainsString('Step 11', \implode("\n", $lines), 'the last step scrolled into view');
    }

    public function testANarrowPaneWholeLineFallbackConcatenatesPrefixTitleAndSuffixInOrder(): void
    {
        $lines = $this->rendered($this->widget($this->step('ABCDEFGHIJ')), rows: 8, columns: 12);

        $screen = AnsiUtils::stripAnsiCodes($lines[0]);
        // Prefix ('[ ]  ') must come before the title text, which must come
        // before the '[Do it]' suffix would — even though truncation clips
        // it — the relative ORDER of what survives must be prefix-then-title.
        $this->assertMatchesRegularExpression('/\[ \].*AB/', $screen);
    }

    public function testMoveUpKeybindingMovesTheSelectionUp(): void
    {
        $widget = $this->widget($this->step('a'), $this->step('b'), $this->step('c'));
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // selected = 2

        $widget->handleInput("\x1b[A"); // Key::UP

        $lines = $this->rendered($widget);
        $this->assertSame(0, \strpos($lines[self::FIRST_STEP_ROW + 3], needle: Glyphs::CARD_ACCENT), 'selection moved back to step 1, not stuck at 2');
    }

    public function testAnInstalledInputHookSwallowsInput(): void
    {
        $ran = 0;
        $widget = $this->widget($this->step('First', static function () use (&$ran): void {
            ++$ran;
        }));
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\r");

        $this->assertSame(0, $ran);
    }
}
