<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Container\AsIdeService;
use Porta\Event\SettingsRowsStale;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsController;
use Porta\Settings\SettingsProvenance;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What the window asks of the IDE beyond closing: open what the system handed
 * it, and follow the system's light or dark mode.
 */
#[AsIdeService]
final class DesktopRequests
{
    /** The look the window last reported, starting as the one it launched with; null when it said none. */
    private ?string $followed;

    public function __construct(
        private readonly ExternalDrop $drops,
        private readonly LiveSettingsApplier $settings,
        private readonly SettingsProvenance $provenance,
        private readonly SettingsController $controller,
        private readonly EventDispatcherInterface $events,
        DesktopSession $session,
    ) {
        // The report every window sends on ready repeats the launch's look: no change.
        $this->followed = $session->appearance;
    }

    /**
     * A file or folder opened with Porta, or dropped on its window or icon. The
     * window sends only what lies in this project, so it is handled as a drop
     * on an empty editor: a file opens, a folder is revealed in the tree.
     */
    public function open(string $path): void
    {
        $this->drops->drop([$path], DropZone::Empty);
    }

    /**
     * The system went light or dark. Followed only while nobody chose a theme:
     * a theme picked in Settings, for this project or all of them, stays.
     */
    public function followAppearance(string $mode): void
    {
        $theme = 'light' === $mode ? 'light' : 'dark';
        $moved = $theme !== $this->followed;
        $this->followed = $theme;
        // A Porta started from this one (a remote project in this terminal)
        // inherits the process environment: it starts as the window is now.
        \putenv(DesktopSession::APPEARANCE.'='.$theme);
        // Read afresh: a theme may have been chosen in another window, or by hand.
        $this->provenance->invalidate();
        // What Settings shows for a theme no file chose, and resets to, moves too.
        $this->controller->followTheme($theme);
        if ($this->controller->followsWindow()) {
            $this->settings->applyTheme($theme);
        }
        // An open Settings page reads its rows again when the value, or only
        // its default, moved: a report of the same look again changes nothing.
        if ($moved) {
            $this->events->dispatch(new SettingsRowsStale());
        }
    }

    /** The look the window last reported, for the next project in this window to start in; null when it reported none. */
    public function followed(): ?string
    {
        return $this->followed;
    }
}
