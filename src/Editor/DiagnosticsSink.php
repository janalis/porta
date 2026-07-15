<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Receives published diagnostics for an open file's buffer (inline messages,
 * stripe, Problems panel). Implemented by {@see BufferService}; plugins and
 * the LSP bootstrapper feed it.
 */
interface DiagnosticsSink
{
    /** @param array<int, list<array{severity: int, message: string, col: int, endCol: int}>> $byLine 0-based line => diagnostics */
    public function applyDiagnostics(string $uri, array $byLine): void;
}
