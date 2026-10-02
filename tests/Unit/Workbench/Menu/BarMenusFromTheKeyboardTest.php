<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Tui;

/**
 * The two bars nothing focuses, reached from the keyboard: Alt+Home opens the
 * breadcrumb trail as a menu, Alt+End the status bar's cells' menus.
 */
final class BarMenusFromTheKeyboardTest extends TestCase
{
    use BootsIde;
    use ScreenReads;

    private const string ALT_HOME = "\x1b[1;3H";

    private const string ALT_END = "\x1b[1;3F";

    protected function setUp(): void
    {
        $this->bootIde(['composer.json' => '{}', 'src/Kernel.php' => "<?php\n\nclass Kernel\n{\n    public function boot(): void\n    {\n    }\n}\n"]);
    }

    public function testAltHomeOpensTheTrailAndAPathCrumbShowsItInTheTree(): void
    {
        $this->open('src/Kernel.php');
        $this->tui()->handleInput(self::ALT_HOME);

        $this->assertTrue($this->menu()->isOpen());
        $labels = $this->labels();
        $trail = $this->breadcrumbs()->trail();
        $this->assertSame(\array_column($trail, 'label'), $labels, 'the trail, crumb by crumb');

        $this->menu()->runItem((int) \array_search('src', $labels, strict: true));

        $this->assertSame($this->project->path('src'), $this->tree()->selectedNode()?->path);
        $this->assertSame($this->tree(), $this->tui()->getFocus(), 'the keys follow');
    }

    public function testASymbolCrumbGoesToItsLine(): void
    {
        $this->open('src/Kernel.php');
        $this->editor()->jumpTo(5, 0);
        $this->frame($this->tui());
        $this->tui()->handleInput(self::ALT_HOME);
        $trail = $this->breadcrumbs()->trail();
        $symbol = \array_key_first(\array_filter($trail, static fn (array $crumb): bool => null !== $crumb['line']));
        $this->assertNotNull($symbol, 'the trail names the class around the caret');
        $this->editor()->jumpTo(0, 0);

        $this->menu()->runItem($symbol);

        $this->assertSame($trail[$symbol]['line'], $this->editor()->getDocument()->cursorLine);
        $this->assertSame($this->editor(), $this->tui()->getFocus());
    }

    public function testNoTrailSaysThereIsNoMenu(): void
    {
        $this->frame($this->tui());

        $this->tui()->handleInput(self::ALT_HOME);

        $this->assertFalse($this->menu()->isOpen());
    }

    public function testAltEndGathersEveryStatusCellsMenu(): void
    {
        $ran = [];
        $item = static function (string $label) use (&$ran): MenuItem {
            return new MenuItem($label, '', static function () use ($label, &$ran): void {
                $ran[] = $label;
            });
        };
        Services::resolve($this->ide, StatusBarWidget::class)->setProvider(static fn (): array => [
            'cells' => [
                ['text' => "\x1b[1mmain\x1b[0m ▾", 'menu' => static fn (): array => [$item('Checkout…'), $item('New Branch…')]],
                ['text' => 'plain', 'menu' => null],
                ['text' => 'server ▾', 'menu' => static fn (): array => [$item('Reconnect Now')]],
            ],
            'left' => '',
            'right' => '',
        ]);
        $this->frame($this->tui());

        $this->tui()->handleInput(self::ALT_END);

        $this->assertSame(['main ▾', 'Checkout…', 'New Branch…', '', 'server ▾', 'Reconnect Now'], $this->labels(), 'each cell\'s items under its own text');
        $items = Probe::get($this->menu(), 'items');
        $this->assertFalse($items[0]->enabled(), 'a header names, it does not run');
        $this->menu()->runItem(5);
        $this->assertSame(['Reconnect Now'], $ran);
    }

    public function testAStatusBarWithNoMenusSaysSo(): void
    {
        Services::resolve($this->ide, StatusBarWidget::class)->setProvider(static fn (): array => ['cells' => [['text' => 'plain', 'menu' => null]], 'left' => '', 'right' => '']);
        $this->frame($this->tui());

        $this->tui()->handleInput(self::ALT_END);

        $this->assertFalse($this->menu()->isOpen());
        $this->assertSame([], Toasts::all($this->ide), 'a hint on the status bar, not a toast');
    }

    private function open(string $relative): void
    {
        Services::resolve($this->ide, BufferService::class)->openFile($this->project->path($relative), OpenFocus::Editor);
        $this->frame($this->tui());
    }

    /** @return list<string> */
    private function labels(): array
    {
        return \array_map(static fn (MenuItem $item): string => $item->label, Probe::get($this->menu(), 'items'));
    }

    private function menu(): ContextMenu
    {
        return Services::resolve($this->ide, ContextMenu::class);
    }

    private function breadcrumbs(): BreadcrumbsWidget
    {
        $bar = $this->tui()->getById('breadcrumbs');
        $this->assertInstanceOf(BreadcrumbsWidget::class, $bar);

        return $bar;
    }

    private function tui(): Tui
    {
        return Services::resolve($this->ide, Tui::class);
    }

    private function tree(): FileTreeWidget
    {
        return Services::resolve($this->ide, FileTreeWidget::class);
    }

    private function editor(): CodeEditorWidget
    {
        return Services::resolve($this->ide, CodeEditorWidget::class);
    }
}
