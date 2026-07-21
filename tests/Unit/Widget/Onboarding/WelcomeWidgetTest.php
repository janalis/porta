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
 * The Welcome screen's step list: rendering, running, and the done state.
 */
final class WelcomeWidgetTest extends TestCase
{
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
    private function plain(WelcomeWidget $widget, int $rows = 8): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(60, $rows)));
    }

    public function testRendersAHeadingThenOneRowPerStep(): void
    {
        $lines = $this->plain($this->widget($this->step('Syntax highlighting'), $this->step('Language server')));

        $this->assertStringContainsString('Welcome to Porta', $lines[1]);
        $this->assertStringContainsString('Syntax highlighting', $lines[3]);
        $this->assertStringContainsString('Language server', $lines[4]);
    }

    /** A pending step shows its action; a done step shows neither action nor prompt. */
    public function testPendingStepsShowTheirActionAndDoneStepsDoNot(): void
    {
        $lines = $this->plain($this->widget($this->step('Pending'), $this->doneStep('Finished')));

        $this->assertStringContainsString('[Do it]', $lines[3]);
        $this->assertStringNotContainsString('[Do it]', $lines[4]);
        $this->assertStringContainsString('done', $lines[4]);
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

    public function testClickingAStepRowSelectsAndRunsIt(): void
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

        $widget->handleClick(4); // the second step (three heading rows precede)

        $this->assertSame(['second'], $ran);
    }

    /** The heading rows are not steps: clicking them does nothing. */
    public function testClickingTheHeadingIsInert(): void
    {
        $ran = 0;
        $widget = $this->widget($this->step('First', static function () use (&$ran): void {
            ++$ran;
        }));

        $widget->handleClick(1);

        $this->assertSame(0, $ran);
    }

    public function testHoverTintsOnlyStepRows(): void
    {
        $widget = $this->widget($this->step('First'));

        $this->assertTrue($widget->hoverRow(3), 'the first step row');
        $this->assertTrue($widget->hoverRow(1), 'the heading clears the tint');
        $this->assertFalse($widget->clearHover(), 'already cleared');
    }

    public function testEscapeAndTabFireTheirCallbacks(): void
    {
        $exited = 0;
        $advanced = 0;
        $widget = $this->widget($this->step('First'));
        $widget->onExit(static function () use (&$exited): void {
            ++$exited;
        });
        $widget->onFocusNext(static function () use (&$advanced): void {
            ++$advanced;
        });

        $widget->handleInput("\x1b");
        $widget->handleInput("\t");

        $this->assertSame([1, 1], [$exited, $advanced]);
    }

    /** Every rendered line pads to the pane width, or the layout garbles. */
    public function testEveryLineIsExactlyThePaneWidth(): void
    {
        $widget = $this->widget($this->step('First'), $this->doneStep('Second'));

        foreach ($widget->render(new RenderContext(40, 6)) as $line) {
            $this->assertSame(40, AnsiUtils::visibleWidth($line));
        }
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
