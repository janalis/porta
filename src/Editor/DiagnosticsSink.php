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
    /** @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine 0-based line => diagnostics */
    public function applyDiagnostics(string $uri, array $byLine): void;
}
