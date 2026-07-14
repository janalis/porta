<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * PHPStan integration: "Analyse Current File" (Ctrl+Alt+A) and an
 * persisted "Analyse on save" setting (toggled from the palette or the
 * plugin's settings page). Errors are published as editor diagnostics —
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
        return 'phpstan';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'phpstan binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerSetting('autoLint', 'Analyse on save', 'bool', false);

        $api->registerCommand(
            'analyse',
            'PHPStan: Analyse Current File',
            function () use ($api): void {
                $file = $api->activeFile();
                if (null === $file || !str_ends_with($file, '.php')) {
                    $api->flash('phpstan: open a PHP file first');

                    return;
                }
                $this->analyse($api, $file);
            },
            ['ctrl+alt+a'],
        );

        $api->registerCommand('toggle-on-save', 'PHPStan: Toggle Analyse on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoLint');
            $api->changeSetting('autoLint', $next);
            $api->flash('phpstan on save: '.($next ? 'on' : 'off'));
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
            $api->flash('phpstan: already running');

            return;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('phpstan not found (vendor/bin or PATH)', 4.0);

            return;
        }

        $this->running = true;
        $command = [$binary, 'analyse', '--error-format=json', '--no-progress', $file];
        new AsyncProcess($command, $api->projectRoot())->start(function (int $code, string $stdout) use ($api, $file): void {
            $this->running = false;
            $this->report($api, $file, $code, $stdout);
        });
    }

    private function report(PluginApi $api, string $file, int $code, string $stdout): void
    {
        // phpstan exits 0 (clean) or 1 (errors found), both with a JSON report;
        // anything else is an internal failure.
        $report = json_decode($stdout, true);
        if ($code !== 0 && $code !== 1 || !is_array($report)) {
            $api->flash('phpstan: exited with code '.$code, 4.0);

            return;
        }

        $diagnostics = [];
        $files = is_array($report['files'] ?? null) ? $report['files'] : [];
        foreach ($files as $entry) {
            foreach ($entry['messages'] ?? [] as $message) {
                $diagnostics[] = [
                    'line' => max(0, (int) ($message['line'] ?? 1) - 1), // phpstan lines are 1-based
                    'message' => (string) ($message['message'] ?? ''),
                ];
            }
        }

        $api->setDiagnostics($file, $diagnostics);
        $count = count($diagnostics);
        $api->flash(match (true) {
            0 === $count => 'phpstan: no errors in '.basename($file),
            1 === $count => 'phpstan: 1 error in '.basename($file),
            default => "phpstan: {$count} errors in ".basename($file),
        }, 4.0);
    }

    private function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        $local = $api->projectRoot().'/vendor/bin/phpstan';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return new ExecutableFinder()->find('phpstan');
    }
};
