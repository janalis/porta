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
    /**
     * Replace everything $owner has said about $uri.
     *
     * The owner is what stops the file's findings being a single slot several
     * things fight over. A language server publishes on every keystroke and an
     * analyser plugin publishes on every save, both for the same file — and
     * before this each wiped the other's, so running phpstan next to a PHP
     * server meant seeing one set or the other depending on which had spoken
     * last. It cannot be fixed by merging non-empty sets either: LSP *requires*
     * an empty list to mean "I have cleared mine", so "nothing to say" and
     * "nothing any more" are the same payload and only the owner tells them
     * apart.
     *
     * Owners are free-form and stable per source: a server's name for LSP, a
     * plugin's name for {@see \Porta\Plugin\Api\PluginApi::setDiagnostics()}.
     *
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine 0-based line => diagnostics
     */
    public function applyDiagnostics(string $uri, array $byLine, string $owner): void;
}
