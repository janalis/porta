<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Desktop;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Porta\Desktop\DesktopProtocol;
use Porta\Desktop\DesktopSession;
use Porta\Desktop\SystemMenu;
use Porta\Editor\BufferManager;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\WorkbenchActions;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Tui;

/** The window's close button against a booted IDE, spoken as `desktop/contract/protocol.json` defines it. */
final class DesktopProtocolTest extends TestCase
{
    use BootsIde;

    /** @var array{examples: array{token: string, root: string, close: string, ready: string, accepted: string, closing: string}} */
    private array $contract;

    #[\Override]
    protected function setUp(): void
    {
        /** @var array{examples: array{token: string, root: string, close: string, ready: string, accepted: string, closing: string}} $contract */
        $contract = \json_decode((string) \file_get_contents(\dirname(__DIR__, 3).'/desktop/contract/protocol.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->contract = $contract;
    }

    #[After]
    protected function leaveDesktopMode(): void
    {
        if ($this->booted) {
            Services::resolve($this->ide, Tui::class)->stop();
        }
        \putenv(DesktopSession::FLAG);
        \putenv(DesktopSession::TOKEN);
        \putenv(DesktopSession::MENU);
    }

    private function bootSystemMenu(): DesktopProtocol
    {
        \putenv(DesktopSession::MENU.'=system');

        return $this->bootDesktop();
    }

    /** @return array{generation: string, menus: list<array{title: string, items: list<array{label: string, shortcut: string, separator: bool, enabled: bool, checked: ?bool}>}>} */
    private function sentMenus(): array
    {
        $this->assertSame(1, \preg_match('/;menu;([A-Za-z0-9+\/=]+)\x1b/', $this->terminal->getOutput(), $sent), 'the menus were sent');

        /** @var array{generation: string, menus: list<array{title: string, items: list<array{label: string, shortcut: string, separator: bool, enabled: bool, checked: ?bool}>}>} */
        return \json_decode((string) \base64_decode($sent[1], strict: true), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    private function bootDesktop(): DesktopProtocol
    {
        \putenv(DesktopSession::FLAG.'=1');
        \putenv(DesktopSession::TOKEN.'='.$this->contract['examples']['token']);
        $this->bootIde(['composer.json' => '{}', 'a.php' => "<?php\n"]);
        $this->terminal->clearOutput();

        return Services::resolve($this->ide, DesktopProtocol::class);
    }

    private function stopped(): bool
    {
        return false === Services::resolve($this->ide, Tui::class)->isRunning();
    }

    private function makeDirty(): void
    {
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $buffers->open($this->project->path('a.php'), content: "<?php\n")->document->insert('x');
        $this->assertSame(1, $buffers->dirtyCount());
    }

    public function testACleanIdeSaysItIsClosingAndStops(): void
    {
        $protocol = $this->bootDesktop();
        $tui = Services::resolve($this->ide, Tui::class);
        $tui->start();
        $event = new InputEvent($this->contract['examples']['close']);

        $protocol->onInput($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertStringContainsString($this->contract['examples']['closing'], $this->terminal->getOutput());
        $this->assertTrue($this->stopped());
    }

    public function testUnsavedWorkKeepsTheWindowOpenUntilTheCloseIsRepeated(): void
    {
        $protocol = $this->bootDesktop();
        $this->makeDirty();
        $tui = Services::resolve($this->ide, Tui::class);
        $tui->start();

        $protocol->onInput(new InputEvent($this->contract['examples']['close']));

        $this->assertStringContainsString($this->contract['examples']['accepted'], $this->terminal->getOutput());
        $this->assertStringNotContainsString($this->contract['examples']['closing'], $this->terminal->getOutput());
        $this->assertFalse($this->stopped());

        $protocol->onInput(new InputEvent($this->contract['examples']['close']));

        $this->assertStringContainsString($this->contract['examples']['closing'], $this->terminal->getOutput());
        $this->assertTrue($this->stopped());
    }

    public function testAForgedTokenIsSwallowedAndDoesNothing(): void
    {
        $protocol = $this->bootDesktop();
        $tui = Services::resolve($this->ide, Tui::class);
        $tui->start();
        $this->terminal->clearOutput();
        $forged = \str_replace($this->contract['examples']['token'], \str_repeat('f', 32), $this->contract['examples']['close']);
        $event = new InputEvent($forged);

        $protocol->onInput($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertStringNotContainsString('porta-desktop', $this->terminal->getOutput());
        $this->assertFalse($this->stopped());
    }

    public function testOtherInputPassesThrough(): void
    {
        $protocol = $this->bootDesktop();
        $event = new InputEvent('a');

        $protocol->onInput($event);

        $this->assertFalse($event->isPropagationStopped());
    }

    public function testReadyNamesTheWindowAfterTheProject(): void
    {
        $protocol = $this->bootDesktop();

        $protocol->announceReady($this->contract['examples']['root']);

        $this->assertStringContainsString("\x1b]0;shop — Porta\x07", $this->terminal->getOutput());
        $this->assertStringContainsString($this->contract['examples']['ready'], $this->terminal->getOutput());
    }

    public function testTheRealInputPathReachesTheProtocolInDesktopMode(): void
    {
        $this->bootDesktop();
        Services::resolve($this->ide, Tui::class)->start();

        $this->terminal->simulateInput($this->contract['examples']['close']);

        $this->assertStringContainsString($this->contract['examples']['closing'], $this->terminal->getOutput());
    }

    public function testATerminalLaunchNeitherAnswersNorNamesTheWindow(): void
    {
        $this->bootIde();
        $this->terminal->clearOutput();
        Services::resolve($this->ide, DesktopProtocol::class)->announceReady('/work/shop');
        Services::resolve($this->ide, Tui::class)->start();

        $this->terminal->simulateInput($this->contract['examples']['close']);

        $this->assertStringNotContainsString('porta-desktop;', $this->terminal->getOutput());
        $this->assertStringNotContainsString('shop — Porta', $this->terminal->getOutput());
        $this->assertFalse($this->stopped());
    }

    public function testTheSystemMenuTakesOverFromTheWindowsOwnBar(): void
    {
        $protocol = $this->bootSystemMenu();
        $menuBar = Services::resolve($this->ide, MenuBarWidget::class);

        $this->assertTrue($menuBar->usesSystemMenu());
        $this->assertSame([], $menuBar->render(new RenderContext(80, rows: 24)));
        /** @var WorkbenchActions $actions */
        $actions = Probe::get($protocol, 'actions');
        $actions->openMenu();
        $this->assertFalse($menuBar->isOpen(), 'F10 has no bar to open');
    }

    public function testAHostWithoutASystemMenuKeepsTheBarAndIsSentNoMenus(): void
    {
        $protocol = $this->bootDesktop();

        $protocol->announceReady('/work/shop');

        $this->assertFalse(Services::resolve($this->ide, MenuBarWidget::class)->usesSystemMenu());
        $this->assertNotSame([], Services::resolve($this->ide, MenuBarWidget::class)->render(new RenderContext(80, rows: 24)));
        $this->assertStringNotContainsString(';menu;', $this->terminal->getOutput());
    }

    public function testTheMenusReachTheHostAsPlainTextOnceUntilTheyChange(): void
    {
        $protocol = $this->bootSystemMenu();

        $protocol->announceReady('/work/shop');
        $menus = $this->sentMenus();
        $protocol->detach();

        $this->assertNotSame([], $menus['menus']);
        $this->assertStringNotContainsString("\x1b", (string) \json_encode($menus, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        $this->terminal->clearOutput();
        $this->assertNull(Services::resolve($this->ide, SystemMenu::class)->changes(), 'nothing changed');
    }

    public function testAChoiceRunsTheItemAndAStaleOneDoesNot(): void
    {
        $protocol = $this->bootSystemMenu();
        $ran = 0;
        $menuBar = Services::resolve($this->ide, MenuBarWidget::class);
        $menuBar->setMenus([new Menu('File', [new MenuItem('Go', '', static function () use (&$ran): void {
            ++$ran;
        })])]);
        $protocol->announceReady('/work/shop');
        $generation = $this->sentMenus()['generation'];
        $protocol->detach();
        $choice = fn (string $generation, int $item = 0): InputEvent => new InputEvent("\x1b]7777;porta-desktop;{$this->contract['examples']['token']};menu;{$generation};0;{$item}\x1b\\");

        $protocol->onInput($choice($generation));
        $this->assertSame(1, $ran);

        $protocol->onInput($choice('00ff'));
        $protocol->onInput($choice($generation, item: 9));
        $this->assertSame(1, $ran, 'a rebuilt menu, or an item that is not there');
    }

    public function testAChoiceIsRefusedWithoutASystemMenuOrTheToken(): void
    {
        $protocol = $this->bootDesktop();
        $ran = 0;
        $menuBar = Services::resolve($this->ide, MenuBarWidget::class);
        $menuBar->setMenus([new Menu('File', [new MenuItem('Go', '', static function () use (&$ran): void {
            ++$ran;
        })])]);
        $generation = \json_decode((string) \base64_decode((string) new SystemMenu($menuBar)->changes(), strict: true), associative: true, flags: \JSON_THROW_ON_ERROR)['generation'];

        $protocol->onInput(new InputEvent("\x1b]7777;porta-desktop;{$this->contract['examples']['token']};menu;{$generation};0;0\x1b\\"));

        $this->assertSame(0, $ran);
    }

    public function testADisabledItemIsNotRunByTheSystemMenu(): void
    {
        $menuBar = new MenuBarWidget();
        $ran = 0;
        $menuBar->setMenus([new Menu('File', [
            MenuItem::separator(),
            new MenuItem(
                'Off',
                '',
                static function () use (&$ran): void {
                    ++$ran;
                },
                isEnabled: static fn (): bool => false,
            ),
        ])]);

        $menuBar->runMenuItem(0, 0);
        $menuBar->runMenuItem(0, 1);
        $menuBar->runMenuItem(5, 0);

        $this->assertSame(0, $ran);
    }
}
