<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Support\DiagnosticLog;
use Porta\Support\OrphanGuard;

/** The production transport: a debug-adapter child process speaking DAP over stdio. */
final readonly class ProcessDapClientFactory
{
    private DiagnosticLog $log;

    public function __construct(
        private string $rootDir,
        private ?OrphanGuard $guard = null,
    ) {
        $this->log = DiagnosticLog::forRoot($this->rootDir, 'dap');
    }

    /** @param list<string> $argv */
    public function create(array $argv): DapClientInterface
    {
        // Adapter stderr and transport failures land in .porta/dap.log — the
        // LSP path surfaces the same seam in its dock panel, but a dead debug
        // adapter would otherwise leave no forensics at all.
        return new DapClient($argv, $this->rootDir, $this->log->append(...), guard: $this->guard);
    }
}
