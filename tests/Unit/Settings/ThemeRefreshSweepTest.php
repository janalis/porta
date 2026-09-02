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
use Porta\Ui\Role;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Ui\ThemeRefreshable;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Tempest\Highlight\Tokens\TokenTypeEnum;

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
            new WorkspaceEditor(new BufferManager(), $editor, $pool, new EventDispatcher()),
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

    public function testTheThreeThemeHoldersAllSwitchToTheNewDefinition(): void
    {
        $styles = Themes::createSwitchable(ColorMode::Ansi, 'dark');
        $renderer = new Renderer(new StyleSheet());
        $syntaxTheme = TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark');
        $applier = new LiveSettingsApplier(
            $styles,
            new StyleSheetThemer($renderer),
            $syntaxTheme,
            new CursorStyle(),
            new Translator('en'),
            new ViewRegions(Styles::dark()),
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: sys_get_temp_dir()),
            new EventDispatcher(),
            self::formatOnSave(),
            true,
        );

        $applier->applyTheme('light');

        $this->assertSame(
            Themes::definition(ColorMode::Ansi, 'light')->of(Role::Gutter)->apply('x'),
            $styles->of(Role::Gutter)->apply('x'),
            'the shared Styles holder switched to the light definition',
        );
        $this->assertSame(
            Themes::definition(ColorMode::Ansi, 'light')->of(Role::Gutter)->apply('x'),
            $renderer->getStyleSheet()->getRules()[Role::Gutter->selector()]->apply('x'),
            'the renderer\'s live stylesheet carries the light theme\'s rule',
        );
        $this->assertSame(
            TerminalThemeFactory::create(ColorMode::Ansi, 'light')->before(TokenTypeEnum::KEYWORD),
            $syntaxTheme->before(TokenTypeEnum::KEYWORD),
            'the syntax-theme holder switched to the light terminal theme',
        );
    }

    public function testAnAnsiFalseIdeSelectsThePlainColorModeRegardlessOfTheThemeId(): void
    {
        $styles = Themes::createSwitchable(ColorMode::Ansi, 'dark');
        $applier = new LiveSettingsApplier(
            $styles,
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            new Translator('en'),
            new ViewRegions(Styles::dark()),
            new EditorPanes(),
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: sys_get_temp_dir()),
            new EventDispatcher(),
            self::formatOnSave(),
            false, // ansi disabled
        );

        $applier->applyTheme('light');

        $this->assertSame(
            MonochromeTheme::definition()->of(Role::Gutter)->apply('x'),
            $styles->of(Role::Gutter)->apply('x'),
            'a non-ANSI IDE always gets the monochrome definition, never a colored theme',
        );
    }

    public function testEveryWidgetInAMultiWidgetSidebarOrContentIsSweptNotJustTheFirst(): void
    {
        // A single-widget sidebar/content list cannot distinguish "spread every
        // item" from "take only the first" — both produce the same one call.
        $sidebarFlags = [false, false];
        $contentFlags = [false, false];
        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('git');
        $region->sidebar()->add($this->widget($sidebarFlags[0]));
        $region->sidebar()->add($this->widget($sidebarFlags[1]));
        $region->content()->add($this->widget($contentFlags[0]));
        $region->content()->add($this->widget($contentFlags[1]));

        $this->applier($regions)->applyTheme('light');

        $this->assertTrue($sidebarFlags[0] && $sidebarFlags[1], 'both sidebar widgets rebaked');
        $this->assertTrue($contentFlags[0] && $contentFlags[1], 'both content widgets rebaked');
    }

    public function testEveryRegisteredEditorPaneIsRefreshedOnAThemeSwitch(): void
    {
        $refreshed = false;
        $panes = new EditorPanes();
        $panes->register(
            new class($refreshed) extends EditorPaneWidget {
                public function __construct(
                    private bool &$refreshed,
                ) {}

                #[\Override]
                public function paneId(): string
                {
                    return 'test-pane';
                }

                #[\Override]
                public function show(string $path, mixed $payload): void {}

                #[\Override]
                public function clear(): void {}

                #[\Override]
                public function statusRight(): string
                {
                    return '';
                }

                #[\Override]
                public function refreshTheme(): void
                {
                    $this->refreshed = true;
                }

                #[\Override]
                public function render(RenderContext $context): array
                {
                    return [];
                }

                #[\Override]
                public function handleInput(string $data): void {}
            },
            static fn (string $path): mixed => null,
        );

        $applier = new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            new Translator('en'),
            new ViewRegions(Styles::dark()),
            $panes,
            new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: sys_get_temp_dir()),
            new EventDispatcher(),
            self::formatOnSave(),
            true,
        );

        $applier->applyTheme('light');

        $this->assertTrue($refreshed, 'a registered editor pane is swept alongside the view regions');
    }
}
