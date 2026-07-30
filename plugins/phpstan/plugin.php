<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * PHPStan integration: "Analyse Current File" (Ctrl+Alt+A) and a persisted
 * "Analyse on save" setting (toggled from the palette or the plugin's settings
 * page). Errors are published as editor diagnostics — inline messages,
 * gutter/stripe colors and the Problems panel (Alt+6), exactly like LSP
 * diagnostics — and a clean run clears them. The run, the binary and the
 * single-flight slot come from {@see AbstractAnalyzerPlugin}; what is left
 * here is phpstan's own truth: its argv, that it exits 0 (clean) or 1 (errors
 * found) with a report either way, and the walk over its per-file message
 * groups. Note: an LSP server publishing diagnostics for the same file
 * replaces these (and vice versa) — last writer wins.
 */
return new class('phpstan', 'phpstan') extends AbstractAnalyzerPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'phpstan/phpstan']]);
        $this->registerAnalysis($api, 'PHPStan', ['ctrl+alt+a']);
    }

    protected function command(string $binary, string $file): array
    {
        return [$binary, 'analyse', '--error-format=json', '--no-progress', $file];
    }

    protected function acceptsExitCode(int $code): bool
    {
        return 0 === $code || 1 === $code;
    }

    protected function diagnostics(array $report): array
    {
        $diagnostics = [];
        foreach (self::rowsAt($report, 'files') as $entry) {
            foreach (self::rowsAt($entry, 'messages') as $message) {
                $diagnostics[] = [
                    'line' => max(0, (int) ($message['line'] ?? 1) - 1), // phpstan lines are 1-based
                    'message' => (string) ($message['message'] ?? ''),
                ];
            }
        }

        return $diagnostics;
    }

    protected function noun(): string
    {
        return 'error';
    }
};
