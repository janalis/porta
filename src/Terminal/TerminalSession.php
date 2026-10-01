<?php

declare(strict_types=1);

namespace Porta\Terminal;

/**
 * One terminal tab: a title, its own {@see TerminalBuffer} scrollback, and an
 * optional {@see PtyProcess} (shells and run configurations attach one; the
 * debugger's output session has none — its lines arrive over DAP). The id is
 * the reuse key: `shell:N`, `run:<label>` or `debug`.
 */
final class TerminalSession
{
    /**
     * Where the keys typed into a session WITHOUT a process go — a terminal
     * shown here whose process runs elsewhere, a collaborator's — or null
     * when they go nowhere.
     *
     * @var (\Closure(string): void)|null receives the encoded bytes
     */
    public ?\Closure $input = null;

    public function __construct(
        public readonly string $id,
        public string $title,
        public readonly TerminalBuffer $buffer,
        public private(set) ?PtyProcess $process = null,
    ) {}

    /** Attach a fresh process, stopping any previous one (a re-run replaces it). */
    public function attach(PtyProcess $process): void
    {
        $this->process?->stop();
        $this->process = $process;
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function stop(): void
    {
        $this->process?->stop();
    }
}
