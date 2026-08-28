<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSearchRequested;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;

final class SettingsNavWidgetTest extends TestCase
{
    use RendersWidget;

    /**
     * Tab lands on the nav, so Ctrl+F has to work from there too. A key that
     * works on one of a view's two panes and not the other is the kind people
     * stop trying.
     */
    public function testCtrlFAsksForTheSettingsPickerFromTheNavToo(): void
    {
        $events = new EventDispatcher();
        $fired = 0;
        $events->addListener(SettingsSearchRequested::class, static function () use (&$fired): void {
            ++$fired;
        });

        new SettingsNavWidget(new Translator('en'), events: $events)->handleInput("\x06");

        $this->assertSame(1, $fired);
    }

    private function widget(?array &$selected = null): SettingsNavWidget
    {
        $selected = [];
        $widget = new SettingsNavWidget(new Translator('en'));
        AttachedStyles::attach($widget); // the selection shade resolves through the themed sheet
        $widget->setFocused(true);
        $widget->onSelect(static function (string $id) use (&$selected): void {
            $selected[] = $id;
        });

        return $widget;
    }

    public function testRenderIsMemoizedUntilSomethingInvalidates(): void
    {
        // The flex-0 side pane renders twice per frame (intrinsic measure +
        // real pass); the memo serves both and misses after a selection move.
        $widget = $this->widget();

        $first = $widget->render(new RenderContext(24, 20));
        $this->assertSame($first, $widget->render(new RenderContext(24, 20)), 'a same-revision render serves the memo');

        $widget->handleInput("\x1b[B"); // move the selection: invalidates
        $second = $widget->render(new RenderContext(24, 20));
        $this->assertNotSame($first, $second, 'a selection move must miss the memo');
        $this->assertNotSame(implode('', $first), implode('', $second), 'the moved shade recolors the rows');
    }

    public function testRendersSectionsHeaderAndLanguages(): void
    {
        $screen = $this->plain($this->widget()->render(new RenderContext(24, 20)));

        $this->assertStringContainsString('General', $screen);
        $this->assertStringContainsString('Keybindings', $screen);
        $this->assertStringContainsString('Plugins', $screen);
        $this->assertStringContainsString('Languages', $screen);
        $this->assertStringContainsString('PHP', $screen);
        $this->assertStringContainsString('TypeScript', $screen);
        $this->assertStringContainsString('Twig', $screen);
        $this->assertSame('general', $this->widget()->selectedId(), 'opens on General');
    }

