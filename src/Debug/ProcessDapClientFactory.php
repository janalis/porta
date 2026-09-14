<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\DiagnosticLog;

/** The production transport: a debug-adapter child process speaking DAP over stdio. */
final readonly class ProcessDapClientFactory implements DapClientFactory
{
    private DiagnosticLog $log;

    public function __construct(
        private string $rootDir,
    ) {
        $this->log = DiagnosticLog::forRoot($this->rootDir, 'dap');
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
