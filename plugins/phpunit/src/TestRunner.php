<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

use Porta\Overlay\State\ToastSeverity;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Files;
use Porta\Workbench\Dock\ToolRun;

/**
 * Runs PHPUnit in a terminal tab of its own and shows what came of it: a row
 * per test in the dock, each failure as a diagnostic on the line it points at,
 * a mark after each test method of the file on screen, and the failing count
 * in the status bar.
 *
 * What is shown is the LAST run, whole: a run of one test replaces a run of
 * the suite rather than merging into it, so nothing on screen is older than
 * the run the tab shows.
 */
final class TestRunner
{
    public const string TOOL = 'tests';

    /** The terminal tab every run streams into, `run:PHPUnit`. */
    public const string TITLE = 'PHPUnit';

    private TestResults $results;

    /** @var list<string> what the last run ran after the binary and its log, for F5 */
    private array $last = [];

    /** Whether the last run measured coverage, so F5 measures it again. */
    private bool $lastMeasured = false;

    /** @var list<string> the files this plugin has diagnostics on, to clear them on the next run */
    private array $diagnosed = [];

    /**
     * @param \Closure(): ?string $acquire the binary, holding the plugin's one run, or null after saying why not
     * @param \Closure(): void    $release frees that run
     */
    public function __construct(
        private readonly PluginApi $api,
        private readonly \Closure $acquire,
        private readonly \Closure $release,
        private readonly CoverageView $coverage,
    ) {
        $this->results = new TestResults();
    }

    /** The test method around the caret, else the file's tests. */
    public function runAtCaret(): void
    {
        $file = $this->testFile($this->api->activeFile());
        $target = null === $file ? null : $this->target($file, $this->api->caretPosition()['line'] ?? 0);
        if (null === $file || null === $target) {
            return;
        }
        $this->run(null === $target->method ? [$file] : ['--filter', TestTarget::filter([[$target->class, $target->method]]), $file]);
    }

    /** Every test in the file a command is about — the right-clicked one, else the active one. */
    public function runFile(): void
    {
        $file = $this->testFile($this->api->contextFile());
        if (null !== $file && null !== $this->target($file, 0)) {
            $this->run([$file]);
        }
    }

    /** The suite as the project's PHPUnit configuration defines it. */
    public function runAll(): void
    {
        $this->run([]);
    }

    /** The tests that failed or errored in the last run, every data set of each. */
    public function rerunFailed(): void
    {
        $broken = $this->results->broken();
        if ([] === $broken) {
            $this->api->flash($this->api->trans('No failed tests to run again'));

            return;
        }
        $tests = \array_map(static fn (TestOutcome $outcome): array => [$outcome->class, $outcome->method], $broken);
        $this->run(['--filter', TestTarget::filter($tests), ...$this->results->brokenFiles()]);
    }

    /**
     * The suite, measuring which lines it runs — what PHPUnit's `<source>`
     * names, with pcov or Xdebug in the PHP that runs it. Each file it
     * measured shows that in its change strip until the coverage is hidden.
     */
    public function runWithCoverage(): void
    {
        $this->start([], \sys_get_temp_dir().'/porta-phpunit-'.\bin2hex(\random_bytes(6)).'.clover.xml');
    }

    /** F5 on the tab: the last run again, or the suite when there has been none. */
    public function rerun(): void
    {
        if ($this->lastMeasured) {
            $this->runWithCoverage();

            return;
        }
        $this->run($this->last);
    }

    /** How many tests the last run left failing, for the status bar; null while none are. */
    public function status(): ?string
    {
        $failing = \count($this->results->broken());

        return 0 === $failing ? null : $this->api->trans('✗ %count% test failing|✗ %count% tests failing', ['%count%' => $failing]);
    }

    /** Mark each test method of the file on screen with how it did — again after every switch, since the editor drops the marks. */
    public function annotate(): void
    {
        $this->api->setLineAnnotations(\array_map(
            fn (string $status): string => TestOutcome::glyph($status).' '.$this->api->trans(TestOutcome::verdict($status)),
            $this->results->lines($this->api->activeFile()),
        ));
    }

    /** Put a file's failures on it when it opens: diagnostics only land on an open buffer. */
    public function opened(string $path): void
    {
        if ([] !== $this->results->brokenIn($path)) {
            $this->diagnose($path);
        }
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): void
    {
        $this->start($arguments, null);
    }

    /**
     * @param list<string> $arguments
     * @param string|null  $clover    where PHPUnit is to write coverage, null for a run that measures none
     */
    private function start(array $arguments, ?string $clover): void
    {
        $binary = ($this->acquire)();
        if (null === $binary) {
            return;
        }
        $this->last = $arguments;
        $this->lastMeasured = null !== $clover;
        $log = \sys_get_temp_dir().'/porta-phpunit-'.\bin2hex(\random_bytes(6)).'.xml';
        $command = [$binary, '--log-junit', $log, ...$arguments];
        if (null !== $clover) {
            $command = [...self::measuring(), $binary, '--log-junit', $log, '--coverage-clover', $clover, ...$arguments];
        }
        $this->api->showList(self::TOOL, [], ToolRun::Running);
        $this->api->runs()->start(self::TITLE, $command, function (int $code) use ($log, $clover): void {
            ($this->release)();
            $reported = $this->finished($code, self::take($log));
            $measured = null === $clover ? '' : self::take($clover);
            if (null !== $clover && $reported) {
                $this->measured($measured);
            }
        });
    }

