<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Container\AsIdeService;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsProvenance;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;

/**
 * What the window asks of the IDE beyond closing: open what the system handed
 * it, and follow the system's light or dark mode.
 */
#[AsIdeService]
final readonly class DesktopRequests
{
    public function __construct(
        private ExternalDrop $drops,
        private LiveSettingsApplier $settings,
        private SettingsProvenance $provenance,
    ) {}

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
        // Read afresh: a theme may have been chosen in another window, or by hand.
        $this->provenance->invalidate();
        if (null === $this->provenance->originOf(['settings', 'theme'])) {
            $this->settings->applyTheme('light' === $mode ? 'light' : 'dark');
        }
    }
}
