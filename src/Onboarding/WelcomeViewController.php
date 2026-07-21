<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Layout\EditorAreaPanes;
use Porta\Ui\EditorArea;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The Welcome screen as a full view: it swaps the main area's tree|editor
 * children for the step list, and restores them on close.
 *
 * Structured like {@see \Porta\Settings\SettingsViewController} but simpler — a
 * single pane, so there is no sidebar, no separator and nothing to re-order for
 * RTL. That is why it adds its widget straight into `main` instead of owning an
 * area container: with one child there is nothing for {@see \Porta\Layout\PaneLayout}
 * to arrange.
 *
 * Like the other view controllers it attaches itself in its constructor and has
 * no inbound service references, so {@see \Porta\Ide} resolves it eagerly for
 * that side effect alone.
 */
final class WelcomeViewController
{
    private bool $open = false;

    public function __construct(
        private readonly WelcomeWidget $welcome,
        private readonly WelcomeSteps $steps,
        private readonly ContainerWidget $main,
        private readonly MenuBarWidget $menuBar,
        private readonly EditorAreaPanes $editArea,
        private readonly Tui $tui,
        private readonly EditorArea $editorArea,
        WorkspaceViews $views,
    ) {
        $views->attachWelcome($this);

        $this->welcome
            ->onExit($this->close(...))
            ->onRan($this->refresh(...));
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return $this->welcome;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->open = true;
        // The menu bar stays put; drop any open dropdown so its tracked hit
        // region cannot go stale across the swap.
        $this->menuBar->close();
        $this->main->clear();
        $this->main->add($this->welcome);

        $this->refresh();
        $this->tui->setFocus($this->welcome);
        $this->tui->requestRender();
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->menuBar->close();
        $this->main->clear();
        foreach ($this->editArea->orderedEditorPanes() as $widget) {
            $this->main->add($widget);
        }
        $this->tui->setFocus($this->editorArea->widget());
        $this->tui->requestRender();
    }

    /** Rebuild the steps so one just completed reads as done. */
    public function refresh(): void
    {
        $this->welcome->setSteps($this->steps->all());
        $this->tui->requestRender();
    }
}
