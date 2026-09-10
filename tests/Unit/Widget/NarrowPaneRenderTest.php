<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\KeybindingRow;
use Porta\Editor\Document;
use Porta\Editor\FindMode;
use Porta\History\SearchHistoryStore;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Terminal\TerminalBuffer;
use Porta\Tests\Support\FakeFindTarget;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Tests\Support\TempProject;
use Porta\Widget\BreadcrumbsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\DockTab;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * No pane widget may render a row wider than the pane it was given.
 *
 * The renderer throws `RenderException` when one does, and that escapes the
 * render loop — so this is not a garbled line, it is the IDE exiting. It
 * happened: twenty-one identical crashes in this repo's own
 * `.porta/error.log`, all `CodeEditorWidget` rendering nine columns into one.
 *
 * Every widget here passed at eighty columns and had no test at eight. A pane
 * width is something the user drags and the terminal decides, so the hostile
 * end of the range is not an edge case — it is Tuesday.
 */
final class NarrowPaneRenderTest extends TestCase
{
    use RendersExactWidth;

    /**
     * Held for the run, not chained off inline: {@see TempProject}'s destructor
     * removes its directory, so a temporary freed at the end of the expression
     * takes the tree's fixture with it. The architecture gate refuses the
     * inline form for exactly this reason.
     */
    private static ?TempProject $project = null;

    /** Held for the same reason: the find bar keeps a reference to it. */
    private static ?SearchHistoryStore $searchHistory = null;

    /**
     * Every pane widget, each given something to DRAW — a provider or a row
     * set. Without content they render zero rows, and a sweep over zero rows
     * passes by having nothing to look at.
     *
     * @return iterable<string, array{AbstractWidget}>
     */
    public static function paneWidgets(): iterable
    {
        self::$project ??= new TempProject(['a.php' => "<?php\n", 'src/Deep.php' => "<?php\n"]);
        yield 'file tree' => [new FileTreeWidget(self::$project->dir)];

        $status = new StatusBarWidget();
        $status->setProvider(static fn (): array => ['left' => 'src/Widget/Thing.php ●', 'right' => '12:4  LSP:none']);
        yield 'status bar' => [$status];

        $tabs = new TabBarWidget();
        $tabs->setProvider(static fn (): array => [
            ['name' => 'Alpha.php', 'dirty' => true, 'active' => true],
            ['name' => 'Beta.php', 'dirty' => false, 'active' => false],
        ]);
        yield 'tab bar' => [$tabs];

        // The SIDE position is a different render path with a fixed intrinsic
        // width, and it is what this repo's own config selects. It emitted
        // twenty-four columns into any narrower pane.
        $sideTabs = new TabBarWidget();
        $sideTabs->setProvider(static fn (): array => [
            ['name' => 'Alpha.php', 'dirty' => true, 'active' => true],
            ['name' => 'Beta.php', 'dirty' => false, 'active' => false],
        ]);
        $sideTabs->setPosition(TabBarPosition::Right);
        yield 'tab bar (side)' => [$sideTabs];

        $crumbs = new BreadcrumbsWidget();
        $crumbs->setProvider(static fn (): array => [
            ['label' => 'App\\Http', 'line' => 2],
            ['label' => 'Service', 'line' => 4],
            ['label' => 'handle', 'line' => 6],
        ]);
        yield 'breadcrumbs' => [$crumbs];

        // The dock panels are overlay widgets: they draw nothing until they
        // are revealed, which the vacuity guard in the sweep catches.
        $results = new ResultsPanelWidget();
        $results->open('Problems', ResultsPanelFields::problemList());
        $results->setResults([['path' => 'src/A.php', 'line' => 3, 'text' => 'a match on a long line of text']]);
        yield 'results panel' => [$results];

        $terminal = new TerminalPanelWidget();
        $buffer = new TerminalBuffer();
        $buffer->feed("$ composer test\nOK (6293 tests)\n");
        $terminal->open('Terminal', $buffer);
        yield 'terminal panel' => [$terminal];

        $debug = new DebugPanelWidget();
        $debug->open('Debug');
        $debug->setFrames([['id' => 1, 'name' => 'Service::handle', 'path' => '/p/src/Service.php', 'line' => 12]]);
        yield 'debug panel' => [$debug];

        // The settings view's two panes, the dock strip and the welcome
        // screen: eight of the seventeen registered widgets had never been
        // through this sweep, and one of them emitted eight columns into a
        // one-column pane. A shortcut column that yields only its LABEL still
        // draws the shortcut, so once the label reached zero the row stopped
        // shrinking.
        $keys = new KeybindingsListWidget(new Translator('en'));
        $keys->setRows([
            new KeybindingRow('save', 'Save File', 'ctrl+s', overridden: true),
            new KeybindingRow('copy_reference', 'Copy Reference', 'ctrl+alt+shift+c'),
        ]);
        yield 'keybindings list' => [$keys];

        $nav = new SettingsNavWidget(new Translator('en'));
        $nav->setPluginEntries(['git', 'local-history']);
        yield 'settings nav' => [$nav];

        $form = new SettingsFormWidget(new Translator('en'));
        $form->setRows([new SettingsRow(key: 'autoPairs', label: 'Auto-pairs', kind: SettingsRowKind::Bool, value: true, restart: false)]);
        yield 'settings form' => [$form];

        $dock = new DockTabsWidget();
        $dock->setProvider(static fn (): array => [
            ['tab' => DockTab::Results, 'label' => 'Problems', 'active' => true, 'key' => '', 'status' => '3 problems'],
            ['tab' => DockTab::Terminal, 'label' => 'Terminal', 'active' => false, 'key' => '', 'status' => ''],
        ]);
        $dock->beforeRender();
        yield 'dock tabs' => [$dock];

        yield 'welcome' => [new WelcomeWidget(new Translator('en'))];

        // Four more that were registered widgets all along and had never been
        // in here — found by listing the #[AsIdeService] widgets and diffing
        // them against this provider. Three of the four overflowed: the find
        // bar in both its modes (an eight-column tag strip drawn whatever the
        // pane could seat) and the help viewer (padRight() with no ceiling
        // over a markdown renderer that floors its own wrap width).
        $bar = new MenuBarWidget();
        $bar->setMenus([
            new Menu('File', [new MenuItem('Save', 'Ctrl+S', static function (): void {})]),
            new Menu('Edit', [new MenuItem('Undo', 'Ctrl+Z', static function (): void {})]),
            new Menu('View', []),
        ]);
        yield 'menu bar' => [$bar];

        // Open is a second assembler: the dropdown draws through the overlay.
        $openBar = new MenuBarWidget();
        $openBar->setMenus([new Menu('File', [new MenuItem('Save', 'Ctrl+S', static function (): void {})])]);
        $openBar->open(0);
        yield 'menu bar (open)' => [$openBar];

        self::$searchHistory ??= new SearchHistoryStore(self::$project->dir);
        foreach ([
            ['find bar',    FindMode::Find],
            ['replace bar', FindMode::Replace],
        ] as [$name, $mode]) {
            $find = new FindBarWidget(new Translator('en'), history: self::$searchHistory);
            $find->setFocused(true); // focused, so the field carries a caret — the column that made the row nine wide
            $find->open(new FakeFindTarget(new Document("nothing here\n")), mode: $mode);
            yield $name => [$find];
        }

        $help = new HelpViewerWidget();
        $help->show('/docs/help.md', "# Heading\n\nSome help text that is long enough to need truncating.\n");
        yield 'help viewer' => [$help];
    }

