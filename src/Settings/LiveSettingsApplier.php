<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Editor\BufferManager;
use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Highlight\ColorMode;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\TerminalThemeFactory;
use Porta\I18n\Translator;
use Porta\Layout\WorkspaceLayout;
use Porta\Lsp\FormatOnSave;
use Porta\Ui\CursorStyle;
use Porta\Ui\PaletteFactory;
use Porta\Ui\SwitchablePalette;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\StyleSheetThemer;
use Porta\Ui\Theme\Themes;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\TabBarPosition;
use Porta\Widget\TabBarWidget;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Applies the live-switchable settings: each method swaps the shared holders
 * / re-arranges the tree, then announces the change on the event bus — the
 * menu rebuild and RTL re-mirror listen at priority 0, and the chrome
 * cache sweep listens last (-100), preserving the old method-tail order.
 */
final class LiveSettingsApplier
{
    public function __construct(
        private readonly SwitchablePalette $palette,
        private readonly Styles $styles,
        private readonly StyleSheetThemer $sheetThemer,
        private readonly SwitchableTerminalTheme $syntaxTheme,
        private readonly CursorStyle $cursorStyle,
        private readonly Translator $translator,
        private readonly BufferManager $buffers,
        private readonly DiffViewWidget $gitDiff,
        private readonly HelpViewerWidget $helpViewer,
        private readonly TabBarWidget $tabBar,
        private readonly WorkspaceLayout $workspaceLayout,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly FormatOnSave $formatOnSave,
        private readonly bool $ansi,
    ) {}

    /**
     * Live theme switch: swap the shared palette + syntax-theme holders every
     * widget and buffer holds. Chrome re-renders from the listener sweep;
     * buffers and the git diff cache colored output by content, so those are
     * busted explicitly here.
     */
    public function applyTheme(string $theme): void
    {
        $mode = $this->ansi ? ColorMode::Ansi : ColorMode::Plain;
        $this->palette->switchTo(PaletteFactory::create($mode, $theme));
        $definition = Themes::definition($mode, $theme);
        $this->styles->switchTo($definition); // fragment styling (widgets hold the holder)
        $this->sheetThemer->apply($definition); // widget-level rules on the renderer's sheet
        $this->syntaxTheme->switchTo(TerminalThemeFactory::create($mode, $theme));
        // Existing highlighters baked the old theme at construction, so rebuild
        // them (the diff view re-highlights its shown hunks in the same step);
        // buffers opened later pick up the new theme from the swapped holder.
        foreach ($this->buffers->all() as $buffer) {
            $buffer->highlighter->refreshTheme();
        }
        $this->gitDiff->refreshTheme();
        $this->helpViewer->refreshTheme(); // rendered help lines bake palette codes
        $this->dispatcher->dispatch(new ThemeChanged($theme));
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

    /** Live UI-locale switch: point the translator at $locale and announce it. */
    public function applyLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->dispatcher->dispatch(new LocaleChanged($locale));
    }

    public function applyCursorShape(string $shape): void
    {
        $this->cursorStyle->switchTo(CursorStyle::shapeFromSetting($shape));
        $this->dispatcher->dispatch(new CursorShapeChanged($shape));
    }

    /**
     * Move the editor tab strip. Left/right are visual, not writing-direction
     * relative — the pane sits inside the editor area, outside the RTL pane
     * mirroring, so the user's explicit choice stays literal.
     */
    public function applyTabBarPosition(string $value): void
    {
        $position = TabBarPosition::tryFrom($value) ?? TabBarPosition::Top;
        $this->tabBar->setPosition($position);
        $this->workspaceLayout->setTabBarPosition($position);
        $this->dispatcher->dispatch(new TabBarPositionChanged($position));
    }
}
