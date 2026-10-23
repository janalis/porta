<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Widget\ContextMenu;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\Menu\ViewContextMenu;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\Menu\ViewMenuSources;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The context menu has two hand-maintained dispatch chains — a right-click
 * keyed by position ({@see \Porta\Input\MouseRouter::handleRightPress()}) and
 * Ctrl+F10 keyed by focus ({@see \Porta\Workbench\Menu\ContextMenuOpener::openForFocus()}).
 * They share their item lists and their finish, but not their dispatch, and
 * dispatch is where they drifted: each grew branches the other never got.
 *
 * This pins the coverage from both ends. A pane that gains a menu on one chain
 * and not the other fails here, which is the only thing that kept the two in
 * step before — nothing.
 */
final class ContextMenuParityTest extends TestCase
{
    use PreservesPath;
    use RunsIde;

    private const string CTRL_F10 = "\x1b[21;5~";

    /**
     * Every pane both chains agree has a menu, and an item that proves the
     * right list opened. The tab strip is reachable by pointing at a tab and,
     * from the keyboard, by focusing a viewer that has no menu of its own.
     */
    public function testTheSamePaneOffersTheSameMenuFromEitherChain(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();

                    // --- The editor: right-click and Ctrl+F10 agree.
                    $byMouse = $this->menuFor($tui, $ide, static function ($tui, Ide $ide): void {
                        $rect = Widgets::rectIn($ide, $tui->getById('editor'));
                        $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    });
                    $byKeyboard = $this->menuFor($tui, $ide, static function ($tui): void {
                        $tui->setFocus($tui->getById('editor'));
                        $tui->handleInput(self::CTRL_F10);
                    });
                    $this->assertNotSame([], $byMouse, 'the editor has a menu');
                    $this->assertSame($byMouse, $byKeyboard, 'the editor offers one menu, however you ask for it');

                    // --- The tree: right-click a node vs Ctrl+F10 on the selection.
                    $byMouse = $this->menuFor($tui, $ide, static function ($tui, Ide $ide): void {
                        $rect = Widgets::rectIn($ide, $tui->getById('tree'));
                        $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 2, $rect->row + 2));
                    });
                    $byKeyboard = $this->menuFor($tui, $ide, static function ($tui): void {
                        $tui->setFocus($tui->getById('tree'));
                        $tui->handleInput(self::CTRL_F10);
                    });
                    $this->assertNotSame([], $byMouse, 'the tree has a menu');
                    $this->assertSame($byMouse, $byKeyboard, 'the tree offers one menu, however you ask for it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A full-screen view's panes are no core widget either chain can name, so
     * the open view answers for itself: the pointed-at cell on a right-click,
     * its focused pane — anchor included — on Ctrl+F10. The settings view has
     * no menus of its own; the source handed in stands for a plugin view's.
     */
    public function testAnOpenViewAnswersBothChainsWithItsOwnMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui);
                    $items = [new MenuItem('Compare with branch', '', static function (): void {})];
                    Services::resolve($ide, ViewMenuSources::class)->register('settings', new readonly class($items) implements ViewMenuSource {
                        /** @param list<MenuItem> $items */
                        public function __construct(
                            private array $items,
                        ) {}

                        public function contextItemsAt(int $row, int $col): ?array
                        {
                            return $this->items;
                        }

                        public function focusedContextMenu(): ?ViewContextMenu
                        {
                            return new ViewContextMenu($this->items, row: 7, col: 11);
                        }
                    });

                    $byMouse = $this->menuFor($tui, $ide, static function ($tui): void {
                        $tui->handleInput("\x1b[<2;40;8M");
                    });
                    $overlay = Services::resolve($ide, MenuOverlayController::class);
                    $anchor = null;
                    $byKeyboard = $this->menuFor($tui, $ide, static function ($tui) use ($overlay, &$anchor): void {
                        $tui->handleInput(self::CTRL_F10);
                        $anchor = [Probe::get($overlay, 'contextAnchorRow'), Probe::get($overlay, 'contextAnchorCol')];
                    });
                    $this->assertSame(['Compare with branch'], $byMouse, 'the view answered the right-click');
                    $this->assertSame($byMouse, $byKeyboard, 'and Ctrl+F10 on its focused pane');
                    $this->assertSame([7, 11], $anchor, 'anchored where the view said, not at the pointer');
                },
            ],
        );
    }

    /**
     * The fallback is the half that had drifted: Ctrl+F10 explained itself on
     * a menu-less pane while a right-click did nothing at all, which reads as
     * broken rather than answered.
     */
    public function testBothChainsSaySoWhenAPaneHasNoMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, Ide $ide): void {
                    // The find bar is a focused pane with no context menu (the
                    // go-to-file popup used to be the example; it is a modal
                    // picker now, which owns input outright).
                    $tui->handleInput("\x06"); // ctrl+f → open the find bar
                    $this->frame($tui); // the bar's rect is read below: a bare tick is not a frame
                    $this->assertSame('find-bar', $tui->getFocus()?->getId());

                    $tui->handleInput(self::CTRL_F10);
                    $tui->tick();
                    $this->assertStringContainsString('No context menu here', $this->statusLine($tui), 'the keyboard already explained itself');

                    // Clear the flash, then ask the same question with the mouse.
                    $tui->getById('status')->flash('', seconds: 0.0);
                    $this->assertStringNotContainsString('No context menu here', $this->statusLine($tui));

                    // A right-click over the find bar reaches no menu-bearing pane.
                    // The bar is a single row at the top of the editor pane, so the
                    // click must land on that row (row + 1 in 1-based screen coords),
                    // not below it where the editor now sits.
                    $rect = Widgets::rectIn($ide, $tui->getById('find-bar'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 2, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringContainsString('No context menu here', $this->statusLine($tui), 'and now the mouse does too');
                    $this->assertFalse(
                        Services::resolve($ide, ContextMenu::class)
                            ->isOpen(),
                        'without opening anything',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** The status bar's rendered text, where a flash lands. */
    private function statusLine(mixed $tui): string
    {
        return AnsiUtils::stripAnsiCodes(Paint::lines($tui->getById('status'), new RenderContext(160, 1))[0]);
    }

    /**
     * The labels of whatever menu $open leaves on screen, then closed again.
     *
     * @return list<string>
     */
    private function menuFor(mixed $tui, mixed $ide, callable $open): array
    {
        $open($tui, $ide);
        $tui->tick();
        $menu = Services::resolve($ide, ContextMenu::class);
        // The menu floats via the overlay and occupies no layout row, so its
        // rows come from segments() rather than render().
        $labels = \array_map(
            static fn (array $row): string => \trim(AnsiUtils::stripAnsiCodes($row['segment'])),
            $menu->segments($menu->panelWidth()),
        );
        $tui->handleInput("\x1b");
        $tui->tick();

        return $labels;
    }
}
