<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Psalm integration: "Analyse Current File" (Ctrl+Alt+P) and an
 * persisted "Analyse on save" setting (toggled from the palette or the
 * plugin's settings page). Issues are published as editor diagnostics —
 * inline messages, gutter/stripe colors and the Problems panel (Alt+6),
 * exactly like LSP diagnostics — and a clean run clears them. The analysis
 * runs asynchronously; the binary resolves from vendor/bin, then the PATH.
 * Note: an LSP server publishing diagnostics for the same file replaces
 * these (and vice versa) — last writer wins.
 */
return new class implements PortaPlugin {
    private bool $running = false;

    public function name(): string
    {
        return 'psalm';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'psalm binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerSetting('autoLint', 'Analyse on save', 'bool', false);
        $api->registerInstaller('psalm', fn (): bool => null !== $this->binary($api), [['composer', 'vimeo/psalm']]);

        $api->registerCommand(
            'analyse',
            'Psalm: Analyse Current File',
            function () use ($api): void {
                $file = $api->activeFile();
                if (null === $file || !str_ends_with($file, '.php')) {
                    $api->flash('psalm: open a PHP file first');

                    return;
                }
                $this->analyse($api, $file);
            },
            ['ctrl+alt+p'],
        );

        $api->registerCommand('toggle-on-save', 'Psalm: Toggle Analyse on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoLint');
            $api->changeSetting('autoLint', $next);
            $api->flash('psalm on save: '.($next ? 'on' : 'off'));
        });

        $api->addMenuItem('analyse', fn (): bool => null !== $this->binary($api));

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoLint') && str_ends_with($path, '.php')) {
                $this->analyse($api, $path);
            }
        });
    }

    private function analyse(PluginApi $api, string $file): void
    {
        if ($this->running) {
            $api->flash('psalm: already running');

            return;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('psalm not found (vendor/bin or PATH)', 4.0);

            return;
        }

        $this->running = true;
        $command = [$binary, '--output-format=json', '--no-progress', $file];
        new AsyncProcess($command, $api->projectRoot())->start(function (int $code, string $stdout) use ($api, $file): void {
            $this->running = false;
            $this->report($api, $file, $code, $stdout);
        });
    }

    private function report(PluginApi $api, string $file, int $code, string $stdout): void
    {
        // psalm exits 0 (clean) or 2 (issues found), both with a JSON report;
        // anything else is an internal failure (missing psalm.xml, crash, …).
        $report = json_decode($stdout, true);
        if ($code !== 0 && $code !== 2 || !is_array($report)) {
            $api->flash('psalm: exited with code '.$code, 4.0);

            return;
        }

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

        $api->setDiagnostics($file, $diagnostics);
        $count = count($diagnostics);
        $api->flash(match (true) {
            0 === $count => 'psalm: no issues in '.basename($file),
            1 === $count => 'psalm: 1 issue in '.basename($file),
            default => "psalm: {$count} issues in ".basename($file),
        }, 4.0);
    }

    private function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        $local = $api->projectRoot().'/vendor/bin/psalm';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return new ExecutableFinder()->find('psalm');
    }
};
