<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Git\GitViewController;
use Porta\Settings\SettingsViewController;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The view-interlock registry: the git and settings view controllers register
 * themselves when constructed, and everything that needs "is a full view
 * open?" asks here instead of holding the controller. Queries answer false
 * until a controller attaches, reproducing the boot-time isset() guards this
 * registry replaces — and breaking the construction cycles between the input
 * stack and the view controllers.
 */
final class WorkspaceViews
{
    private ?GitViewController $git = null;

    private ?SettingsViewController $settings = null;

    public function attachGit(GitViewController $git): void
    {
        $this->git = $git;
    }

    public function attachSettings(SettingsViewController $settings): void
    {
        $this->settings = $settings;
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

    /** Repopulate the settings view's rows (plugin lists, live values). */
    public function refreshSettings(): void
    {
        $this->settings?->refresh();
    }

    /** Toggle the git view; the settings view yields first (one full view at a time). */
    public function toggleGit(): void
    {
        $this->settings?->close();
        $this->git?->toggle();
    }

    /** Open the git view on the History tab filtered to one file; settings yields first. */
    public function openGitFileHistory(string $absPath): void
    {
        $this->settings?->close();
        $this->git?->openFileHistory($absPath);
    }

    /** Toggle the settings view; the git view yields first. */
    public function toggleSettings(): void
    {
        $this->git?->close();
        $this->settings?->toggle();
    }

    /** Close whichever full view is open, returning the main area to the editor layout. */
    public function closeAll(): void
    {
        $this->git?->close();
        $this->settings?->close();
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

        return false;
    }
}
