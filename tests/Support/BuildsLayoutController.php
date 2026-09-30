<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Config\LayoutStore;
use Porta\GitPlugin\View\GitSidebar;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Layout\LayoutController;
use Porta\Layout\PreviewSplit;
use Porta\Layout\ResizableSidebar;
use Porta\Layout\ViewRegions;
use Porta\Ui\Theme\Styles;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A real LayoutController over a real widget tree, with the pieces a resize
 * test has to reach afterwards left on the class.
 *
 * It is a whole tree rather than doubles because what these tests grade is
 * arithmetic ACROSS widgets — a sidebar percent that has to land on the file
 * tree's width and come back out of `.porta/layout.json` — and a double that
 * agrees with the arithmetic proves nothing about it.
 *
 * `$locale` is a parameter because RTL flips which edge a separator is dragged
 * from; `$columns` because the clamps are the point of half the cases.
 */
trait BuildsLayoutController
{
    private TempProject $project;
    private FileTreeWidget $tree;
    private GitSidebar $gitSidebar;
    private SettingsNavWidget $settingsSidebar;
    private Tui $tui;
    private Renderer $renderer;
    private ViewRegions $regions;
    private PreviewSplit $previewSplit;

    private function controller(string $locale = 'en', int $columns = 100): LayoutController
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: $columns, rows: 30), keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->tree = new FileTreeWidget($this->project->dir);
        $this->gitSidebar = new GitSidebar(
            new GitTabsWidget(new Translator('en')),
            new ChangesListWidget(new Translator('en')),
            new CommitBoxWidget(new Translator('en')),
            new HistoryListWidget(new Translator('en')),
        );
        $this->settingsSidebar = new SettingsNavWidget(new Translator('en'));
        $this->regions = self::regionsWith($this->gitSidebar, $this->settingsSidebar);

        return new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $this->renderer,
            $this->tree,
            TestBottomDock::create($this->tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            new TabBarWidget(),
            $this->regions,
            new Translator($locale),
            $this->previewSplit = new PreviewSplit(Styles::dark(), renderer: $this->renderer, tui: $this->tui),
        );
    }

    /**
     * The persisted layout, or an empty array when nothing was written.
     *
     * Reading it back is half of what these tests assert: a percent that
     * changed the tree's width and did NOT reach disk is the defect this
     * catches, and an empty array is a real expected value — hence no null.
     *
     * @return array<string, int|bool> the persisted layout.json contents
     */
    private function layoutFile(): array
    {
        $file = $this->project->dir.'/.porta/layout.json';
        if (!\is_file($file)) {
            return [];
        }

        /** @var array<string, int|bool> */
        return \json_decode((string) \file_get_contents($file), associative: true);
    }

    /**
     * The two regions, each already told how its sidebar resizes — which is
     * what each view does when it mints its own.
     */
    private static function regionsWith(ResizableSidebar $git, ResizableSidebar $settings): ViewRegions
    {
        $regions = new ViewRegions(Styles::dark());
        $regions->mint('git')->setSidebarPanes($git);
        $regions->mint('settings')->setSidebarPanes($settings);

        return $regions;
    }
}
