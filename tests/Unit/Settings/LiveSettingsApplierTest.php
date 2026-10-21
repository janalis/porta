<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\KeymapPlatform;
use Porta\Config\LiveKeybindings;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\EditorPanes;
use Porta\Editor\InlayHintMode;
use Porta\Event\KeymapChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\SoftWrapChanged;
use Porta\Highlight\ColorMode;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Layout\ViewRegions;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Settings\LiveSettingsApplier;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestCompletionController;
use Porta\Tests\Support\TestLiveKeybindings;
use Porta\Tests\Support\TestTui;
use Porta\Tests\Support\TestWorkspaceLayout;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;

/**
 * The small live-switch pass-throughs {@see ThemeRefreshSweepTest} doesn't
 * cover: format-on-save, locale, cursor shape, tab-bar position, soft wrap.
 */
final class LiveSettingsApplierTest extends TestCase
{
    private FormatOnSave $formatOnSave;

    private function applier(
        ?Translator $translator = null,
        ?CursorStyle $cursorStyle = null,
        ?TabBarWidget $tabBar = null,
        CodeEditorWidget $editor = new CodeEditorWidget(),
        EventDispatcher $dispatcher = new EventDispatcher(),
        ?LiveKeybindings $keys = null,
    ): LiveSettingsApplier {
        $this->formatOnSave = self::buildFormatOnSave();

        return new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            $cursorStyle ?? new CursorStyle(),
            $translator ?? new Translator('en'),
            new ViewRegions(Styles::dark()),
            new EditorPanes(),
            $tabBar ?? new TabBarWidget(),
            TestWorkspaceLayout::create(rootDir: \sys_get_temp_dir()),
            $dispatcher,
            $this->formatOnSave,
            TestCompletionController::idle($editor),
            PixelGraphics::ratio(),
            $keys ?? TestLiveKeybindings::create(),
            $editor->display,
            true,
        );
    }

    private static function buildFormatOnSave(): FormatOnSave
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'));

        return new FormatOnSave(
            $pool,
            $editor,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new Translator('en'),
            new FakeScheduler(),
            TestTui::create(80, 24),
        );
    }

    public function testEnableFormatOnSaveFlipsTheHolder(): void
    {
        $applier = $this->applier();

        $applier->enableFormatOnSave();

        $this->assertTrue($this->formatOnSave->isEnabled());
    }

    public function testDisableFormatOnSaveFlipsTheHolderBack(): void
    {
        $applier = $this->applier();
        $applier->enableFormatOnSave();

        $applier->disableFormatOnSave();

        $this->assertFalse($this->formatOnSave->isEnabled());
    }

    public function testApplyKeymapPlatformSwitchesTheLiveMapAndSaysSo(): void
    {
        $dispatcher = new EventDispatcher();
        $changes = [];
        $dispatcher->addListener(KeymapChanged::class, static function (KeymapChanged $event) use (&$changes): void {
            $changes[] = $event->platform;
        });
        $keys = TestLiveKeybindings::create();
        $applier = $this->applier(dispatcher: $dispatcher, keys: $keys);

        $applier->applyKeymapPlatform('macos');

        $this->assertSame(['backspace', 'delete'], $keys->getBindings('reset_binding'));
        $this->assertSame([KeymapPlatform::MacOs], $changes, 'the menus rebuild their labels on this');
    }

    public function testApplySoftWrapWrapsTheEditorAndSaysSoOnlyOnARealChange(): void
    {
        $dispatcher = new EventDispatcher();
        $changes = [];
        $dispatcher->addListener(SoftWrapChanged::class, static function (SoftWrapChanged $event) use (&$changes): void {
            $changes[] = $event->on;
        });
        $editor = new CodeEditorWidget();
        $applier = $this->applier(editor: $editor, dispatcher: $dispatcher);

        $applier->applySoftWrap(false);
        $this->assertSame([], $changes, 'a boot at the default says nothing');

        $applier->applySoftWrap(true);
        $applier->applySoftWrap(true);
        $this->assertTrue($editor->display->softWrap);
        $this->assertSame([true], $changes, 'the diff panes re-wrap on this, once');

        $applier->applySoftWrap(false);
        $this->assertFalse($editor->display->softWrap);
        $this->assertSame([true, false], $changes);
    }

    public function testApplyInlayHintsSwitchesTheEditorsMode(): void
    {
        $editor = new CodeEditorWidget();
        $applier = $this->applier(editor: $editor);

        $applier->applyInlayHints('tooltip');
        $this->assertSame(InlayHintMode::Tooltip, $editor->display->inlayHints);

        $applier->applyInlayHints('none');
        $this->assertSame(InlayHintMode::None, $editor->display->inlayHints);

        $applier->applyInlayHints('sideways');
        $this->assertSame(InlayHintMode::Inline, $editor->display->inlayHints, 'a value outside the options falls back to the default');
    }

    public function testApplyLocaleSwitchesTheTranslator(): void
    {
        $translator = new Translator('en');
        $applier = $this->applier(translator: $translator);

        $applier->applyLocale('fr');

        $this->assertSame('fr', $translator->locale());
    }

    public function testApplyAutoLocaleAnnouncesTheSystemLocaleItResolvedTo(): void
    {
        $translator = new Translator('en', systemLocale: 'de');
        $dispatcher = new EventDispatcher();
        $announced = [];
        $dispatcher->addListener(LocaleChanged::class, static function (LocaleChanged $e) use (&$announced): void {
            $announced[] = $e->locale;
        });
        $applier = $this->applier(translator: $translator, dispatcher: $dispatcher);

        $applier->applyLocale('auto');

        $this->assertSame('de', $translator->locale());
        $this->assertSame(['de'], $announced, 'listeners hear a locale, never `auto`');
    }

    public function testApplyCursorShapeSwitchesTheHolder(): void
    {
        $cursorStyle = new CursorStyle();
        $before = $cursorStyle->marker();
        $applier = $this->applier(cursorStyle: $cursorStyle);

        $applier->applyCursorShape('block');

        $this->assertNotSame($before, $cursorStyle->marker(), 'the bar-shaped default marker changed');
    }

    public function testApplyTabBarPositionMovesBothTheWidgetFlagAndTheLayoutOrder(): void
    {
        $this->formatOnSave = self::buildFormatOnSave();
        $tabBar = new TabBarWidget();
        $workspaceLayout = TestWorkspaceLayout::create(rootDir: \sys_get_temp_dir(), tabBar: $tabBar);
        $applier = new LiveSettingsApplier(
            Themes::createSwitchable(ColorMode::Ansi, 'dark'),
            new StyleSheetThemer(new Renderer(new StyleSheet())),
            TerminalThemeFactory::createSwitchable(ColorMode::Ansi, 'dark'),
            new CursorStyle(),
            new Translator('en'),
            new ViewRegions(Styles::dark()),
            new EditorPanes(),
            $tabBar,
            $workspaceLayout,
            new EventDispatcher(),
            $this->formatOnSave,
            TestCompletionController::idle(),
            PixelGraphics::ratio(),
            TestLiveKeybindings::create(),
            new EditorDisplayOptions(static function (): void {}),
            true,
        );
        $editorPane = Probe::get($workspaceLayout, 'editorPane');
        $editorBody = Probe::get($workspaceLayout, 'editorBody');
        // Top (the default) is tabs-first; confirm the fixture starts there
        // so "moved to bottom" is an observable, not a coincidental no-op.
        $this->assertSame($tabBar, $editorPane->all()[0], 'starts tabs-first (top)');

        $applier->applyTabBarPosition('bottom');

        $this->assertSame(TabBarPosition::Bottom, $tabBar->position(), 'the widget-level flag moved');
        $this->assertSame($editorBody, $editorPane->all()[0], 'the layout re-seated the tab strip after the editor body');
    }
}
