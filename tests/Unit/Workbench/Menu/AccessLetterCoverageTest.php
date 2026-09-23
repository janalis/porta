<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuModel;
use Porta\Workbench\Menu\MenuPanel;
use Porta\Workbench\Menu\Mnemonics;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Every title of the app menu, and every item in each of its menus, gets an
 * access letter of its own in every shipped language — all but those a menu
 * has more items than letters for. The letters are worked out from the
 * translated labels, so a catalogue change is what could take one away: a
 * menu grown past its free letters, or two labels translated alike.
 *
 * The allowance is the pigeonhole and nothing more. Italian's Edit menu has
 * 21 items spelled with 20 distinct letters, so one of them goes without;
 * a deal that left out a second would fail here.
 */
final class AccessLetterCoverageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['en', 'de', 'es', 'fr', 'he', 'it', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryTitleAndItemHasALetter(string $locale): void
    {
        $menus = MenuModel::build(new CommandRegistry(), new CodeEditorWidget(), new Keybindings([]), new Translator($locale));

        $missing = [];
        foreach (Mnemonics::assign(\array_map(static fn (Menu $menu): string => $menu->title, $menus)) as $i => $at) {
            if (null !== $at) {
                continue;
            }
            $missing[] = 'title '.$menus[$i]->title;
        }
        $shortfall = 0;
        foreach ($menus as $menu) {
            $labels = [];
            foreach (MenuPanel::letters($menu->items) as $i => $at) {
                if ($menu->items[$i]->separator) {
                    continue;
                }
                $labels[] = $menu->items[$i]->label;
                if (null === $at) {
                    $missing[] = $menu->title.' › '.$menu->items[$i]->label;
                }
            }
            $letters = \array_unique(\array_filter(\array_map(Mnemonics::key(...), \mb_str_split(\implode('', $labels)))));
            $shortfall += \max(0, \count($labels) - \count($letters));
        }

        $this->assertLessThanOrEqual($shortfall, \count($missing), "{$locale}: no access letter for these, beyond the menus' letters: ".\implode(', ', $missing));
    }
}