    /**
     * What a run that measures coverage starts under: Xdebug collects it only
     * in its `coverage` mode, which a machine set up for debugging is not in,
     * so the run adds it to whatever mode the IDE was started with. pcov
     * needs nothing: loaded, it collects.
     *
     * @return list<string>
     */
    private static function measuring(): array
    {
        $mode = \getenv('XDEBUG_MODE');

        return ['/usr/bin/env', 'XDEBUG_MODE='.(\is_string($mode) && '' !== $mode ? $mode.',' : '').'coverage'];
    }

    /** What a report file holds, the file removed: '' when the run wrote none. */
    private static function take(string $file): string
    {
        $text = '';
        if (\is_file($file)) {
            $text = (string) \file_get_contents($file);
            \unlink($file);
        }

        return $text;
    }

    /**
     * Show the coverage a run that reported its tests wrote, or say why there
     * is none: no report, or one that measured no file. A run that never got
     * as far as its tests has said so already.
     */
    private function measured(string $xml): void
    {
        try {
            $files = CloverReport::parse($xml);
        } catch (\RuntimeException) {
            $files = [];
        }
        $this->coverage->show($files);
        if ([] === $files) {
            $this->api->notify($this->api->trans('No coverage: PHPUnit measures it with pcov or Xdebug, for what <source> in phpunit.xml names'), ToastSeverity::Warn, 6.0);
        }
    }

    /**
     * Show what the run left. A run with no report — stopped, crashed, or
     * refused by its own configuration — still replaces the last one: what
     * stays on screen would otherwise be older than the run the tab shows.
     */
    private function finished(int $code, string $xml): bool
    {
        try {
            $outcomes = JunitReport::parse($xml, $this->api->projectRoot());
        } catch (\RuntimeException) {
            $outcomes = null;
        }
        $this->publish($outcomes ?? []);
        $this->report($code, $outcomes);

        return null !== $outcomes;
    }

    /** @param list<TestOutcome> $outcomes */
    private function publish(array $outcomes): void
    {
        $this->results = new TestResults($outcomes);
        $this->api->updateList(self::TOOL, \array_map($this->row(...), $this->results->outcomes));

        // The files the last run left failures on, and this run's: the first
        // to clear, the second to mark.
        $files = [...$this->diagnosed, ...$this->results->brokenFiles()];
        $this->diagnosed = [];
        foreach (\array_unique($files) as $file) {
            $this->diagnose($file);
        }
        $this->annotate();
        $this->api->refreshStatusSegments();
    }

    private function diagnose(string $file): void
    {
        $diagnostics = \array_map(
            static fn (TestOutcome $outcome): array => ['line' => $outcome->at, 'message' => $outcome->label().': '.$outcome->message, 'code' => 'phpunit'],
            $this->results->brokenIn($file),
        );
        if ([] !== $diagnostics) {
            $this->diagnosed[] = $file;
        }
        $this->api->setDiagnostics($file, $diagnostics);
    }

    /**
     * The run's verdict. The exit code has the last word over a report of
     * passes: PHPUnit fails a run on warnings, deprecations or risky tests
     * the project's configuration names, and the log records none of them.
     *
     * @param list<TestOutcome>|null $outcomes null when the run wrote no report
     */
    private function report(int $code, ?array $outcomes): void
    {
        $broken = \count($this->results->broken());
        $params = ['%count%' => \count($outcomes ?? []), '%failed%' => $broken, '%code%' => $code];
        [$message, $severity] = match (true) {
            null === $outcomes && -1 === $code => [$this->api->trans('PHPUnit was stopped before it finished'), ToastSeverity::Error],
            null === $outcomes => [$this->api->trans('PHPUnit exited with code %code% without a report — see its terminal tab', $params), ToastSeverity::Error],
            [] === $outcomes => [$this->api->trans('PHPUnit ran no tests'), ToastSeverity::Warn],
            0 === $broken && 0 !== $code => [
                $this->api->trans(
                    '%count% test passed, but PHPUnit exited with code %code% — see its terminal tab|%count% tests passed, but PHPUnit exited with code %code% — see its terminal tab',
                    $params,
                ),
                ToastSeverity::Warn,
            ],
            0 === $broken => [$this->api->trans('%count% test passed|%count% tests passed', $params), ToastSeverity::Success],
            default => [$this->api->trans('%failed% of %count% tests failed', $params), ToastSeverity::Error],
        };
        $this->api->notify($message, $severity, 5.0);
    }

    /** @return array{path: string, line: int, text: string} */
    private function row(TestOutcome $outcome): array
    {
        $text = TestOutcome::glyph($outcome->status).' '.$outcome->label();

        return ['path' => $outcome->file, 'line' => $outcome->at, 'text' => '' === $outcome->message ? $text : $text.' — '.$outcome->message];
    }

    /** $path when it is a PHP file, saved first if it is open with edits PHPUnit would not see; else null after saying why. */
    private function testFile(?string $path): ?string
    {
        // Only an open file can have edits to save: one right-clicked in the
        // tree is already what is on disk.
        $refusal = match (true) {
            null === $path || !\str_ends_with($path, '.php') => $this->api->trans('Open a PHPUnit test file first'),
            null === $this->api->openBuffers()->text($path) => null,
            default => $this->api->openBuffers()->save($path),
        };
        if (null !== $refusal) {
            $this->api->flash($refusal, 4.0);
        }

        return null === $refusal ? $path : null;
    }

    /** The test class $file declares, read from its buffer when open; null after saying it has none. */
    private function target(string $file, int $line): ?TestTarget
    {
        $text = $this->api->openBuffers()->text($file) ?? Files::read($file);
        $target = TestTarget::at($text, $line);
        if (null === $target) {
            $this->api->flash($this->api->trans('%file% declares no test class', ['%file%' => \basename($file)]));
        }

        return $target;
    }
}
