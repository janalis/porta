<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Porta\Widget\ContextMenu;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;

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
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $tui->tick();

                    // --- The editor: right-click and Ctrl+F10 agree.
                    $byMouse = $this->menuFor($tui, $terminal, $ide, static function ($tui, $ide): void {
                        $rect = $ide->resolve(Renderer::class)
                            ->getWidgetRect($tui->getById('editor'));
                        $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 1));
                    });
                    $byKeyboard = $this->menuFor($tui, $terminal, $ide, static function ($tui): void {
                        $tui->setFocus($tui->getById('editor'));
                        $tui->handleInput(self::CTRL_F10);
                    });
                    $this->assertNotSame([], $byMouse, 'the editor has a menu');
                    $this->assertSame($byMouse, $byKeyboard, 'the editor offers one menu, however you ask for it');

                    // --- The tree: right-click a node vs Ctrl+F10 on the selection.
                    $byMouse = $this->menuFor($tui, $terminal, $ide, static function ($tui, $ide): void {
                        $rect = $ide->resolve(Renderer::class)
                            ->getWidgetRect($tui->getById('tree'));
                        $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 2, $rect->row + 2));
                    });
                    $byKeyboard = $this->menuFor($tui, $terminal, $ide, static function ($tui): void {
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
     * The fallback is the half that had drifted: Ctrl+F10 explained itself on
     * a menu-less pane while a right-click did nothing at all, which reads as
     * broken rather than answered.
     */
    public function testBothChainsSaySoWhenAPaneHasNoMenu(): void
    {
        putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    // The find bar is a focused pane with no context menu (the
                    // go-to-file popup used to be the example; it is a modal
                    // picker now, which owns input outright).
                    $tui->handleInput("\x06"); // ctrl+f → open the find bar
                    $tui->tick();
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
                    $rect = $ide->resolve(Renderer::class)
                        ->getWidgetRect($tui->getById('find-bar'));
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 2, $rect->row + 1));
                    $tui->tick();
                    $this->assertStringContainsString('No context menu here', $this->statusLine($tui), 'and now the mouse does too');
                    $this->assertFalse(
                        $ide->resolve(ContextMenu::class)
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
        return AnsiUtils::stripAnsiCodes($tui->getById('status')->render(new \Symfony\Component\Tui\Render\RenderContext(160, 1))[0]);
    }

    /**
     * The labels of whatever menu $open leaves on screen, then closed again.
     *
     * @return list<string>
     */
    private function menuFor(mixed $tui, mixed $terminal, mixed $ide, callable $open): array
    {
        $open($tui, $ide);
        $tui->tick();
        $menu = $ide->resolve(ContextMenu::class);
        // The menu floats via the overlay and occupies no layout row, so its
        // rows come from segments() rather than render().
        $labels = array_map(
            static fn (array $row): string => trim(AnsiUtils::stripAnsiCodes($row['segment'])),
            $menu->segments($menu->panelWidth()),
        );
        $tui->handleInput("\x1b");
        $tui->tick();

        return $labels;
    }
}
