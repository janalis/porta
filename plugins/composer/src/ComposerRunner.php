<?php

declare(strict_types=1);

namespace Porta\Composer;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\AsyncProcess;
use Porta\Workbench\Dock\ToolRun;

/**
 * Composer, driven from the editor.
 *
 * What is out of date is asked for in the background — `composer outdated`,
 * the direct dependencies, with Composer's own plugins off — and shown twice:
 * a row per package in the dock's **Packages** tab, and a mark after the line
 * requiring it in composer.json.
 *
 * What changes the project — install, update, require, remove — runs in a
 * `Composer` terminal tab, where its output and any question it asks are the
 * user's to read and answer. Once one succeeds, what is out of date is asked
 * for again, if it was asked for before: the list never stays behind a change
 * it no longer describes.
 */
final class ComposerRunner
{
    public const string TOOL = 'packages';

    /** The terminal tab every run streams into, `run:Composer`. */
    public const string TITLE = 'Composer';

    /** @var list<OutdatedPackage>|null null until asked for */
    private ?array $outdated = null;

    /**
     * @param \Closure(): ?string $acquire the binary, holding the plugin's one run, or null after saying why not
     * @param \Closure(): void    $release frees that run
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $acquire,
        private readonly \Closure $release,
    ) {}

    /** Ask which direct dependencies have newer releases. */
    public function checkOutdated(): void
    {
        $binary = ($this->acquire)();
        if (null !== $binary) {
            $this->check($binary);
        }
    }

    public function install(): void
    {
        $this->run(['install']);
    }

    public function updateAll(): void
    {
        $this->run(['update']);
    }

    /** `composer update` for the package the caret's line in composer.json requires. */
    public function updateAtCaret(): void
    {
        $package = $this->packageAtCaret();
        if (null !== $package) {
            $this->run(['update', $package]);
        }
    }

    /** `composer remove` for the package the caret's line in composer.json requires. */
    public function removeAtCaret(): void
    {
        $package = $this->packageAtCaret();
        if (null !== $package) {
            $this->run(['remove', $package]);
        }
    }

    /** Ask what to require — `vendor/name`, a constraint after a colon, `--dev` — then `composer require` it. */
    public function require(): void
    {
        $this->api->prompt($this->api->trans('Require (vendor/name[:constraint], --dev for a dev dependency)'), '', function (string $input): void {
            $arguments = \preg_split('~\s+~', \trim($input), flags: \PREG_SPLIT_NO_EMPTY);
            if (false !== $arguments && [] !== $arguments) {
                $this->run(['require', ...$arguments]);
            }
        });
    }

    /** Mark each outdated requirement in composer.json while it is in front — again after every switch, since the editor drops the marks. */
    public function annotate(): void
    {
        $manifest = $this->manifestInFront();
        if (null === $manifest || null === $this->outdated) {
            return;
        }
        $marks = [];
        foreach ($this->outdated as $package) {
            $line = $manifest->lineOf($package->name);
            if (null !== $line) {
                $marks[$line] = $this->mark($package);
            }
        }
        $this->api->setLineAnnotations($marks);
    }

    private function check(string $binary): void
    {
        $this->api->showList(self::TOOL, [], ToolRun::Running);
        $command = [$binary, 'outdated', '--direct', '--format=json', '--no-plugins', '--no-interaction'];
        $started = new AsyncProcess($command, $this->api->projectRoot(), timeout: AsyncProcess::EXTERNAL_TOOL_TIMEOUT)->start(function (int $code, string $output): void {
            ($this->release)();
            try {
                $packages = OutdatedReport::parse($output);
            } catch (\RuntimeException) {
                $packages = null;
            }
            $this->publish($packages ?? []);
            $this->checked($packages, $code);
        });
        if (!$started) {
            ($this->release)();
            $this->publish([]);
            $this->api->notify($this->api->trans('composer could not be started'), ToastSeverity::Error, 5.0);
        }
    }

    /** @param list<OutdatedPackage> $packages */
    private function publish(array $packages): void
    {
        $this->outdated = $packages;
        $path = $this->manifestPath();
        $manifest = new Manifest($this->manifestText($path));
        $this->api->updateList(self::TOOL, \array_map(fn (OutdatedPackage $package): array => [
            'path' => $path,
            'line' => $manifest->lineOf($package->name) ?? 0,
            'text' => $package->name.' '.$package->version.' '.$this->mark($package),
        ], $packages));
        $this->annotate();
    }

