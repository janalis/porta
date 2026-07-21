<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Onboarding\WelcomeStep;
use Porta\Widget\Onboarding\WelcomeWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The Welcome screen's step cards: layout, running, and the row map that turns
 * a pointer row back into a step.
 *
 * Layout is a title bar then three rows per step (title, detail, spacer), so
 * step N owns rows `1 + 3N` and `2 + 3N`.
 */
final class WelcomeWidgetTest extends TestCase
{
    private const int FIRST_STEP_ROW = 1;

    private function widget(WelcomeStep ...$steps): WelcomeWidget
    {
        $widget = new WelcomeWidget(new Translator('en'));
        $widget->setSteps(array_values($steps));

        return $widget;
    }

    /** @param \Closure(): void|null $run */
    private function step(string $title, ?\Closure $run = null): WelcomeStep
    {
        return $this->build($title, done: false, run: $run);
    }

    /** @param \Closure(): void|null $run */
    private function doneStep(string $title, ?\Closure $run = null): WelcomeStep
    {
        return $this->build($title, done: true, run: $run);
    }

    /** @param \Closure(): void|null $run */
    private function build(string $title, bool $done, ?\Closure $run): WelcomeStep
    {
        return new WelcomeStep(
            $title,
            'why it matters',
            'Do it',
            static fn (): bool => $done,
            $run ?? static function (): void {},
        );
    }

    /** @return list<string> */
    private function plain(WelcomeWidget $widget, int $rows = 12, int $columns = 60): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($columns, $rows)));
    }

    public function testRendersATitleBarThenACardPerStep(): void
    {
        $lines = $this->plain($this->widget($this->step('Syntax highlighting'), $this->step('Language server')));

        $this->assertStringContainsString('Welcome', $lines[0], 'the title bar');
        $this->assertStringContainsString('Syntax highlighting', $lines[1]);
        $this->assertStringContainsString('why it matters', $lines[2], 'the detail sits on its own line');
        $this->assertStringContainsString('Language server', $lines[4]);
    }

    public function testTheTitleCountsProgressAndHintsTheKeys(): void
    {
        $lines = $this->plain($this->widget($this->doneStep('Finished'), $this->step('Pending')));

        $this->assertStringContainsString('1 of 2 done', $lines[0]);
        $this->assertStringContainsString('run', $lines[0], 'the keys are advertised');
    }

    /** A pending step offers its action; a done one does not. */
    public function testPendingStepsShowTheirActionAndDoneStepsDoNot(): void
    {
        $lines = $this->plain($this->widget($this->step('Pending'), $this->doneStep('Finished')));

        $this->assertStringContainsString('[Do it]', $lines[1]);
        $this->assertStringNotContainsString('[Do it]', $lines[4]);
    }

    /**
     * State must survive with no colour: under `--no-ansi` and high contrast a
     * Role resolves to nothing, so done vs pending can only be the glyph.
     */
    public function testDoneAndPendingAreDistinguishableWithoutColour(): void
    {
        $lines = $this->plain($this->widget($this->doneStep('Finished'), $this->step('Pending')));

        $this->assertStringContainsString('[x]', $lines[1]);
        $this->assertStringContainsString('[ ]', $lines[4]);
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

        $widget->handleClick(4); // the second step's title row
        $this->assertSame(0, $ran, 'a click never runs a step');

        $widget->handleInput("\r");
        $this->assertSame(1, $ran, 'but it did select it');
    }

    /**
     * The row map's reason for existing: a step owns two rows, so its detail
     * line must select that step and not the next one.
     */
    public function testClickingAStepsDetailLineSelectsThatStep(): void
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
        $widget->render(new RenderContext(60, 12));

        $widget->handleClick(self::FIRST_STEP_ROW + 1); // the FIRST step's detail row
        $widget->handleInput("\r");

        $this->assertSame(['first'], $ran);
    }

    /** The title bar and the spacer rows own no step. */
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
        $widget->handleClick(4); // select the second step

        $widget->handleClick(0); // the title bar
        $widget->handleClick(3); // the spacer between cards
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
        $lines = $this->plain($widget, rows: 8);

        $this->assertStringContainsString('Step 11', implode("\n", $lines), 'the selection scrolled into view');
        $this->assertCount(8, $lines);
    }

    public function testAllSetIsSaidOutLoudWhenEveryStepIsDone(): void
    {
        $lines = $this->plain($this->widget($this->doneStep('First'), $this->doneStep('Second')));

        $this->assertStringContainsString('All set', $lines[1]);
        $this->assertStringContainsString('Help', $lines[1], 'and how to get back');
    }

    public function testNoStepsRendersAMessageRatherThanABlankPane(): void
    {
        $lines = $this->plain($this->widget());

        $this->assertStringContainsString('Nothing to set up', $lines[1]);
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

    /**
     * The input hook (the menu overlay's key grab) takes precedence over every
     * binding, so a step must not run while a menu is open over the pane.
     */
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
