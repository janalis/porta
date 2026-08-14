<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\DiagnosticLog;

/** The production transport: a debug-adapter child process speaking DAP over stdio. */
final class ProcessDapClientFactory implements DapClientFactory
{
    private readonly DiagnosticLog $log;

    public function __construct(
        private readonly string $rootDir,
    ) {
        $this->log = new DiagnosticLog($this->rootDir.'/.porta/dap.log');
    }

    #[\Override]
    public function create(array $argv): DapClientInterface
    {
        // Adapter stderr and transport failures land in .porta/dap.log — the
        // LSP path surfaces the same seam in its dock panel, but a dead debug
        // adapter would otherwise leave no forensics at all.
        return new DapClient($argv, $this->rootDir, $this->log->append(...));
    }
}