    #[DataProvider('paneWidgets')]
    public function testAPaneWidgetFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        $this->assertFitsEveryWidth($widget);
    }

    public function testTheEDITORFitsEveryWidthWithAFileOpenAndTheGutterFull(): void
    {
        // The provider's editor has no file, which takes an early-return path
        // (the centered empty-state hint). The crash was on the ROW path, with
        // a gutter — so this one opens a file, turns the vcs strip on, and uses
        // a line count wide enough to push the number field past its floor of
        // three. Those are the two inputs the gutter's width is built from.
        $editor = new CodeEditorWidget();
        $editor->openFile('/narrow.php', "<?php\n".str_repeat("// a line\n", times: 120));
        $editor->setFocused(true);
        $editor->display()->setVcsGutter(true);

        $this->assertFitsEveryWidth($editor);
    }

    public function testTheEDITORFitsEveryWidthWithSTICKYHeadersPinned(): void
    {
        // A second gutter lives in `renderStickyRow()`, and it overflowed the
        // same way: the pinned header built its own gutter whatever the pane
        // could seat. Reaching it needs a scrolled buffer with scopes, which is
        // why the plain sweep above walked straight past it — and sticky scroll
        // is ON by default, so this path was at least as reachable as the one
        // the crash log recorded.
        $editor = new CodeEditorWidget();
        $editor->openFile('/sticky.php', "<?php\n\nclass A\n{\n    public function one(): void\n    {\n".str_repeat("        \$x = 1;\n", times: 30)."    }\n}\n");
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(25, 4); // deep inside one(), headers scrolled off

        $this->assertFitsEveryWidth($editor);
    }

    public function testTheEDITORFitsEveryWidthWithNoFileOpen(): void
    {
        // The empty-state path renders a centered hint rather than rows, and
        // centering a hint wider than the pane is its own way to overflow.
        $this->assertFitsEveryWidth(new CodeEditorWidget());
    }

    public function testTheEDITORFitsEveryWidthWhileSHOWINGADocument(): void
    {
        // showDocument() is the viewer path (help pages, previews), which
        // reaches the same assembler by another door.
        $editor = new CodeEditorWidget();
        $editor->showDocument('/preview.md', new Document("# heading\n\ntext\n"));

        $this->assertFitsEveryWidth($editor);
    }
}
