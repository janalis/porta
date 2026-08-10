<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Support;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Tests\Support\GitWidgetEvents;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The wheel-is-the-arrow-keys contract. These assert the SIDE EFFECT, not the
 * offset: a refactor that moved the selection without firing what the keyboard
 * fires would pass a distance-only test while silently breaking the pane — the
 * changes list would stop reloading its diff, the settings nav would stop
 * switching section.
 */
final class ArrowScrollTraitTest extends TestCase
{
    public function testScrollingTheChangesListReloadsTheDiffJustLikeTheArrowKeys(): void
    {
        $selected = [];
        $events = new EventDispatcher();
        $list = new ChangesListWidget(new Translator('en'), events: $events);
        GitWidgetEvents::wire($events, onSelect: static function (ChangeEntry $entry) use (&$selected): void {
            $selected[] = $entry->path;
        });
        $list->setEntries([
            new ChangeEntry('a.php', ' ', 'M'),
            new ChangeEntry('b.php', ' ', 'M'),
            new ChangeEntry('c.php', ' ', 'M'),
        ]);
        $selected = [];

        $list->scrollBy(2);

        $this->assertSame(['b.php', 'c.php'], $selected, 'each row the wheel passed fired onSelect, exactly as ↓ does');
    }

    public function testScrollingTheSettingsNavSwitchesSectionJustLikeTheArrowKeys(): void
    {
        $shown = [];
        $nav = new SettingsNavWidget(new Translator('en'));
        $nav->onSelect(static function (string $id) use (&$shown): void {
            $shown[] = $id;
        });
        $nav->render(new RenderContext(30, 20));
        $shown = [];

        $nav->scrollBy(1);

        $this->assertNotSame([], $shown, 'the wheel changed the shown section, as the keyboard does');
    }

    public function testTheWheelIsSymmetricAndItsDistanceIsTheKeyPressCount(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '', 'c.php' => '', 'd.php' => '']);
        $tree = new FileTreeWidget($project->dir);
        $tree->render(new RenderContext(30, 20));

        $down = static function (FileTreeWidget $t, int $times): void {
            for ($i = 0; $i < $times; ++$i) {
                $t->handleInput("\x1b[B");
            }
        };

        $byKeys = new FileTreeWidget($project->dir);
        $byKeys->render(new RenderContext(30, 20));
        $down($byKeys, times: 3);

        $tree->scrollBy(3);
        $this->assertSame($byKeys->render(new RenderContext(30, 20)), $tree->render(new RenderContext(30, 20)), 'three rows down = three ↓ presses');

        $tree->scrollBy(-3);
        $tree->scrollBy(0); // a zero notch is a no-op, not a stray press
        $fresh = new FileTreeWidget($project->dir);
        $fresh->render(new RenderContext(30, 20));
        $this->assertSame($fresh->render(new RenderContext(30, 20)), $tree->render(new RenderContext(30, 20)), 'scrolling back up undoes it');
    }
}
