<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * Psalm integration: "Analyse Current File" (Ctrl+Alt+P) and a persisted
 * "Analyse on save" setting (toggled from the palette or the plugin's settings
 * page). Issues are published as editor diagnostics — inline messages,
 * gutter/stripe colors and the Problems panel (Alt+6), exactly like LSP
 * diagnostics — and a clean run clears them. The run, the binary and the
 * single-flight slot come from {@see AbstractAnalyzerPlugin}; what is left
 * here is psalm's own truth: its argv, that it exits 0 (clean) or 2 (issues
 * found) with a report either way, and its flat issue list, whose lines AND
 * columns are 1-based and whose severity is a word. Note: an LSP server
 * publishing diagnostics for the same file replaces these (and vice versa) —
 * last writer wins.
 */
return new class('psalm', 'psalm') extends AbstractAnalyzerPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'vimeo/psalm']]);
        $this->registerAnalysis($api, 'Psalm', ['ctrl+alt+p']);
    }

    protected function command(string $binary, string $file): array
    {
        return [$binary, '--output-format=json', '--no-progress', $file];
    }

    protected function acceptsExitCode(int $code): bool
    {
        return 0 === $code || 2 === $code;
    }

    protected function diagnostics(array $report): array
    {
        $diagnostics = [];
        foreach ($report as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $diagnostics[] = [
                'line' => max(0, (int) ($issue['line_from'] ?? 1) - 1), // psalm lines are 1-based
                'column' => max(0, (int) ($issue['column_from'] ?? 1) - 1),
                'message' => trim(($issue['type'] ?? 'psalm').': '.($issue['message'] ?? '')),
                'severity' => match ($issue['severity'] ?? '') {
                    'error' => 1,
                    'info' => 3,
                    default => 2,
                },
            ];
        }

        return $diagnostics;
    }
};
