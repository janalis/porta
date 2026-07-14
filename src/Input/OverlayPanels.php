<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\DebugPanelWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;

/**
 * The floating panels {@see MouseRouter} tries for wheel, click and hover
 * routing: the integrated terminal, the search-results panel, the debug panel
 * and the go-to-file palette. Bundled so the router takes one argument.
 */
final readonly class OverlayPanels
{
    public function __construct(
        private TerminalPanelWidget $terminalPanel,
        private ResultsPanelWidget $resultsPanel,
        private GoToFileWidget $goToFile,
        private DebugPanelWidget $debugPanel,
    ) {}

    public function debugPanel(): DebugPanelWidget
    {
        return $this->debugPanel;
    }

    public function terminalPanel(): TerminalPanelWidget
    {
        return $this->terminalPanel;
    }

    public function resultsPanel(): ResultsPanelWidget
    {
        return $this->resultsPanel;
    }

    public function goToFile(): GoToFileWidget
    {
        return $this->goToFile;
    }
}
