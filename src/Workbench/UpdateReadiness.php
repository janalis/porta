<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Debug\DebugController;
use Porta\Filesystem\ProjectFilesystem;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Tool\RunningProcesses;
use Porta\Terminal\TerminalSessions;

/** A terminal with a live shell is conservatively busy until it closes. */
#[AsIdeService]
final readonly class UpdateReadiness
{
    public function __construct(
        private TerminalSessions $terminals,
        private RunningProcesses $processes,
        private PluginInstaller $installer,
        private ProjectFilesystem $files,
        private DebugController $debug,
    ) {}

    public function busy(): bool
    {
        return (
            $this->debug->isActive()
            || $this->processes->busy()
            || $this->installer->busy()
            || 0 < $this->files->pending()
            || null !== \array_find($this->terminals->sessions, static fn ($session): bool => $session->isRunning())
        );
    }
}
