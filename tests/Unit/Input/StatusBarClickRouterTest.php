<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Document;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\StatusBarClickRouter;
use Porta\Tests\Support\EditorState;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\StatusClickSpan;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Which verb a status click runs.
 *
 * The two kinds are dispatched differently and neither can stand in for the
 * other: the tool windows are registry commands, while `goto_line` is an editor
 * action, because the editor's action router is built inside the widget rather
 * than registered as a service.
 */
final class StatusBarClickRouterTest extends TestCase
{
    private StatusBarWidget $statusBar;
    private CommandRegistry $commands;
    private CodeEditorWidget $editor;

    /** @var list<string> */
    private array $ran = [];

    private function router(): StatusBarClickRouter
    {
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 40, rows: 1), renderer: $renderer);
        $this->statusBar = new StatusBarWidget();
        $this->statusBar->setProvider(static fn (): array => [
            'left' => '',
            'right' => 'AB CD',
            'rightClicks' => [
                new StatusClickSpan(0, 2, 'ide_problems'),
                new StatusClickSpan(3, 5, 'goto_line'),
            ],
        ]);
        $tui->add($this->statusBar);
        $tui->requestRender();
        $tui->processRender();

        $this->commands = new CommandRegistry();
        $this->commands->add(
            'ide_problems',
            function (): void {
                $this->ran[] = 'ide_problems';
            },
            label: 'Show Problems',
        );

        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document("aa\nbb\ncc\n"));

        return new StatusBarClickRouter(new HitTester($renderer), $this->statusBar, $this->commands, $this->editor);
    }

    /** @var list<int> the columns the right-click router was asked to open a menu at */
    private array $menuAsked = [];

    /** The right-click router's menu opener, as a spy: it opens nothing. */
    private function menus(MouseEvent $mouse): bool
    {
        $this->menuAsked[] = $mouse->col;

        return false;
    }

    /** A left press at the 0-based screen column/row, as the terminal sends it. */
    private static function pressAt(int $col, int $row): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;".($col + 1).';'.($row + 1).'M');
        self::assertNotNull($event);

        return $event;
    }

    private function clickOn(string $needle): MouseEvent
    {
        $row = $this->statusBar->render(new RenderContext(40, 1))[0];
        $col = \mb_strpos(AnsiUtils::stripAnsiCodes($row), $needle);
        $this->assertNotFalse($col);

        return self::pressAt($col, 0);
    }

    public function testAClickOnAToolWindowCellRunsThatCommand(): void
    {
        $router = $this->router();

        $this->assertTrue($router->route($this->clickOn('AB'), $this->menus(...)));
        $this->assertSame(['ide_problems'], $this->ran);
    }

    public function testAClickOnTheCaretCellGoesToTheEditorNotTheRegistry(): void
    {
        // `goto_line` is not a registry command and never will be — the
        // editor's action router is not a service — so this branch is the only
        // way that cell does anything.
        $router = $this->router();

        $this->assertTrue($router->route($this->clickOn('CD'), $this->menus(...)));
        $this->assertSame([], $this->ran, 'the registry must not have claimed an editor verb');
        // The EFFECT, not just the absence of the other branch: dropping the
        // runAction call leaves both of those assertions true.
        $this->assertTrue(EditorState::promptOpen($this->editor), 'the Go to line prompt opened');
    }

    public function testACommandTheRegistryClaimsIsNotALSORunByTheEditor(): void
    {
        // `goto_line` is registered here as a command as well, which is the only
        // way to see the difference: without the early return the registry runs
        // it AND the editor runs it, and the second run opens a prompt nobody
        // asked for.
        $router = $this->router();
        $this->commands->add(
            'goto_line',
            function (): void {
                $this->ran[] = 'goto_line';
            },
            label: 'Go to Line',
        );

        $this->assertTrue($router->route($this->clickOn('CD'), $this->menus(...)));
        $this->assertSame(['goto_line'], $this->ran);
        $this->assertFalse(EditorState::promptOpen($this->editor), 'the registry claimed it; the editor must not run it too');
    }

    public function testAClickOutsideEveryCellIsNotConsumed(): void
    {
        // It must fall through, or the panes below never see their clicks.
        $router = $this->router();

        $this->assertFalse($router->route(self::pressAt(2, 0), $this->menus(...)));
    }

    public function testAClickOutsideTheStatusRowIsNotConsumed(): void
    {
        $router = $this->router();

        $this->assertFalse($router->route(self::pressAt(0, 5), $this->menus(...)));
    }

    public function testAClickOnANEARBYRowAtACELLSCOLUMNIsStillNotConsumed(): void
    {
        // The row guard, not the column one: this lands on the exact column a
        // status cell occupies, but several rows above the status bar. Without
        // the rect check it would run that cell's action from inside the
        // editor.
        $router = $this->router();
        $cellColumn = $this->clickOn('AB')->col;

        $this->assertFalse($router->route(self::pressAt($cellColumn, 7), $this->menus(...)));
        $this->assertSame([], $this->ran, 'nothing may run from a click off the status row');
    }

    /**
     * A click on the row that runs no action is offered to the menu opener —
     * the branch cell's menu is the right-click router's — and its answer is
     * the router's: consumed only when a menu opened.
     */
    public function testAClickWithNoActionIsOfferedToTheMenuOpener(): void
    {
        $router = $this->router();

        $this->assertFalse($router->route(self::pressAt(2, 0), $this->menus(...)), 'no menu opened: not consumed');
        $this->assertSame([2], $this->menuAsked);
        $this->assertTrue($router->route(self::pressAt(2, 0), static fn (): bool => true), 'a menu opened: consumed');

        $router->route($this->clickOn('AB'), $this->menus(...));
        $this->assertSame([2], $this->menuAsked, 'a cell with an action never asks');
    }
}
