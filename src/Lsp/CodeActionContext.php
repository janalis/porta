<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\DiagnosticSeverity;

/**
 * What a `textDocument/codeAction` request says about the problems it is
 * asked about, and how its answers are read — shared by the Alt+Enter list,
 * the problem card's "Fix:" line and Alt+Shift+Enter, so the line names
 * exactly what the key applies.
 */
final class CodeActionContext
{
    /**
     * A diagnostic in LSP shape. `code`, `source`, `data` and `tags` ride along
     * when the server gave them: a server that matches its own fix by code
     * sees nothing to match without them, and the context is meant to hand
     * back the diagnostic it published, not a summary of it.
     *
     * The range is the one thing that is NOT handed back whole: a range that
     * ended on its own line keeps its real end, but a multi-line one was filed
     * per line by {@see LspMapper::diagnosticsByLine()} without its end, so it
     * goes back as start..start+1 on its first line. `data` is what a server
     * should match on.
     *
     * @param array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed} $diag
     *
     * @return array<string, mixed>
     */
    public static function lspDiagnostic(int $line, array $diag): array
    {
        $endCol = $diag['endCol'] ?? \PHP_INT_MAX;
        $endCol = \PHP_INT_MAX === $endCol ? $diag['col'] + 1 : $endCol;
        $lspDiag = [
            'range' => [
                'start' => ['line' => $line, 'character' => $diag['col']],
                'end' => ['line' => $line, 'character' => $endCol],
            ],
            'severity' => $diag['severity']->value,
            'message' => $diag['message'],
        ];
        foreach (['code', 'source', 'data', 'tags'] as $key) {
            /** @var mixed $extra */
            $extra = $diag[$key] ?? null;
            if (null !== $extra) {
                $lspDiag[$key] = $extra;
            }
        }

        return $lspDiag;
    }

    /**
     * The server's preferred action first, everything else in the order it sent.
     * `usort` is stable, so this only lifts the preferred one.
     *
     * @param list<array<string, mixed>> $actions
     *
     * @return list<array<string, mixed>>
     */
    public static function preferredFirst(array $actions): array
    {
        \usort($actions, static fn (array $a, array $b): int => (true === ($b['isPreferred'] ?? null) ? 1 : 0) <=> (true === ($a['isPreferred'] ?? null) ? 1 : 0));

        return $actions;
    }

    /**
     * The fix PhpStorm's Alt+Shift+Enter applies: the server's preferred
     * action, otherwise the first quick fix it can run — never a refactoring
     * or a source action, which change more than the problem, and never one
     * it says is disabled. An action without a kind is taken for a fix: older
     * servers send none.
     *
     * @param list<array<string, mixed>> $actions
     *
     * @return array<string, mixed>|null
     */
    public static function firstQuickFix(array $actions): ?array
    {
        foreach (self::preferredFirst($actions) as $action) {
            $kind = (string) ($action['kind'] ?? '');
            if (!\is_array($action['disabled'] ?? null) && ('' === $kind || 'quickfix' === $kind || \str_starts_with($kind, 'quickfix.'))) {
                return $action;
            }
        }

        return null;
    }
}
