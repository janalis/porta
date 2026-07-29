<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Symfony\Component\Process\ExecutableFinder;

/**
 * The base for a plugin that drives an external binary (php-cs-fixer, mago,
 * phpstan, psalm, phpactor, phpantom, chroma). It owns the four things every
 * one of them used to copy:
 *
 *   - **binary discovery** — the `binary` setting, then `vendor/bin/<tool>`,
 *     then PATH (see {@see BinarySearch} for the PATH-only variant);
 *   - **the single-flight slot** — {@see acquire()} fuses "already running?",
 *     "binary missing?" and "take the slot" into one call, so a command
 *     handler is a guard clause and a run. {@see release()} is explicit
 *     rather than automatic precisely so a plugin can chain two processes
 *     under one slot (mago's format→lint);
 *   - **the active-file guard** — "open a PHP file first";
 *   - **the boilerplate registrations** — the `binary` setting row, the
 *     install offer, the on-save toggle command.
 *
 * Nothing here decides what running the tool MEANS: exit-code semantics,
 * output parsing and diagnostics stay in the plugin (or in
 * {@see AbstractAnalyzerPlugin} for the tools that genuinely share them).
 */
abstract class AbstractToolPlugin extends AbstractPlugin
{
    /** The single-flight slot: one run of this tool at a time. */
    private bool $running = false;

    public function __construct(
        string $name,
        private readonly string $binaryName,
        private readonly BinarySearch $search = BinarySearch::VendorBinThenPath,
    ) {
        parent::__construct($name);
    }

    /**
     * The tool binary, or null when it cannot be found.
     *
     * Deliberately NOT memoized: the setting can change at runtime, and a
     * cached answer would ignore it until restart (chroma used to). The cost
     * is two stat calls, on a path that runs per resolution, not per key.
     */
    protected function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        if (BinarySearch::VendorBinThenPath === $this->search) {
            $local = $api->projectRoot().'/vendor/bin/'.$this->binaryName;
            if (is_file($local) && is_executable($local)) {
                return $local;
            }
        }

        return new ExecutableFinder()->find($this->binaryName);
    }

    /**
     * The "is this tool usable?" predicate menu entries and install offers share.
     *
     * @return \Closure(): bool
     */
    protected function binaryAvailable(PluginApi $api): \Closure
    {
        return fn (): bool => null !== $this->binary($api);
    }

    /** Take the single-flight slot and resolve the binary, or flash why not and return null. */
    protected function acquire(PluginApi $api): ?string
    {
        if ($this->running) {
            $api->flash($this->name().': already running');

            return null;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash($this->missingBinaryMessage(), 4.0);

            return null;
        }
        $this->running = true;

        return $binary;
    }

    /** Free the single-flight slot — call it from the process's exit callback (or after the last one in a chain). */
    protected function release(): void
    {
        $this->running = false;
    }

    /** The active tab's file when it has $extension, else null after flashing the hint. */
    protected function activeFileOrFlash(PluginApi $api, string $extension = '.php'): ?string
    {
        $file = $api->activeFile();
        if (null === $file || !str_ends_with($file, $extension)) {
            $api->flash($this->name().': open a PHP file first');

            return null;
        }

        return $file;
    }

    /**
     * The `binary` setting row plus the install offer, wired to the shared
     * availability predicate.
     *
     * @param list<array{0: string, 1: string}> $via ordered [manager, package] install candidates
     */
    protected function registerBinarySetting(PluginApi $api, array $via, ?string $hint = null): void
    {
        $api->registerSetting('binary', $this->binaryName.' binary', 'text', '', hint: $hint ?? $this->binaryHint());
        $api->registerInstaller($this->binaryName, $this->binaryAvailable($api), $via);
    }

    /** A persisted on/off setting exposed as a palette command that flips and reports it. */
    protected function registerToggle(PluginApi $api, string $id, string $label, string $settingKey, string $phrase): void
    {
        $api->registerCommand($id, $label, static function () use ($api, $settingKey, $phrase): void {
            $next = !(bool) $api->setting($settingKey);
            $api->changeSetting($settingKey, $next);
            $api->flash($phrase.': '.($next ? 'on' : 'off'));
        });
    }

    /** The exit-code report every tool run ends with: the success line, or the code. */
    protected function flashExit(PluginApi $api, int $code, string $success): void
    {
        if (0 === $code) {
            $api->flash($success, 4.0);

            return;
        }
        $this->flashFailure($api, $code);
    }

    /**
     * The failure half on its own — for a run that failed for a reason the
     * exit code alone does not carry (mago exits 0 with an unparseable
     * report), where {@see flashExit()} would wrongly report success.
     */
    protected function flashFailure(PluginApi $api, int $code): void
    {
        $api->flash($this->name().': exited with code '.$code, 4.0);
    }

    /** '<n> issues' / '1 issue' / 'no issues' — the count report the analysis tools share. */
    protected function flashCount(PluginApi $api, int $count, string $noun, string $file): void
    {
        $api->flash(match (true) {
            0 === $count => \sprintf('%s: no %ss in %s', $this->name(), $noun, basename($file)),
            1 === $count => \sprintf('%s: 1 %s in %s', $this->name(), $noun, basename($file)),
            default => \sprintf('%s: %d %ss in %s', $this->name(), $count, $noun, basename($file)),
        }, 4.0);
    }

    private function binaryHint(): string
    {
        return BinarySearch::PathOnly === $this->search
            ? \sprintf('empty = %s on PATH', $this->binaryName)
            : 'empty = vendor/bin, then PATH';
    }

    private function missingBinaryMessage(): string
    {
        return BinarySearch::PathOnly === $this->search
            ? \sprintf('%s not found (set its binary or install it)', $this->name())
            : \sprintf('%s not found (vendor/bin or PATH)', $this->name());
    }
}
