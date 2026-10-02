<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Config\WorkspaceTrust;
use Porta\Filesystem\ProjectLocation;
use Porta\Overlay\State\ToastSeverity;
use Porta\Support\AsyncProcess;
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
        $configured = \trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        // A project's vendor/bin is the project's code: only once it is trusted.
        if (BinarySearch::VendorBinThenPath === $this->search && self::trusted($api)) {
            $local = $api->projectRoot().'/vendor/bin/'.$this->binaryName;
            if (\is_file($local) && \is_executable($local)) {
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

    /**
     * Take the single-flight slot and resolve the binary, or flash why not and
     * return null. A remote project is a why not: its cache holds what was
     * opened, not the project the tool needs to read. So is an untrusted one,
     * for a tool that would run its code.
     */
    protected function acquire(PluginApi $api): ?string
    {
        $remote = ProjectLocation::forRoot($api->projectRoot())->remote;
        if (null !== $remote) {
            $api->flash($api->trans('This project is on %host%: running it needs it on this machine', ['%host%' => $remote->host]), 4.0);

            return null;
        }
        if ($this->runsProjectCode() && !self::trusted($api)) {
            $api->flash($api->trans('%tool% runs code of the project\'s own: trust the project to use it', ['%tool%' => $this->name()]), 4.0);

            return null;
        }
        if ($this->running) {
            $api->flash($api->trans('%tool%: already running', ['%tool%' => $this->name()]));

            return null;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash($this->missingBinaryMessage($api), 4.0);

            return null;
        }
        $this->running = true;

        return $binary;
    }

    /**
     * Whether running the tool executes PHP of the project's choosing — a
     * phpstan `bootstrapFiles`, a psalm autoloader, a `.php-cs-fixer.dist.php`
     * — whichever binary runs. Such a tool does not run in an untrusted project.
     */
    protected function runsProjectCode(): bool
    {
        return false;
    }

    private static function trusted(PluginApi $api): bool
    {
        return WorkspaceTrust::fromEnvironment()->isTrusted($api->projectRoot());
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
        if (null === $file || !\str_ends_with($file, $extension)) {
            $api->flash($api->trans('%tool%: open a PHP file first', ['%tool%' => $this->name()]));

            return null;
        }

        return $file;
    }

    /**
     * The `binary` setting row plus the install offer, wired to the shared
     * availability predicate.
     *
     * @param list<array{0: string, 1: string}> $via ordered [manager, package] install candidates
     *
     * @throws PluginException
     */
    protected function registerBinarySetting(PluginApi $api, array $via, ?string $hint = null): void
    {
        $api->registerSetting('binary', $this->binaryName.' binary', 'text', '', hint: $hint ?? $this->binaryHint());
        $api->registerInstaller($this->binaryName, $this->binaryAvailable($api), $via);
    }

    /**
     * A persisted on/off setting exposed as a palette command that flips and reports it.
     *
     * @throws PluginException
     */
    protected function registerToggle(PluginApi $api, string $id, string $label, string $settingKey, string $phrase): void
    {
        $api->registerCommand($id, $label, static function () use ($api, $settingKey, $phrase): void {
            $next = !(bool) $api->setting($settingKey);
            $api->changeSetting($settingKey, $next);
            $api->flash($phrase.': '.$api->trans($next ? 'on' : 'off'));
        });
    }

    /**
     * Apply what an in-place formatter just wrote, into the BUFFER — the step
     * that turns "rewrite the file and let the 1s poller notice" into an edit
     * the user can Ctrl+Z, that works while the buffer is dirty, and that
     * arrives the moment the tool exits.
     *
     * `$before` is the document text captured when the run STARTED, and it is
     * the version guard: if it no longer matches, the user typed while the
     * subprocess worked and their keystrokes win. Core's own format-on-save
     * makes the same check with a document version, which plugins cannot see.
     *
     * A null `$before` means the file was not the active one at launch, so
     * there is nothing to apply into — the poller still reloads it later, which
     * is the right outcome for a file the user is not looking at.
     */
    final protected function applyFormatted(PluginApi $api, string $file, ?string $before): void
    {
        if (null === $before || $api->activeFile() !== $file) {
            return; // the user moved on; the file on disk is still correct
        }
        $formatted = \is_file($file) ? \file_get_contents($file) : false;
        if (false === $formatted || $formatted === $before || $api->documentText() !== $before) {
            return; // unreadable, unchanged, or typed into while the tool ran
        }
        $api->replaceDocument($formatted);
    }

    /** The document text to hand {@see applyFormatted()} later, or null when $file is not the active buffer. */
    final protected function textBefore(PluginApi $api, string $file): ?string
    {
        return $api->activeFile() === $file ? $api->documentText() : null;
    }

    /** The exit-code report every tool run ends with: the success line, or the code. */
    protected function flashExit(PluginApi $api, int $code, string $success): void
    {
        if (0 === $code) {
            $api->notify($success, ToastSeverity::Success, 4.0);

            return;
        }
        $this->flashFailure($api, $code);
    }

    /**
     * Acquire the slot, run $arguments to completion, apply the result into
     * $formatted's buffer (if given), then flash $success or the failure —
     * the single-file-formatter shape php-cs-fixer and phpantom both run.
     * A plugin whose control flow branches on the exit code (mago's
     * format→lint chain) still assembles the run by hand instead.
     *
     * @param list<string> $arguments argv after the binary
     */
    final protected function runAndApply(PluginApi $api, array $arguments, string $success, ?string $formatted = null): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }
        $before = null === $formatted ? null : $this->textBefore($api, $formatted);
        $started = new AsyncProcess([$binary, ...$arguments], $api->projectRoot(), timeout: AsyncProcess::INTERACTIVE_TOOL_TIMEOUT)->start(function (int $code) use (
            $api,
            $success,
            $formatted,
            $before,
        ): void {
            $this->release();
            if (null !== $formatted) {
                $this->applyFormatted($api, $formatted, $before);
            }
            $this->flashExit($api, $code, $success);
        });
        if (!$started) {
            // Never spawned: nothing will release the slot this run took, and
            // the tool would answer "already running" for the rest of the
            // session about a run that never started.
            $this->release();
            $api->notify($api->trans('%tool% could not be started', ['%tool%' => $this->name()]), ToastSeverity::Error);
        }
    }

    /**
     * The failure half on its own — for a run that failed for a reason the
     * exit code alone does not carry (mago exits 0 with an unparseable
     * report), where {@see flashExit()} would wrongly report success.
     */
    protected function flashFailure(PluginApi $api, int $code): void
    {
        $api->notify($api->trans('%tool%: exited with code %code%', ['%tool%' => $this->name(), '%code%' => $code]), ToastSeverity::Error, 5.0);
    }

    /**
     * '<n> issues' / '1 issue' / 'no issues' — the count report the analysis
     * tools share. The sentence is translated; %noun%/%nouns% arrive in English
     * from {@see AbstractAnalyzerPlugin::noun()}, because plugins name their
     * own diagnostics.
     */
    protected function flashCount(PluginApi $api, int $count, string $noun, string $file): void
    {
        // BOTH nouns go to both sentences, and that is load-bearing rather
        // than lazy: five of the six translations of the zero-count string use
        // `%noun%` where the English uses `%nouns%`, because "aucun problème"
        // and its equivalents are singular and Japanese and Hebrew do not mark
        // plurals here at all. Trimming this array to the placeholders the
        // ENGLISH source happens to name would render those five literally.
        $params = [
            '%tool%' => $this->name(),
            '%count%' => $count,
            '%noun%' => $noun,
            '%nouns%' => $noun.'s',
            '%file%' => \basename($file),
        ];
        $api->flash(
            0 === $count
                ? $api->trans('%tool%: no %nouns% in %file%', $params)
                : $api->trans('%tool%: %count% %noun% in %file%|%tool%: %count% %nouns% in %file%', $params),
            4.0,
        );
    }

    private function binaryHint(): string
    {
        return BinarySearch::PathOnly === $this->search
            ? \sprintf('empty = %s on PATH', $this->binaryName)
            : 'empty = vendor/bin, then PATH';
    }

    private function missingBinaryMessage(PluginApi $api): string
    {
        return $api->trans(
            BinarySearch::PathOnly === $this->search
                ? '%tool% not found (set its binary or install it)'
                : '%tool% not found (vendor/bin or PATH)',
            ['%tool%' => $this->name()],
        );
    }
}
