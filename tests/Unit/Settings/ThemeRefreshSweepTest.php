<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Highlight\ColorMode;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Layout\ViewRegions;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Settings\LiveSettingsApplier;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Ui\ThemeRefreshable;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A theme switch reaches a contributed view's panes, whoever contributed them.
 *
 * This used to be one named class: the applier held a `DiffViewWidget` and
 * called `refreshTheme()` on it, because that widget bakes ANSI codes into
 * cached rows and a plain `invalidate()` repaints them in the old colours. The
 * git view is a plugin's now, and core cannot name its widgets — so the sweep
 * walks every minted region and asks whatever implements
 * {@see ThemeRefreshable}.
 *
 * The failure it guards is invisible in a unit run and obvious on screen: the
 * theme changes everywhere except inside the open view, which keeps painting
 * the previous palette until something else invalidates it.
 */
final class ThemeRefreshSweepTest extends TestCase
{
    private function widget(bool &$refreshed): AbstractWidget
    {
        return new class($refreshed) extends AbstractWidget implements ThemeRefreshable {
            public function __construct(
                private bool &$refreshed,
            ) {}

            public function refreshTheme(): void
            {
                $this->refreshed = true;
            }

            public function render(RenderContext $context): array
            {
                return [];
            }
        };
    }

    private function applier(ViewRegions $regions): LiveSettingsApplier
    {
        return new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            new Translator('en'),
            $regions,
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: sys_get_temp_dir()),
            new EventDispatcher(),
            self::formatOnSave(),
            true,
        );
    }

    private static function formatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            new WorkspaceEditor(new BufferManager(), $editor, $pool),
            $editor,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            new FakeScheduler(),
            TestTui::create(80, 24),
        );
    }

    public function testBothHalvesOfEveryRegionAreSwept(): void
    {
        // Sidebar AND content: the git view bakes codes in its diff pane (the
        // content half) and its history list (the sidebar half), and a sweep
        // that walked one would leave the other stale.
        $sidebar = false;
        $content = false;
        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('git');
        $region->sidebar()->add($this->widget($sidebar));
        $region->content()->add($this->widget($content));

        $this->applier($regions)->applyTheme('light');

        $this->assertTrue($sidebar, 'the sidebar pane rebaked');
        $this->assertTrue($content, 'and so did the content pane');
    }

    public function testAWidgetThatDoesNotBakeColoursIsLeftAlone(): void
    {
        // The interface is the opt-in, not the region: most widgets hold the
        // Styles holder and repaint correctly from a plain invalidate, so
        // calling a refresh on all of them would be work for nothing.
        $refreshed = false;
        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('settings');
        $region->sidebar()->add(new class extends AbstractWidget {
            public function render(RenderContext $context): array
            {
                return [];
            }
        });
        $region->content()->add($this->widget($refreshed));

        $this->applier($regions)->applyTheme('light');

        $this->assertTrue($refreshed, 'the one that opted in still ran');
    }

    public function testNoRegionsMeansNoSweepAndNoThrow(): void
    {
        // The state at boot and in a project where no view has ever opened —
        // and, now that both remaining views can come from plugins, the state
        // of a whole session with none installed.
        $this->applier(new ViewRegions(Styles::dark()))->applyTheme('light');

        $this->addToAssertionCount(1);
    }
}
