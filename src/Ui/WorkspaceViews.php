<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Container\AsIdeService;
use Porta\Git\GitViewController;
use Porta\Settings\SettingsViewController;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The view-interlock registry: each {@see FullScreenView} controller attaches
 * itself when constructed, and everything that needs "is a full view open?"
 * asks here instead of holding the controller. Queries answer false until a
 * controller attaches, reproducing the boot-time isset() guards this registry
 * replaces — and breaking the construction cycles between the input stack and
 * the view controllers. Every verb ranges over {@see WorkspaceView}, so a new
 * view is one enum case plus one attach call; only the two view-specific
 * capabilities ({@see openGitFileHistory}, {@see refreshSettings}) hold a
 * concrete controller.
 */
#[AsIdeService]
final class WorkspaceViews
{
    /** @var array<string, FullScreenView> keyed by {@see WorkspaceView}::$name */
    private array $views = [];

    private ?GitViewController $git = null;

    private ?SettingsViewController $settings = null;

    /** Register a view's controller (each attaches itself at construction). */
    public function attach(WorkspaceView $view, FullScreenView $controller): void
    {
        $this->views[$view->name] = $controller;
    }

    /** The git attach keeps its concrete type: {@see openGitFileHistory} needs it. */
    public function attachGit(GitViewController $git): void
    {
        $this->git = $git;
        $this->attach(WorkspaceView::Git, $git);
    }

    /** The settings attach keeps its concrete type: {@see refreshSettings} needs it. */
    public function attachSettings(SettingsViewController $settings): void
    {
        $this->settings = $settings;
        $this->attach(WorkspaceView::Settings, $settings);
    }

    /** Whether every main-area view controller exists yet (the boot-time guard). */
    public function isAttached(): bool
    {
        foreach (WorkspaceView::cases() as $view) {
            if ($view->ownsMainArea() && !isset($this->views[$view->name])) {
                return false;
            }
        }

        return true;
    }

    public function isOpen(WorkspaceView $view): bool
    {
        return ($this->views[$view->name] ?? null)?->isOpen() ?? false;
    }

    /**
     * Whether any full view owns the main area.
     *
     * Callers that only need "is the editor layout detached?" ask this rather
     * than OR-ing the individual views, so adding a view does not mean hunting
     * down every guard that would otherwise have to grow a term. The views
     * that float instead of detaching say so themselves
     * ({@see WorkspaceView::ownsMainArea} — the Welcome modal).
     */
    public function isAnyOpen(): bool
    {
        foreach (WorkspaceView::cases() as $view) {
            if ($view->ownsMainArea() && $this->isOpen($view)) {
                return true;
            }
        }

        return false;
    }

    /** The open view, or null when the editor layout owns the workspace. At most one is ever open. */
    public function activeView(): ?WorkspaceView
    {
        foreach (WorkspaceView::cases() as $view) {
            if ($this->isOpen($view)) {
                return $view;
            }
        }

        return null;
    }

    /** Toggle a view; every other view yields first (one full view at a time). */
    public function toggle(WorkspaceView $view): void
    {
        $this->closeOthers($view);
        ($this->views[$view->name] ?? null)?->toggle();
    }

    /** Open a view (e.g. the Welcome first-run entry point); the others yield first. */
    public function open(WorkspaceView $view): void
    {
        $this->closeOthers($view);
        ($this->views[$view->name] ?? null)?->open();
    }

    /** Close one view alone — e.g. a floating picker opening over Welcome takes the span slot. */
    public function close(WorkspaceView $view): void
    {
        ($this->views[$view->name] ?? null)?->close();
    }

    /** Open the git view on the History tab filtered to one file; the others yield first. */
    public function openGitFileHistory(string $absPath): void
    {
        $this->closeOthers(WorkspaceView::Git);
        $this->git?->openFileHistory($absPath);
    }

    /** Repopulate the settings view's rows (plugin lists, live values). */
    public function refreshSettings(): void
    {
        $this->settings?->refresh();
    }

    /** Close whichever full view is open, returning the main area to the editor layout. */
    public function closeAll(): void
    {
        foreach ($this->views as $view) {
            $view->close();
        }
    }

    private function closeOthers(WorkspaceView $target): void
    {
        foreach ($this->views as $name => $view) {
            if ($name !== $target->name) {
                $view->close();
            }
        }
    }

    /** The open full view's focus target, or null when the editor layout owns the main area. */
    public function focusTarget(): ?AbstractWidget
    {
        $view = $this->activeView();

        return null !== $view ? $this->views[$view->name]->focusTarget() : null;
    }

    /** Cycle focus inside the open full view; false when the editor layout owns the ring. */
    public function cycleFocus(int $direction): bool
    {
        $view = $this->activeView();

        return null !== $view && $this->views[$view->name]->cycleFocus($direction);
    }
}
