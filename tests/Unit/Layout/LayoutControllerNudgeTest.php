<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\I18n\Translator;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class LayoutControllerNudgeTest extends TestCase
{
    private TempProject $project;
    private FileTreeWidget $tree;
    private GitSidebar $gitSidebar;
    private SettingsNavWidget $settingsSidebar;

    private function controller(): LayoutController
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 30), keybindings: new Keybindings([]), renderer: $renderer = new Renderer(new StyleSheet()));
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->gitSidebar = new GitSidebar(
            new GitTabsWidget(new Translator('en')),
            new ChangesListWidget(new Translator('en')),
            new CommitBoxWidget(new Translator('en')),
            new HistoryListWidget(new Translator('en')),
        );
        $this->settingsSidebar = new SettingsNavWidget(new Translator('en'));

        return new LayoutController(
            new LayoutStore($this->project->dir),
            $tui,
            $renderer,
            $this->tree,
            new TerminalPanelWidget(),
            new ResultsPanelWidget(),
            new DebugPanelWidget(),
            $this->gitSidebar,
            $this->settingsSidebar,
        );
    }

    /** @return array<string, int|bool> the persisted layout.json contents */
    private function layoutFile(): array
    {
        $file = $this->project->dir.'/.porta/layout.json';
        if (!is_file($file)) {
            return [];
        }

        /** @var array<string, int|bool> */
        return json_decode((string) file_get_contents($file), associative: true);
    }

    public function testNudgeSidebarResizesTheTreeAndPersistsThePercent(): void
    {
        $layout = $this->controller();
        $start = $this->tree->width();

        $layout->nudgeSidebar(2);
        $this->assertSame($start + 2, $this->tree->width());
        $this->assertSame($start + 2, $this->layoutFile()['sidebarWidthPercent'], '32 of 100 cols persists as 32%');

        $layout->nudgeSidebar(-2);
        $this->assertSame($start, $this->tree->width(), 'shrinking undoes the grow');
    }

    public function testNudgeSidebarClampsAtTheFloorAndCeiling(): void
    {
        $layout = $this->controller();

        for ($i = 0; $i < 30; ++$i) {
            $layout->nudgeSidebar(-2);
        }
        $this->assertSame(12, $this->tree->width(), 'the tree keeps its 12-column floor');

        for ($i = 0; $i < 60; ++$i) {
            $layout->nudgeSidebar(2);
        }
        $this->assertSame(60, $this->tree->width(), 'capped at 60% of a 100-column terminal');
    }

    public function testNudgeGitAndSettingsSidebarsUseTheirOwnClampsAndKeys(): void
    {
        $layout = $this->controller();

        $layout->nudgeGitSidebar(-200);
        $this->assertSame(30, $this->gitSidebar->width(), 'git sidebar floor is 30');

        $layout->nudgeSettingsSidebar(2);
        $file = $this->layoutFile();
        $this->assertSame(30, $file['gitSidebarWidthPercent']);
        $this->assertArrayHasKey('settingsSidebarWidthPercent', $file);
    }

    public function testNudgeBottomPanelIsANoOpWhenNoPanelIsOpen(): void
    {
        $layout = $this->controller();

        $layout->nudgeBottomPanel(1);
        $this->assertSame([], $this->layoutFile(), 'nothing to resize, nothing persisted');
    }
}
