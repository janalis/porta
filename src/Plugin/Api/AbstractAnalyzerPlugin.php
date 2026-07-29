<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Support\AsyncProcess;

/**
 * The base for a static analyzer that publishes editor diagnostics (phpstan,
 * psalm). On top of {@see AbstractToolPlugin} it owns the whole run: take the
 * single-flight slot, spawn the tool asynchronously, decode its JSON report,
 * hand the rows to the editor and flash the count.
 *
 * What is left to the subclass is exactly the per-tool truth, in three small
 * methods: the argv, which exit codes carry a report (phpstan says `0|1`,
 * psalm `0|2` — reading the other's as "internal failure" would swallow every
 * real finding), and the walk from that tool's JSON shape to diagnostic rows.
 * {@see noun()} is the fourth, so phpstan can say "3 errors" where psalm says
 * "3 issues".
 *
 * Note the analyzer never inspects a row's path: both tools are invoked on a
 * single file and their report is about it. An analyzer whose tool reports on
 * other files too must filter inside {@see diagnostics()}.
 */
abstract class AbstractAnalyzerPlugin extends AbstractToolPlugin
{
    /**
     * The analysis command line for one file.
     *
     * @return list<string>
     */
    abstract protected function command(string $binary, string $file): array;

    /** Whether this exit code means "the report on stdout is valid" (as opposed to an internal failure). */
    abstract protected function acceptsExitCode(int $code): bool;

    /**
     * This tool's JSON report, walked into editor diagnostic rows.
     *
     * @param array<array-key, mixed> $report
     *
     * @return list<array{line: int, message: string, severity?: int, column?: int}>
     */
    abstract protected function diagnostics(array $report): array;

    /** What one row is called in the count flash — "3 errors" vs "3 issues". */
    protected function noun(): string
    {
        return 'issue';
    }

    /**
     * The analysis command, its on-save toggle, both menu entries and the save
     * hook — the registration block both analyzers had verbatim. `$display` is
     * the human name that prefixes the two command labels ("PHPStan", "Psalm");
     * the ids are shared on purpose, since they are palette entries and keymap
     * targets that should read the same across analyzers.
     *
     * @param list<string> $keys default keys for the analyse command
     */
    protected function registerAnalysis(PluginApi $api, string $display, array $keys): void
    {
        $api->registerSetting('autoLint', 'Analyse on save', 'bool', false);

        $api->registerCommand(
            'analyse',
            $display.': Analyse Current File',
            function () use ($api): void {
                $file = $this->activeFileOrFlash($api);
                if (null !== $file) {
                    $this->analyse($api, $file);
                }
            },
            $keys,
        );
        $this->registerToggle($api, 'toggle-on-save', $display.': Toggle Analyse on Save', 'autoLint', $this->name().' on save');

        $api->addMenuItem('analyse', $this->binaryAvailable($api));
        $api->addMenuItem('toggle-on-save');

        $api->onFileSaved(function (string $path) use ($api): void {
            if ((bool) $api->setting('autoLint') && str_ends_with($path, '.php')) {
                $this->analyse($api, $path);
            }
        });
    }

    /** One analysis run: take the slot, then run it. */
    final protected function analyse(PluginApi $api, string $file): void
    {
        $binary = $this->acquire($api);
        if (null !== $binary) {
            $this->analyseWith($api, $binary, $file);
        }
    }

    /**
     * The run WITHOUT the acquire, for a plugin that already holds the slot —
     * mago's "format, then lint the formatted file" chain is one user action and
     * must be one slot, so it formats under `acquire()` and lints through here.
     * It still releases when the process exits, so it is the LAST step of a
     * chain, never the middle one.
     */
    final protected function analyseWith(PluginApi $api, string $binary, string $file): void
    {
        new AsyncProcess($this->command($binary, $file), $api->projectRoot())->start(
            function (int $code, string $stdout) use ($api, $file): void {
                $this->release();
                $this->publish($api, $file, $code, $stdout);
            },
        );
    }

    /**
     * A finished run: a valid report becomes the file's diagnostics (an empty
     * one clears them), anything else is reported as a failure and leaves the
     * previous diagnostics alone.
     */
    private function publish(PluginApi $api, string $file, int $code, string $stdout): void
    {
        /** @var array<array-key, mixed>|scalar|null $report */
        $report = json_decode($stdout, associative: true);
        if (!$this->acceptsExitCode($code) || !\is_array($report)) {
            $this->flashFailure($api, $code);

            return;
        }

        $diagnostics = $this->diagnostics($report);
        $api->setDiagnostics($file, $diagnostics);
        $this->flashCount($api, \count($diagnostics), $this->noun(), $file);
    }
}