    /** @param list<OutdatedPackage>|null $packages null when composer printed no report */
    private function checked(?array $packages, int $code): void
    {
        if (null === $packages) {
            $this->api->notify($this->api->trans('composer outdated exited with code %code%', ['%code%' => $code]), ToastSeverity::Error, 5.0);

            return;
        }
        $behind = \count(\array_filter($packages, static fn (OutdatedPackage $package): bool => $package->behind()));
        $this->api->notify(
            0 === $behind
                ? $this->api->trans('Every direct dependency is on its latest release')
                : $this->api->trans('%count% direct dependency has a newer release|%count% direct dependencies have newer releases', ['%count%' => $behind]),
            0 === $behind ? ToastSeverity::Success : ToastSeverity::Info,
            5.0,
        );
    }

    /** What is said after a package: how far behind, and whether it is abandoned. */
    private function mark(OutdatedPackage $package): string
    {
        $mark = $package->glyph();
        if ($package->behind()) {
            $mark .= ' '.$package->latest.($package->withinConstraint() ? '' : ' '.$this->api->trans('(past the constraint)'));
        }

        return match ($package->abandoned) {
            null => $mark,
            '' => $mark.' '.$this->api->trans('abandoned'),
            default => $mark.' '.$this->api->trans('abandoned, use %package%', ['%package%' => $package->abandoned]),
        };
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): void
    {
        // composer.json open with edits: Composer reads, and may rewrite, the file on disk.
        $path = $this->manifestPath();
        $refusal = null === $this->api->openBuffers()->text($path) ? null : $this->api->openBuffers()->save($path);
        $binary = null === $refusal ? ($this->acquire)() : null;
        if (null !== $refusal) {
            $this->api->flash($refusal, 4.0);
        }
        if (null === $binary) {
            return;
        }
        $this->api->runs()->start(self::TITLE, [$binary, ...$arguments], function (int $code) use ($binary, $arguments): void {
            $this->report($code, $arguments[0]);
            if (0 === $code && null !== $this->outdated) {
                $this->check($binary); // under the same slot

                return;
            }
            ($this->release)();
        });
    }

    private function report(int $code, string $verb): void
    {
        $params = ['%command%' => 'composer '.$verb, '%code%' => $code];
        [$message, $severity] = match ($code) {
            0 => [$this->api->trans('%command% finished', $params), ToastSeverity::Success],
            -1 => [$this->api->trans('%command% was stopped before it finished', $params), ToastSeverity::Error],
            default => [$this->api->trans('%command% exited with code %code% — see its terminal tab', $params), ToastSeverity::Error],
        };
        $this->api->notify($message, $severity, 5.0);
    }

    /** The package the caret's line requires, in the project's composer.json in front; null after saying why not. */
    private function packageAtCaret(): ?string
    {
        $package = $this->manifestInFront()?->packageAt($this->api->caretPosition()['line'] ?? -1);
        if (null === $package) {
            $this->api->flash($this->api->trans('Put the caret on a package in composer.json\'s require or require-dev'), 4.0);
        }

        return $package;
    }

    /** The project's composer.json, read, when it is the file in front; else null. */
    private function manifestInFront(): ?Manifest
    {
        $active = $this->api->activeFile();
        if (null === $active || $active !== $this->manifestPath()) {
            return null;
        }

        return new Manifest($this->manifestText($active));
    }

    /**
     * The project's composer.json as the editor names an open file: by its
     * real path — the root may keep a symlink's spelling, and an open buffer
     * is found by the exact path it was opened under.
     */
    private function manifestPath(): string
    {
        $path = $this->api->projectRoot().'/composer.json';
        $real = \realpath($path);

        return false === $real ? $path : $real;
    }

    /** As the editor has it, unsaved edits and all, else as it is on disk; '' when there is none. */
    private function manifestText(string $path): string
    {
        return $this->api->openBuffers()->text($path) ?? (\is_file($path) ? (string) \file_get_contents($path) : '');
    }
}
