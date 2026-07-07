<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Git\CommitBoxWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class CommitBoxWidgetTest extends TestCase
{
    private bool $staged = true;

    private function widget(?string &$committed = null): CommitBoxWidget
    {
        $widget = new CommitBoxWidget();
        $widget->setFocused(true);
        $widget->hasStagedChanges(fn (): bool => $this->staged);
        $widget->onCommit(function (string $message) use (&$committed): void {
            $committed = $message;
        });

        return $widget;
    }

    private function type(CommitBoxWidget $widget, string $text): void
    {
        foreach (str_split($text) as $ch) {
            $widget->handleInput($ch);
        }
    }

    private function plain(CommitBoxWidget $widget): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(44, 10))));
    }

    public function testMessageAssemblyAndEnabledState(): void
    {
        $widget = $this->widget();
        $this->assertFalse($widget->commitEnabled(), 'empty summary disables commit');

        $this->type($widget, 'Fix parser');
        $this->assertTrue($widget->commitEnabled());
        $this->assertSame('Fix parser', $widget->message(), 'no blank body appended without a description');

        $widget->handleInput("\t"); // into the description
        $this->type($widget, 'Long');
        $widget->handleInput("\r");
        $this->type($widget, 'body');
        $this->assertSame("Fix parser\n\nLong\nbody", $widget->message());

        $this->staged = false;
        $this->assertFalse($widget->commitEnabled(), 'nothing staged disables commit');
    }

    public function testCtrlEnterCommitsOnlyWhenEnabled(): void
    {
        $widget = $this->widget($committed);

        $widget->handleInput("\x1b[13;5u"); // ctrl+enter with no summary
        $this->assertNull($committed);

        $this->type($widget, 'Do it');
        $widget->handleInput("\x1b[13;5u");
        $this->assertSame('Do it', $committed);
    }

    public function testButtonClickCommitsAndFieldClicksMoveTheCaret(): void
    {
        $widget = $this->widget($committed);
        $this->type($widget, 'Click me');

        $widget->handleClick(2); // description rows start at rel row 2
        $this->type($widget, 'desc');
        $this->assertSame("Click me\n\ndesc", $widget->message());

        $widget->handleClick(1); // back to the summary row
        $this->type($widget, '!');
        $this->assertStringStartsWith('Click me!', $widget->message());

        $widget->handleClick($widget->buttonRow());
        $this->assertSame("Click me!\n\ndesc", $committed);
    }

    public function testTabWalksSummaryDescriptionThenNextPane(): void
    {
        $widget = $this->widget();
        $focusNext = 0;
        $widget->onFocusNext(function () use (&$focusNext): void {
            ++$focusNext;
        });

        $widget->handleInput("\t"); // summary -> description
        $this->assertSame(0, $focusNext);
        $widget->handleInput("\t"); // description -> next pane
        $this->assertSame(1, $focusNext);
    }

    public function testRenderShowsPlaceholdersButtonStateAndClearResets(): void
    {
        $widget = new CommitBoxWidget();
        $widget->hasStagedChanges(static fn (): bool => true);

        $screen = $this->plain($widget);
        $this->assertStringContainsString('Summary (required)', $screen);
        $this->assertStringContainsString('Description', $screen);
        $this->assertStringContainsString('Commit to branch', $screen);

        $widget->setFocused(true);
        $this->type($widget, 'Msg');
        $raw = $widget->render(new RenderContext(44, 10));
        $this->assertStringContainsString("\x1b[48;5;31m", implode('', $raw), 'enabled button uses the selected style');

        $widget->clear();
        $this->assertSame('', $widget->message());
        $widget->setFocused(false); // a focused empty field shows the caret, not the hint
        $this->assertStringContainsString('Summary (required)', $this->plain($widget));
    }

    public function testSummaryBackspaceAndResetField(): void
    {
        $widget = $this->widget();
        $this->type($widget, 'abc');
        $widget->handleInput("\x7f");
        $this->assertSame('ab', $widget->message());

        $widget->handleInput("\t"); // into the description
        $this->type($widget, 'x');
        $widget->resetField(); // pane re-entry lands on the summary
        $this->type($widget, '!');
        $this->assertSame("ab!\n\nx", $widget->message());
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->onInput(static fn (string $data): bool => true);
        $this->type($widget, 'swallowed');
        $this->assertSame('', $widget->message());
    }

    public function testEscapeFiresExit(): void
    {
        $widget = $this->widget();
        $exited = false;
        $widget->onExit(function () use (&$exited): void {
            $exited = true;
        });

        $widget->handleInput("\x1b");
        $this->assertTrue($exited);
    }
}
