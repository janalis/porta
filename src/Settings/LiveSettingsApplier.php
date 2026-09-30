<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Editor\EditorPanes;
use Porta\Editor\InlayHintMode;
use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\ColorMode;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Layout\ViewRegions;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\CompletionController;
use Porta\Lsp\FormatOnSave;
use Porta\Ui\CursorStyle;
use Porta\Ui\Graphics\PixelRatio;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Theme;
use Porta\Ui\Theme\Themes;
use Porta\Ui\ThemeRefreshable;
use Porta\Widget\Support\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Applies the live-switchable settings: each method swaps the shared holders
 * / re-arranges the tree, then announces the change on the event bus — the
 * menu rebuild and RTL re-mirror listen at priority 0, and the chrome
 * cache sweep listens last (-100), preserving the old method-tail order.
 */
final readonly class LiveSettingsApplier
{
    public function __construct(
        private Styles $styles,
        private StyleSheetThemer $sheetThemer,
        private SwitchableTerminalTheme $syntaxTheme,
        private CursorStyle $cursorStyle,
        private Translator $translator,
        private ViewRegions $regions,
        private EditorPanes $panes,
        private TabBarWidget $tabBar,
        private WorkspaceLayout $workspaceLayout,
        private EventDispatcherInterface $dispatcher,
        private FormatOnSave $formatOnSave,
        private CompletionController $completion,
        private PixelRatio $pixelRatio,
        private bool $ansi,
    ) {}

    /**
     * Live theme switch: swap the shared styles + syntax-theme holders every
     * widget and buffer holds, and re-apply the renderer's stylesheet rules.
     * Chrome re-renders from the listener sweep. The git diff and help viewer
     * cache colored output by content, so they are busted explicitly here (the
     * panes through the registry, so a plugin's pane is swept too);
     * buffer highlighters are busted by {@see \Porta\Highlight\HighlightThemeListener}
     * once the plugin bridge has had its turn.
     */
    public function applyTheme(string $theme): void
    {
        $mode = $this->ansi ? ColorMode::Ansi : ColorMode::Plain;
        $definition = Themes::definition($mode, $theme);
        $this->styles->switchTo($definition); // fragment styling (widgets hold the holder)
        $this->sheetThemer->apply($definition); // widget-level rules on the renderer's sheet
        $this->syntaxTheme->switchTo(TerminalThemeFactory::create($mode, $theme));
        // Buffer highlighters are busted by HighlightThemeListener at priority
        // -200 — after the plugin bridge, so a plugin backend that bakes the
        // theme has rebuilt itself before core drops the caches.
        // Every pane, not a list of the ones that happened to bake codes: a pane
        // that caches rendered lines has to re-make them, and the default is a
        // plain invalidate(). The git diff was named here for exactly that
        // reason and is reached through its region now, so a contributed view's
        // panes rebake on a theme switch without being listed.
        foreach ($this->regions->all() as $region) {
            foreach ([...$region->sidebar()->all(), ...$region->content()->all()] as $widget) {
                if (!$widget instanceof ThemeRefreshable) {
                    continue;
                }

                $widget->refreshTheme();
            }
        }
        foreach ($this->panes->all() as $pane) {
            $pane->refreshTheme();
        }
        $this->dispatcher->dispatch(new ThemeChanged(Theme::fromId($theme)));
    }

    /** Live formatOnSave enable: the flag holder the save path consults. */
    public function enableFormatOnSave(): void
    {
        $this->formatOnSave->enable();
    }

    /** Live formatOnSave disable: the flag holder the save path consults. */
    public function disableFormatOnSave(): void
    {
        $this->formatOnSave->disable();
    }

    /** Live inlay-hint mode switch: none, inline, or a hover card. */
    public function applyInlayHints(string $mode): void
    {
        $this->completion->applyInlayHintMode(InlayHintMode::tryFrom($mode) ?? InlayHintMode::Inline);
    }

    /**
     * Live UI-locale switch: point the translator at $locale and announce the
     * locale it resolved to — `auto` is the system's, never a locale itself.
     */
    public function applyLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->dispatcher->dispatch(new LocaleChanged($this->translator->locale()));
    }

    /** What 100% zoom means on this display: a named pixel ratio, or `auto` to ask the system. */
    public function applyPixelRatio(string $ratio): void
    {
        $this->pixelRatio->apply($ratio);
    }

    public function applyCursorShape(string $shape): void
    {
        $resolved = CursorStyle::shapeFromSetting($shape);
        $this->cursorStyle->switchTo($resolved);
        $this->dispatcher->dispatch(new CursorShapeChanged($resolved));
    }

    /**
     * Move the editor tab strip. Left/right are visual, not writing-direction
     * relative, so the user's explicit choice stays literal.
     *
     * Not because the strip escapes the reorder — it does not. TabBarWidget is
     * a leaf without {@see \Porta\Ui\LtrPane}, so the segmenter gives it a
     * mirrored run like any other chrome. What it sits outside is the LAYOUT
     * mirroring, which reverses pane ORDER; the two are separate mechanisms and
     * the comment here used to conflate them.
     */
    public function applyTabBarPosition(string $value): void
    {
        $position = TabBarPosition::tryFrom($value) ?? TabBarPosition::Top;
        $this->tabBar->setPosition($position);
        $this->workspaceLayout->setTabBarPosition($position);
        $this->dispatcher->dispatch(new TabBarPositionChanged($position));
    }
}
