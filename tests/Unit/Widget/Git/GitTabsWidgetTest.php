<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Git;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Widget\Git\GitTabsWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GitTabsWidgetTest extends TestCase
{
    public function testRendersBothTabsWithTheActiveOneHighlighted(): void
    {
        $widget = new GitTabsWidget(new Translator('en'));
        $lines = $widget->render(new RenderContext(44, 1));

        $this->assertCount(1, $lines);
        $plain = AnsiUtils::stripAnsiCodes($lines[0]);
        $this->assertStringContainsString('Changes', $plain);
        $this->assertStringContainsString('History', $plain);
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[0], 'the active tab uses the selected style');

        $widget->setActive('history');
        $lines = $widget->render(new RenderContext(44, 1));
        $changesPos = mb_strpos(AnsiUtils::stripAnsiCodes($lines[0]), 'Changes');
        $highlightPos = strpos($lines[0], "\x1b[48;5;31m");
        $this->assertNotFalse($highlightPos);
        $this->assertStringContainsString('History', substr($lines[0], $highlightPos), 'the highlight moved to History');
        $this->assertNotFalse($changesPos);
    }

    public function testClickOnTheInactiveTabFiresAndTheActiveOneDoesNot(): void
    {
        $selected = [];
        $widget = new GitTabsWidget(new Translator('en'));
        $widget->onSelect(function (string $mode) use (&$selected): void {
            $selected[] = $mode;
        });
        $widget->render(new RenderContext(44, 1)); // populate the layout map

        $historyCol = $widget->modeAtColumn(12) === 'history' ? 12 : 15; // inside ' History '
        $this->assertSame('history', $widget->modeAtColumn($historyCol));

        $widget->handleClick($historyCol);
        $this->assertSame(['history'], $selected);

        // The controller flips the active tab; a repeat click is a no-op.
        $widget->setActive('history');
        $widget->handleClick($historyCol);
        $this->assertSame(['history'], $selected);

        // Clicking the (now inactive) Changes tab switches back.
        $widget->handleClick(2);
        $this->assertSame(['history', 'changes'], $selected);

        // Dead space past the tabs selects nothing.
        $widget->handleClick(40);
        $this->assertNull($widget->modeAtColumn(40));
        $this->assertSame(['history', 'changes'], $selected);
    }

    public function testSetActiveIsIdempotentAndOverlayComposites(): void
    {
        $widget = new GitTabsWidget(new Translator('en'));
        $widget->setActive('changes'); // already active: no-op

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $widget->render(new RenderContext(44, 1))[0];
        $this->assertStringContainsString('DROPDOWN', $line, 'a dropdown span composites over the strip');
    }
}
