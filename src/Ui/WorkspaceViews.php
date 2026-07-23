<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Git\GitViewController;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Settings\SettingsViewController;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The view-interlock registry: the git and settings view controllers (and the
 * Welcome modal controller) register themselves when constructed, and
 * everything that needs "is a full view open?" asks here instead of holding
 * the controller. Queries answer false until a controller attaches,
 * reproducing the boot-time isset() guards this registry replaces — and
 * breaking the construction cycles between the input stack and the view
 * controllers. The Welcome surface is a floating modal, not a main-area view,
 * but it keeps the same interlock: opening any surface closes the others.
 */
final class WorkspaceViews
{
    private ?GitViewController $git = null;

    private ?SettingsViewController $settings = null;

    private ?WelcomeModalController $welcome = null;

    public function attachGit(GitViewController $git): void
    {
        $this->git = $git;
    }

    public function attachSettings(SettingsViewController $settings): void
    {
        $this->settings = $settings;
    }

    public function attachWelcome(WelcomeModalController $welcome): void
    {
        $this->welcome = $welcome;
    }

    /** Whether both view controllers exist yet (the boot-time guard). */
    public function isAttached(): bool
    {
        return null !== $this->git && null !== $this->settings;
    }

    public function isGitOpen(): bool
    {
        return $this->git?->isOpen() ?? false;
    }

    public function isSettingsOpen(): bool
    {
        return $this->settings?->isOpen() ?? false;
    }

    public function isWelcomeOpen(): bool
    {
        return $this->welcome?->isOpen() ?? false;
    }

    /**
     * Whether any full view owns the main area.
     *
     * Callers that only need "is the editor layout detached?" ask this rather
     * than OR-ing the individual views, so adding a view does not mean hunting
     * down every guard that would otherwise have to grow a term. The Welcome
     * modal is deliberately NOT a term: it floats over the editor layout, which
     * stays mounted (rects live, panes composited) behind it.
     */
    public function isAnyOpen(): bool
    {
        return $this->isGitOpen() || $this->isSettingsOpen();
    }

    /** Repopulate the settings view's rows (plugin lists, live values). */
    public function refreshSettings(): void
    {
        $this->settings?->refresh();
    }

    /** Toggle the git view; the settings view yields first (one full view at a time). */
    public function toggleGit(): void
    {
        $this->settings?->close();
        $this->welcome?->close();
        $this->git?->toggle();
    }

    /** Open the git view on the History tab filtered to one file; settings yields first. */
    public function openGitFileHistory(string $absPath): void
    {
        $this->settings?->close();
        $this->welcome?->close();
        $this->git?->openFileHistory($absPath);
    }

    /** Toggle the settings view; the git view yields first. */
    public function toggleSettings(): void
    {
        $this->git?->close();
        $this->welcome?->close();
        $this->settings?->toggle();
    }

    /** Toggle the Welcome view; the other two yield first. */
    public function toggleWelcome(): void
    {
        $this->git?->close();
        $this->settings?->close();
        $this->welcome?->toggle();
    }

    /** Open the Welcome view (the first-run entry point); the other two yield first. */
    public function openWelcome(): void
    {
        $this->git?->close();
        $this->settings?->close();
        $this->welcome?->open();
    }

    /** Close the Welcome modal alone — a floating picker opening over it takes the span slot. */
    public function closeWelcome(): void
    {
        $this->welcome?->close();
    }

    /** Close whichever full view is open, returning the main area to the editor layout. */
    public function closeAll(): void
    {
        $this->git?->close();
        $this->settings?->close();
        $this->welcome?->close();
    }

    /** The open full view's focus target, or null when the editor layout owns the main area. */
    public function focusTarget(): ?AbstractWidget
    {
        if ($this->isSettingsOpen()) {
            return $this->settings?->focusTarget();
        }
        if ($this->isGitOpen()) {
            return $this->git?->focusTarget();
        }
        if ($this->isWelcomeOpen()) {
            return $this->welcome?->focusTarget();
        }

        return null;
    }

    /** Cycle focus inside the open full view; false when the editor layout owns the ring. */
    public function cycleFocus(int $direction): bool
    {
        if ($this->isSettingsOpen()) {
            $this->settings?->cycleFocus();

            return true;
        }
        if ($this->isGitOpen()) {
            $direction > 0 ? $this->git?->focusNext() : $this->git?->focusPrevious();

            return true;
        }

        // The Welcome modal needs no branch: while it is open the dispatcher's
        // modal branch swallows every key before the focus ring can move.
        return false;
    }
}
