<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\StatusBarClickRouter;
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
            'menus' => [],
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

        return new StatusBarClickRouter(new HitTester($renderer), $this->statusBar, $this->commands, new CodeEditorWidget());
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
        $col = mb_strpos(AnsiUtils::stripAnsiCodes($row), $needle);
        self::assertNotFalse($col);

        return self::pressAt($col, 0);
    }

    public function testAClickOnAToolWindowCellRunsThatCommand(): void
    {
        $router = $this->router();

        self::assertTrue($router->route($this->clickOn('AB')));
        self::assertSame(['ide_problems'], $this->ran);
    }

    public function testAClickOnTheCaretCellGoesToTheEditorNotTheRegistry(): void
    {
        // `goto_line` is not a registry command and never will be — the
        // editor's action router is not a service — so this branch is the only
        // way that cell does anything.
        $router = $this->router();

        self::assertTrue($router->route($this->clickOn('CD')));
        self::assertSame([], $this->ran, 'the registry must not have claimed an editor verb');
    }

    public function testAClickOutsideEveryCellIsNotConsumed(): void
    {
        // It must fall through, or the panes below never see their clicks.
        $router = $this->router();

        self::assertFalse($router->route(self::pressAt(2, 0)));
    }

    public function testAClickOutsideTheStatusRowIsNotConsumed(): void
    {
        $router = $this->router();

        self::assertFalse($router->route(self::pressAt(0, 5)));
    }
}
