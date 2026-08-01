<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Widget\DebugPanelWidget;
use Porta\Workbench\Dock\DockPanels;
use Porta\Workbench\Dock\DockTab;

/**
 * Registers the single-tab dock panel core ships — the debugger's — into the
 * same registry a contributed panel reaches.
 *
 * The {@see CorePanes} argument, applied to the dock: core registering through
 * the extension point is what keeps the point honest. `BottomDock` held this
 * panel as a constructor field and branched on it in four places; now it holds
 * a registry and the branches are loops, so a contributed panel takes exactly
 * the path the debugger takes.
 *
 * The label is a closure rather than a string because the debug panel retitles
 * itself while a session runs — the strip reads it per frame.
 */
#[AsIdeService]
final class CoreDockPanels
{
    public function __construct(
        private readonly DockPanels $panels,
        private readonly DebugPanelWidget $debugPanel,
        private readonly Translator $translator,
    ) {}

    public function register(): void
    {
        $this->panels->register(
            $this->debugPanel,
            'debug',
            function (): string {
                $title = $this->debugPanel->getTitle();

                return $this->translator->trans('Debug').('' === $title ? '' : ': '.$title);
            },
            DockTab::Debug,
        );
    }
}
