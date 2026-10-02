<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\ViewWindowPresenter;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\WindowZone;
use Porta\Overlay\State\WindowFrame;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Settings (and every view like it) opens in a window over the editor: 80%
 * of the workspace, centred, moved by its title bar, resized from its edges,
 * closed by its button, Esc or a click beside it — and what it holds answers
 * the pointer as it did full-screen, because its widgets are still in the
 * tree and still hit-test at the cells they are drawn at.
 */
final class IdeViewWindowTest extends TestCase
{
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    private function project(): TempProject
    {
        \putenv('PATH=/nonexistent');

        return new TempProject([
            'demo.php' => "<?php\n".\str_repeat("echo 'abcdefghij klmnop';\n", times: 30),
        ]);
    }

    /** A left press and release at 0-based screen ($row, $col). */
    private static function click(Tui $tui, int $row, int $col): void
    {
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));
    }

    /** Press at ($row, $col), drag to ($toRow, $toCol), release there. */
    private static function drag(Tui $tui, int $row, int $col, int $toRow, int $toCol): void
    {
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
        $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $toCol + 1, $toRow + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $toCol + 1, $toRow + 1));
    }

    /** Open Settings and wait for its window to be drawn; its box. */
    private function openSettings(Tui $tui, VirtualTerminal $terminal, Ide $ide): ModalBox
    {
        $this->frame($tui);
        Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
        $this->awaitPainted($tui, $terminal, 'Settings › General ');
        $box = Services::resolve($ide, ViewWindowPresenter::class)->box();
        $this->assertInstanceOf(ModalBox::class, $box);

        return $box;
    }

    public function testSettingsOpensInAWindowCoveringEightyPercentCentred(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $box = $this->openSettings($tui, $terminal, $ide);
                    $presenter = Services::resolve($ide, ViewWindowPresenter::class);
                    $delivery = Probe::get($presenter, 'delivery');
                    $this->assertInstanceOf(OverlayDelivery::class, $delivery);
                    $bounds = $delivery->bounds();
                    $this->assertNotNull($bounds);
                    [$top, $left, $bottom, $right] = $bounds;

                    $this->assertSame(\intdiv(($right - $left) * 80, num2: 100), $box->width, '80% of the workspace wide');
                    $this->assertSame(\intdiv(($bottom - $top) * 80, num2: 100), $box->rows, '80% of it tall');
                    $this->assertSame($left + \intdiv($right - $left - $box->width, num2: 2), $box->col, 'centred across');
                    $this->assertSame($top + \intdiv($bottom - $top - $box->rows, num2: 2), $box->startRow, 'centred down');

                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertStringContainsString('Settings', $grid[$box->startRow], 'the view names its window');
                    $this->assertStringNotContainsString('Esc', $grid[$box->startRow], 'Esc closes it; the bar does not spell it out');
                    $this->assertStringContainsString('Settings › General ', \implode("\n", $grid), 'the view is drawn inside');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    public function testAClickInsideReachesTheViewAtTheCellItIsDrawnAt(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->openSettings($tui, $terminal, $ide);
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = $this->rowOf($grid, 'Interface');
                    $this->assertNotNull($row);
                    $col = \mb_strpos($grid[$row], needle: 'Interface');
                    $this->assertIsInt($col);

                    self::click($tui, $row, $col);

                    $this->awaitPainted($tui, $terminal, 'Settings › Interface ');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    public function testTheCloseButtonEscAndAClickBesideTheWindowEachCloseIt(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $views = Services::resolve($ide, ViewRegistry::class);

                    $box = $this->openSettings($tui, $terminal, $ide);
                    $close = $box->closeButton->leads() ? $box->col + 1 : $box->col + $box->width - 2;
                    self::click($tui, $box->startRow, $close);
                    $this->assertFalse($views->isOpen('settings'), 'the close button');

                    $this->openSettings($tui, $terminal, $ide);
                    $tui->handleInput("\x1b");
                    $this->await(static fn (): bool => !$views->isOpen('settings'), what: 'Esc to close the window');

                    $box = $this->openSettings($tui, $terminal, $ide);
                    self::click($tui, $box->startRow + 3, $box->col - 2);
                    $this->assertFalse($views->isOpen('settings'), 'a click on the workspace beside it');

                    $this->await(
                        fn (): bool => !\str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), 'Settings › General '),
                        what: 'the window to leave the screen',
                    );
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    public function testTheMenuBarAndStatusBarStayLiveBesideTheWindow(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->openSettings($tui, $terminal, $ide);
                    $grid = $this->screenGrid($tui, $terminal);

                    self::click($tui, \count($grid) - 1, 1); // the status bar, outside the workspace

                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->isOpen('settings'), 'the chrome is not "beside the window"');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    public function testTheTitleBarMovesTheWindowAndAnEdgeResizesIt(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $presenter = Services::resolve($ide, ViewWindowPresenter::class);
                    $box = $this->openSettings($tui, $terminal, $ide);

                    self::drag($tui, $box->startRow, $box->col + 20, $box->startRow + 2, $box->col + 15);
                    $this->frame($tui);
                    $moved = $presenter->box();
                    $this->assertInstanceOf(ModalBox::class, $moved);
                    $this->assertSame([$box->startRow + 2, $box->col - 5, $box->width, $box->rows], [$moved->startRow, $moved->col, $moved->width, $moved->rows], 'moved with the pointer, same size');

                    $right = $moved->col + $moved->width - 1;
                    self::drag($tui, $moved->startRow + 5, $right, $moved->startRow + 5, $right - 10);
                    $this->frame($tui);
                    $narrower = $presenter->box();
                    $this->assertInstanceOf(ModalBox::class, $narrower);
                    $this->assertSame(
                        [$moved->startRow, $moved->col, $moved->width - 10],
                        [$narrower->startRow, $narrower->col, $narrower->width],
                        'the right edge followed the pointer, the left stayed',
                    );

                    $this->await(
                        fn (): bool => \str_contains($this->screenGrid($tui, $terminal)[$narrower->startRow + 2] ?? '', 'General'),
                        what: 'the view to redraw at the new size',
                    );
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    public function testAMenuCommandClosesTheWindowAndOneOfTheViewsOwnKeepsIt(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $views = Services::resolve($ide, ViewRegistry::class);
                    $this->openSettings($tui, $terminal, $ide);

                    // Reaching the menu bar is the workbench's, not the editor's:
                    // the window stays.
                    $tui->handleInput("\x1b[21~"); // F10: the menu bar
                    $tui->handleInput("\r"); // … and its File menu
                    $this->awaitPainted($tui, $terminal, 'Save File');
                    $this->assertTrue($views->isOpen('settings'), 'opening the menu leaves the window');
                    $tui->handleInput("\x1b"); // close the menu

                    // File ▸ Go to File acts on the editor: the window closes first.
                    $tui->handleInput("\x1b[21~");
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Save File');
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = $this->rowOf($grid, 'Go to File');
                    $this->assertNotNull($row, 'the File menu is enabled and open');
                    $col = \mb_strpos($grid[$row], needle: 'Go to File');
                    $this->assertIsInt($col);
                    self::click($tui, $row, $col);

                    $this->await(static fn (): bool => !$views->isOpen('settings'), what: 'the window to close for a command on the editor');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    /** F6 and Shift+F6 cycle the window's own panes and never reach the editor behind it. */
    public function testFocusCyclingStaysInsideTheWindow(): void
    {
        $project = $this->project();
        $this->runIde(
            $project,
            $project->path('demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->openSettings($tui, $terminal, $ide);
                    $seen = [];
                    foreach (["\x1b[17~", "\x1b[17~", "\x1b[17~", "\x1b[17;2~", "\x1b[17;2~"] as $key) {
                        $tui->handleInput($key);
                        $seen[] = $tui->getFocus()?->getId();
                    }

                    $this->assertNotContains('editor', $seen, 'the editor behind the window never takes focus');
                    $this->assertContains('settings-nav', $seen, 'the cycle visits the window\'s panes');
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->isOpen('settings'), 'cycling focus is the workbench\'s: the window stays');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }

    /** The close button lights under the pointer and goes out when the pointer leaves — onto the menu bar too. */
    public function testTheCloseButtonLightsAndGoesOutAgain(): void
    {
        $this->runIde(
            $this->project(),
            null,
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $box = $this->openSettings($tui, $terminal, $ide);
                    $frame = Probe::get(Services::resolve($ide, ViewWindowPresenter::class), 'frame');
                    $this->assertInstanceOf(WindowFrame::class, $frame);
                    $close = $box->closeButton->leads() ? $box->col + 1 : $box->col + $box->width - 2;

                    $tui->handleInput(\sprintf("\x1b[<35;%d;%dM", $close + 1, $box->startRow + 1));
                    $this->assertSame(WindowZone::Close, $frame->hot(), 'lit under the pointer');

                    $tui->handleInput(\sprintf("\x1b[<35;%d;1M", $close + 1)); // straight up onto the menu bar
                    $this->assertNull($frame->hot(), 'out once the menu bar has the pointer');
                },
            ],
            columns: 120,
            rows: 36,
        );
    }
}
