<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferService;
use Porta\Editor\OpenAs;
use Porta\Editor\OpenFocus;
use Porta\Settings\LiveSettingsApplier;
use Porta\Settings\SettingsProvenance;
use Porta\Support\Files;
use Porta\Workbench\ProjectSwitcher;

/**
 * What the window asks of the IDE beyond closing: open what the system handed
 * it, and follow the system's light or dark mode.
 */
#[AsIdeService]
final readonly class DesktopRequests
{
    public function __construct(
        private BufferService $buffers,
        private ProjectSwitcher $projects,
        private LiveSettingsApplier $settings,
        private SettingsProvenance $provenance,
        private string $rootDir,
    ) {}

    /**
     * A file or folder opened with Porta, or dropped on its icon. A file of this
     * project opens here; anything else is another project, switched to through
     * the switcher, so unsaved work and trust are asked about as they always are.
     */
    public function open(string $path): void
    {
        $root = Files::canonical($this->rootDir);
        $target = Files::canonical($path);
        if (\is_file($target) && \str_starts_with($target, $root.'/')) {
            $this->buffers->openFile($target, OpenFocus::Editor, OpenAs::Text);

            return;
        }
        $this->projects->openPath(\is_file($target) ? \dirname($target) : $path);
    }

    /**
     * The system went light or dark. Followed only while nobody chose a theme:
     * a theme picked in Settings, for this project or all of them, stays.
     */
    public function followAppearance(string $mode): void
    {
        if (null === $this->provenance->originOf(['settings', 'theme'])) {
            $this->settings->applyTheme('light' === $mode ? 'light' : 'dark');
        }
    }
}