    public function testArrowNavigationSkipsTheHeaderAndFires(): void
    {
        $widget = $this->widget($selected);

        $widget->handleInput("\x1b[B"); // → Interface
        $this->assertSame('interface', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Highlighting
        $this->assertSame('highlighting', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Keybindings
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleInput("\x1b[B"); // → Plugins
        $widget->handleInput("\x1b[B"); // skips the Languages header → PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $this->assertSame(['interface', 'highlighting', 'keybindings', 'plugins', 'lang:php'], $selected);

        $widget->handleInput("\x1b[A"); // back up, skipping the Languages header → Plugins
        $this->assertSame('plugins', $widget->selectedId());
    }

    public function testNavigationClampsAtBothEnds(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\x1b[A"); // already at the top
        $this->assertSame('general', $widget->selectedId());

        for ($i = 0; $i < 20; ++$i) {
            $widget->handleInput("\x1b[B");
        }
        $this->assertSame('lang:dotenv', $widget->selectedId(), 'stops at the last language');
    }

    public function testClickSelectsAndTheHeaderIsInert(): void
    {
        $widget = $this->widget($selected);
        $widget->render(new RenderContext(24, 20)); // establish scroll

        $widget->handleClick(3); // Keybindings (row 3, after the new Highlighting row)
        $this->assertSame('keybindings', $widget->selectedId());

        $widget->handleClick(5); // the Languages header → no-op
        $this->assertSame('keybindings', $widget->selectedId());
        $this->assertSame(['keybindings'], $selected, 'the header fires nothing');

        $widget->handleClick(6); // PHP
        $this->assertSame('lang:php', $widget->selectedId());
        $widget->handleClick(99); // dead space
        $this->assertSame('lang:php', $widget->selectedId());
    }

    public function testSetPluginEntriesInsertsIndentedSubItemsUnderPlugins(): void
    {
        $widget = $this->widget($selected);
        $widget->setPluginEntries(['phpstan', 'mago']);

        $screen = $this->plain($widget->render(new RenderContext(24, 24)));
        $this->assertStringContainsString('   phpstan', $screen, 'plugin sub-items indent like languages');
        $this->assertMatchesRegularExpression('/Plugins\s*\n\s{3}phpstan\s*\n\s{3}mago/', $screen, 'they sit right under the Plugins section');

        $widget->setActive('plugin:mago');
        $this->assertSame('plugin:mago', $widget->selectedId());

        // A rebuild without mago falls the selection back to the Plugins section.
        $widget->setPluginEntries(['phpstan']);
        $this->assertSame('plugins', $widget->selectedId());

        // A rebuild that keeps the selected id preserves it.
        $widget->setActive('plugin:phpstan');
        $widget->setPluginEntries(['phpstan', 'late']);
        $this->assertSame('plugin:phpstan', $widget->selectedId());
    }

    public function testSetActiveHighlightsAnEntry(): void
    {
        $widget = $this->widget();
        $widget->setActive('lang:css');
        $this->assertSame('lang:css', $widget->selectedId());
        $widget->setActive('bogus'); // no-op
        $this->assertSame('lang:css', $widget->selectedId());
    }

    public function testFocusedSelectionHighlightAndHover(): void
    {
        $widget = $this->widget();
        $rendered = $widget->render(new RenderContext(24, 20));
        $this->assertStringContainsString("\x1b[48;5;31m", $rendered[0], 'General selected + focused');

        $widget->setFocused(false);
        $this->assertTrue($widget->hoverRow(6)); // hover PHP
        $rendered = $widget->render(new RenderContext(24, 20));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[6], 'hovered language tinted');
        $this->assertTrue($widget->hoverRow(5), 'hovering the header clears the language tint');
        $this->assertFalse($widget->clearHover(), 'already cleared');
    }

    public function testTabAndEscapeAndInputHook(): void
    {
        $fired = [];
        $widget = $this->widget();
        $widget->onFocusNext(static function () use (&$fired): void {
            $fired[] = 'next';
        });
        $widget->onExit(static function () use (&$fired): void {
            $fired[] = 'exit';
        });
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $this->assertSame(['next', 'exit'], $fired);

        $widget->onInput(static fn (string $d): bool => true);
        $widget->handleInput("\x1b[B"); // swallowed
        $this->assertSame('general', $widget->selectedId());
    }

    public function testWidthResizeExpandAndOverlay(): void
    {
        $widget = $this->widget();
        $widget->setWidth(30);
        $this->assertSame(30, $widget->width());
        $widget->setWidth(5); // clamped up
        $this->assertSame(14, $widget->width());
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROP\x1b[0m"]]);
        $this->assertStringContainsString('DROP', $widget->render(new RenderContext(24, 20))[0]);
    }

    public function testDefaultWidthIsTwentyTwo(): void
    {
        $this->assertSame(22, $this->widget()->width());
    }

    public function testMaxWidthIsSixty(): void
    {
        $widget = $this->widget();
        $widget->setWidth(60);
        $this->assertSame(60, $widget->width());
        $widget->setWidth(61); // clamped down
        $this->assertSame(60, $widget->width());
    }

    public function testARealSelectionMoveBumpsTheRenderRevision(): void
    {
        $widget = $this->widget();
        $before = $widget->getRenderRevision();
        $widget->setPluginEntries(['phpstan']);
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'setPluginEntries always invalidates');

        $widget->render(new RenderContext(24, 20)); // establish scroll for the click's row mapping
        $before = $widget->getRenderRevision();
        $widget->handleClick(3); // 'general' -> 'keybindings'
        $this->assertSame('keybindings', $widget->selectedId());
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'a real click-driven move invalidates');

        $before = $widget->getRenderRevision();
        $widget->setActive('general'); // a different, real move
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'setActive on a real move invalidates');
    }

    public function testHoveringARowInvalidatesButAReRequestOfTheSameRowDoesNot(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(24, 20));

        $before = $widget->getRenderRevision();
        $this->assertTrue($widget->hoverRow(1));
        $this->assertGreaterThan($before, $widget->getRenderRevision(), 'a real hover change invalidates');

        $before = $widget->getRenderRevision();
        $this->assertFalse($widget->hoverRow(1), 'still the same row → no change');
        $this->assertSame($before, $widget->getRenderRevision());
    }

    public function testMoveWithoutAnOnSelectCallbackDoesNotCrash(): void
    {
        $widget = new SettingsNavWidget(new Translator('en'));
        AttachedStyles::attach($widget);
        $widget->setFocused(true);
        // deliberately no onSelect(...) registered

        $widget->handleInput("\x1b[B"); // move_down: moveTo() must not call a null callback
        $this->assertSame('interface', $widget->selectedId());
    }

    public function testRenderRowCountRespectsTheContextRowsIncludingTheSingleRowFloor(): void
    {
        $widget = $this->widget();
        $this->assertCount(1, $widget->render(new RenderContext(24, 1)), 'exactly 1 context row renders exactly 1 line');
        $this->assertCount(12, $widget->render(new RenderContext(24, 0)), 'a non-positive context row count falls back to 12');
    }
}
