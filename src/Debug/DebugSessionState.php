<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The paused-location holder shared between the debug session (writer) and
 * the editor decorations (reader). A holder instead of a DebugController
 * dependency so a buffer switch can re-apply the stopped-line band without
 * the buffer stack depending on the debugger orchestration (and vice versa).
 */
final class DebugSessionState
{
    private ?string $stoppedPath = null;

    private ?int $stoppedLine = null;

    public function stopAt(string $path, int $line): void
    {
        $this->stoppedPath = $path;
        $this->stoppedLine = $line;
    }

    public function clear(): void
    {
        $this->stoppedPath = null;
        $this->stoppedLine = null;
    }

    public function stoppedPath(): ?string
    {
        return $this->stoppedPath;
    }

    public function stoppedLine(): ?int
    {
        return $this->stoppedLine;
    }

    /** The stopped line when the debuggee is paused in $path, else null. */
    public function stoppedLineFor(string $path): ?int
    {
        return $path === $this->stoppedPath ? $this->stoppedLine : null;
    }
}
