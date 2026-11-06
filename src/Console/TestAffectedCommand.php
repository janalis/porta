<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The inner loop's test run: only the test files the working tree's change
 * can reach, in seconds instead of the whole suite's minute and a half.
 *
 * The selection is by NAME, which is what keeps it cheap and predictable: a
 * changed test file runs itself, and a changed class runs every test file that
 * mentions its short name. Replayed over twelve recent fix commits, that picked
 * a median of 3 of 1043 test files and at most a tenth of them. A change it
 * cannot place — a config file, a doc, a fixture, `bin/` — runs the whole suite
 * instead, and says which file forced it: guessing small there would make a
 * green run mean less than it reads.
 *
 * It is not a gate and replaces none: an E2e test that drives a class without
 * naming it is invisible to a name search, which is exactly why
 * `composer verify` still runs everything. What it buys is the minutes between
 * an edit and its first honest red.
 *
 * The run goes through paratest with `composer test`'s own flags, over a
 * throwaway copy of `phpunit.xml.dist` whose one testsuite lists the selected
 * files — so the bootstrap, the isolation extensions, the environment and the
 * random order are the real suite's, not an approximation of them (paratest
 * takes ONE path, so a list needs a configuration).
 *
 * `--coverage` answers "is any line I just wrote unexercised?" the way the
 * hand recipe in contributing.md does: one serial phpunit under pcov over the
 * selection, then each changed source file's uncovered statement lines, by
 * number. Serial on purpose — a single process has no coverage merge to lose
 * plugin paths in (phpunit.xml.dist's `<source>` note says why that matters).
 */
#[AsCommand(
    name: 'test:affected',
    description: 'Run the tests the working tree\'s change can reach',
    help: <<<'HELP'
        Selects the test files the change touches — every changed test file, and every
        test file that names a changed class — and runs them with composer test's flags.

          <info>test:affected</info>                   the uncommitted change, against HEAD
          <info>test:affected --base=main</info>       everything since main, committed or not
          <info>test:affected --list</info>            print the selection, run nothing
          <info>test:affected --coverage</info>        serial run under pcov, then each changed source
                                          file's uncovered lines

        A changed file it cannot place (not PHP under src/, tests/ or plugins/) runs the
        whole suite instead. Not a gate: composer verify still runs everything.
        HELP,
    usages: ['--base=main', '--list', '--coverage'],
)]
final class TestAffectedCommand extends Command
{
    use CommandErrorStream;

    /** Where the Clover report of a `--coverage` run is written, under the root. */
    public const string CLOVER = 'coverage/affected.xml';

    /** A test file: what a changed one of these selects is itself. */
    private const string TEST_FILE = '#^(?:tests/(?:Unit|Integration|E2e)/|plugins/[^/]+/tests/).+Test\.php$#';

    /** Code a name search can place: the classes under the four PHP roots. */
    private const string CODE_FILE = '#^(?:src/|tests/|plugins/[^/]+/(?:src|tests)/).+\.php$#';

    /** Source a `--coverage` run grades: what the 100% gates hold to 100%. */
    private const string SOURCE_FILE = '#^(?:src/|plugins/[^/]+/src/).+\.php$#';

    /**
     * A plugin's own top-level files (`plugin.php`, its manifest, its
     * catalogs): nothing names them, but the plugin's own tests load them.
     */
    private const string PLUGIN_FILE = '#^plugins/([^/]+)/(?!src/|tests/)#';

    /** @var \Closure(list<string>): ?string */
    private readonly \Closure $git;

    /** @var \Closure(list<string>, array<string, string>, OutputInterface): int */
    private readonly \Closure $runner;

    /**
     * @param (\Closure(list<string> $args): ?string)|null $git the output of `git <args>` in the root, null when git fails; null runs the real git
     * @param (\Closure(list<string> $argv, array<string, string> $env, OutputInterface $output): int)|null $runner
     *                                                                                                       runs a test process in the root, streaming to the output, and returns its exit; null runs it for real
     */
    public function __construct(
        private readonly string $rootDir,
        ?\Closure $git = null,
        ?\Closure $runner = null,
    ) {
        parent::__construct();
        $this->git = $git ?? $this->realGit(...);
        $this->runner = $runner ?? $this->realRunner(...);
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('The revision the change is measured from')]
        string $base = 'HEAD',
        #[Option('paratest worker count, or auto; the composer script passes composer test\'s default')]
        string $processes = 'auto',
        // @mago-expect lint:no-boolean-flag-parameter -- Symfony Console's #[Option] shape: a bool option IS a flag.
        #[Option('Print the selection and run nothing')]
        bool $list = false,
        // @mago-expect lint:no-boolean-flag-parameter -- Symfony Console's #[Option] shape: a bool option IS a flag.
        #[Option('Run serially under pcov and name each changed source file\'s uncovered lines')]
        bool $coverage = false,
    ): int {
        $errors = self::errorOutput($output);
        $changed = $this->changedFiles($base);

        // Each step reports and yields its exit code; null means the next runs.
        $status = null === $changed
            ? self::finish($errors, \sprintf('test:affected: git could not diff %s against %s.', $this->rootDir, $base), Command::INVALID)
            : null;
        $status ??= [] === $changed ? self::finish($output, \sprintf('Nothing changed against %s.', $base), Command::SUCCESS) : null;
        $changed ??= [];
        $unplaced = \array_values(\array_filter($changed, self::unplaced(...)));
        [$selected, $unreached] = null === $status && [] === $unplaced ? $this->select($changed) : [[], []];
        if (null === $status) {
            self::announce($output, $unplaced, $unreached, $selected);
        }
        $whole = [] !== $unplaced || [] !== $unreached;
        $status ??= $coverage && $whole
            ? self::finish($errors, 'A whole-suite coverage run is the gate itself: run composer coverage:check.', Command::INVALID)
            : null;
        $status ??= $list ? Command::SUCCESS : null;

        return $status ?? match (true) {
            $whole => ($this->runner)([\PHP_BINARY, 'vendor/bin/paratest', ...self::suiteFlags($processes)], [], $output),
            $coverage => $this->coverageRun($output, $errors, $selected, $changed),
            default => $this->parallelRun($output, $selected, $processes),
        };
    }

    private static function finish(OutputInterface $to, string $message, int $status): int
    {
        $to->writeln($message);

        return $status;
    }

    /**
     * Says what the run will cover: the whole suite and the files that forced
     * it, or the selection.
     *
     * @param list<string> $unplaced
     * @param list<string> $unreached
     * @param list<string> $selected
     */
    private static function announce(OutputInterface $output, array $unplaced, array $unreached, array $selected): void
    {
        $output->writeln(match (true) {
            [] !== $unplaced => ['No name search can place '.\implode(', ', $unplaced).' — the whole suite reaches the change.'],
            [] !== $unreached => ['No test file names '.\implode(', ', $unreached).' — the whole suite reaches the change.'],
            default => [\sprintf('%d test file(s) reach the change:', \count($selected)), ...\array_map(static fn (string $file): string => '  '.$file, $selected)],
        });
    }

    /**
     * The changed paths, root-relative and sorted: the diff against the base
     * (staged and unstaged alike) plus the untracked files a new class or test
     * arrives as. Null when git cannot answer.
     *
     * @return list<string>|null
     */
    private function changedFiles(string $base): ?array
    {
        $diff = ($this->git)(['diff', '--name-only', $base, '--']);
        $untracked = ($this->git)(['ls-files', '--others', '--exclude-standard']);
        $paths = null;
        if (null !== $diff && null !== $untracked) {
            $paths = \array_values(\array_unique(\array_filter(
                \explode("\n", $diff."\n".$untracked),
                static fn (string $path): bool => '' !== $path,
            )));
            \sort($paths);
        }

        return $paths;
    }

    /** A changed path no rule here can map to the tests it affects. */
    private static function unplaced(string $path): bool
    {
        return 1 !== \preg_match(self::CODE_FILE, $path) && 1 !== \preg_match(self::PLUGIN_FILE, $path);
    }

    /**
     * The test files the change reaches, root-relative and sorted, and the
     * changed paths that reach none.
     *
     * A path that reaches no test is never dropped quietly: a selection that
     * skipped it would report green over code nothing ran, which is the
     * failure that kept a same-path mapper out of this tree (contributing.md,
     * "The inner loop") — so it sends the run to the whole suite instead.
     *
     * @param list<string> $changed
     *
     * @return array{list<string>, list<string>}
     */
    private function select(array $changed): array
    {
        $candidates = $this->testFiles();
        // A changed test file is searched for too: a base class or a
        // trait-holding test is named by the tests that extend it.
        $names = \array_map(static fn (string $path): string => \basename($path, suffix: '.php'), $changed);
        $namedBy = $this->namedBy($candidates, $names);

        $selected = [];
        $unreached = [];
        foreach ($changed as $path) {
            $plugin = [];
            $reach = 1 === \preg_match(self::PLUGIN_FILE, $path, $plugin)
                ? \array_filter($candidates, static fn (string $candidate): bool => \str_starts_with($candidate, 'plugins/'.$plugin[1].'/tests/'))
                : [
                    ...(1 === \preg_match(self::TEST_FILE, $path) && \is_file($this->rootDir.'/'.$path) ? [$path] : []),
                    ...($namedBy[\basename($path, suffix: '.php')] ?? []),
                ];
            if ([] === $reach) {
                $unreached[] = $path;
            }
            \array_push($selected, ...$reach);
        }
        $selected = \array_values(\array_unique($selected));
        \sort($selected);

        return [$selected, $unreached];
    }

    /**
     * Which candidates mention each name as a whole word, in one read of each.
     *
     * @param list<string>  $candidates
     * @param array<string> $names
     *
     * @return array<string, list<string>>
     */
    private function namedBy(array $candidates, array $names): array
    {
        $namedBy = [];
        $pattern = '/\b(?:'.\implode('|', \array_map(static fn (string $name): string => \preg_quote($name, delimiter: '/'), $names)).')\b/';
        foreach ([] === $names ? [] : $candidates as $candidate) {
            $found = [];
            \preg_match_all($pattern, (string) \file_get_contents($this->rootDir.'/'.$candidate), $found);
            foreach ($found[0] as $name) {
                $namedBy[$name][] = $candidate;
            }
        }

        return $namedBy;
    }

    /**
     * Every test file of the four suites, root-relative.
     *
     * @return list<string>
     */
    private function testFiles(): array
    {
        $plugins = \glob($this->rootDir.'/plugins/*/tests', flags: \GLOB_ONLYDIR);
        $roots = \array_filter(
            [
                'tests/Unit',
                'tests/Integration',
                'tests/E2e',
                ...\array_map(fn (string $dir): string => \substr($dir, \strlen($this->rootDir) + 1), false === $plugins ? [] : $plugins),
            ],
            fn (string $root): bool => \is_dir($this->rootDir.'/'.$root),
        );
        $files = [];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->rootDir.'/'.$root, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!\str_ends_with($file->getFilename(), 'Test.php')) {
                    continue;
                }
                $files[] = \substr($file->getPathname(), \strlen($this->rootDir) + 1);
            }
        }

        return $files;
    }

    /**
     * `composer test`'s flags, so a selected run is graded the way the suite is.
     *
     * @return list<string>
     */
    private static function suiteFlags(string $processes): array
    {
        return ['--processes='.$processes, '--functional', '--enforce-time-limit', '--default-time-limit=10'];
    }

    /**
     * @param list<string> $selected
     */
    private function parallelRun(OutputInterface $output, array $selected, string $processes): int
    {
        $configuration = $this->writeConfiguration($selected);
        if (null === $configuration) {
            return self::finish(self::errorOutput($output), 'test:affected: '.$this->rootDir.'/phpunit.xml.dist has no <testsuites> to replace.', Command::FAILURE);
        }

        try {
            return ($this->runner)([\PHP_BINARY, 'vendor/bin/paratest', '--configuration='.$configuration, ...self::suiteFlags($processes)], [], $output);
        } finally {
            \unlink($configuration);
        }
    }

    /**
     * A copy of `phpunit.xml.dist` whose one testsuite lists the selection,
     * written beside it so every relative path in it still resolves. Named by
     * pid, so two runs in one checkout cannot delete each other's. Null when
     * the original has no `<testsuites>` block to replace.
     *
     * @param list<string> $selected
     */
    private function writeConfiguration(array $selected): ?string
    {
        $source = $this->rootDir.'/phpunit.xml.dist';
        $files = \implode('', \array_map(static fn (string $file): string => '<file>'.\htmlspecialchars($file, \ENT_XML1).'</file>', $selected));
        $replaced = 0;
        $configuration = \preg_replace(
            '#<testsuites>.*</testsuites>#s',
            '<testsuites><testsuite name="Affected">'.$files.'</testsuite></testsuites>',
            \is_file($source) ? (string) \file_get_contents($source) : '',
            limit: 1,
            count: $replaced,
        );
        $path = null;
        if (1 === $replaced && \is_string($configuration)) {
            $path = $this->rootDir.'/.phpunit.affected-'.(int) \getmypid().'.xml';
            new Filesystem()->dumpFile($path, $configuration);
        }

        return $path;
    }

    /**
     * @param list<string> $selected
     * @param list<string> $changed
     */
    private function coverageRun(OutputInterface $output, OutputInterface $errors, array $selected, array $changed): int
    {
        $sources = \array_values(\array_filter(
            $changed,
            fn (string $path): bool => 1 === \preg_match(self::SOURCE_FILE, $path) && \is_file($this->rootDir.'/'.$path),
        ));
        $filters = [];
        foreach (\array_unique(\array_map(\dirname(...), $sources)) as $directory) {
            $filters[] = '--coverage-filter';
            $filters[] = $directory;
        }
        new Filesystem()->remove($this->rootDir.'/'.self::CLOVER);

        $exit = ($this->runner)(
            [
                \PHP_BINARY,
                '-d',
                'pcov.enabled=1',
                '-d',
                'pcov.directory=.',
                '-d',
                'pcov.exclude=#/(vendor|tests)/#',
                'vendor/bin/phpunit',
                ...$selected,
                ...$filters,
                '--coverage-clover',
                self::CLOVER,
            ],
            // A serial run grades no merge, so the reasons tests/bootstrap.php
            // turns the container cache off under pcov do not apply (the same
            // call CoveragePluginsCommand makes for its shards).
            ['PORTA_CONTAINER_CACHE' => 'on'],
            $output,
        );

        $status = [] === $sources ? self::finish($output, 'No changed source file under src/ or a plugin\'s src/ to grade.', $exit) : null;
        $misses = null === $status ? $this->misses($sources) : [];
        $status ??= null === $misses
            ? self::finish($errors, 'test:affected: the run wrote no readable Clover report at '.self::CLOVER.'.', Command::FAILURE)
            : null;

        return $status ?? self::reportMisses($output, $misses ?? [], $exit);
    }

    /**
     * @param array<string, list<int>|null> $misses
     */
    private static function reportMisses(OutputInterface $output, array $misses, int $exit): int
    {
        $output->writeln('');
        foreach ($misses as $source => $lines) {
            $output->writeln(match (true) {
                null === $lines => \sprintf('  %s: no selected test loaded it', $source),
                [] === $lines => \sprintf('  %s: every line covered', $source),
                default => \sprintf('  %s: lines %s not covered', $source, \implode(', ', $lines)),
            });
        }
        // The gate counts every test in the tree; this counted a selection, so
        // a miss here may still be covered by a test that never names the class.
        $output->writeln('Covered or not by the SELECTED tests only — composer coverage:check grades the tree.');
        $uncovered = [] !== \array_filter($misses, static fn (?array $lines): bool => [] !== $lines);

        return match (true) {
            0 !== $exit => $exit,
            $uncovered => Command::FAILURE,
            default => Command::SUCCESS,
        };
    }

    /**
     * Each source's statement lines the report saw run zero times — null for
     * a source the report does not carry at all. Only `stmt` entries, for
     * CoverageCheckCommand's reason: a `method` entry's count is the method's
     * maximum, not its line's. Null when the report is unreadable.
     *
     * @param list<string> $sources
     *
     * @return array<string, list<int>|null>|null
     */
    private function misses(array $sources): ?array
    {
        $clover = $this->rootDir.'/'.self::CLOVER;
        $previous = \libxml_use_internal_errors(use_errors: true);
        $report = \is_file($clover) ? \simplexml_load_file($clover) : false;
        \libxml_use_internal_errors($previous);
        if (false === $report) {
            return null;
        }

        $byPath = [];
        foreach (\is_array($found = $report->xpath('//file')) ? $found : [] as $file) {
            $byPath[(string) $file['name']] = $file;
        }
        $misses = [];
        foreach ($sources as $source) {
            $file = $byPath[(string) \realpath($this->rootDir.'/'.$source)] ?? null;
            $misses[$source] = null === $file
                ? null
                : \array_map(
                    static fn (\SimpleXMLElement $line): int => (int) (string) $line['num'],
                    \is_array($entries = $file->xpath('line[@type="stmt"][@count="0"]')) ? $entries : [],
                );
        }

        return $misses;
    }

    /**
     * @param list<string> $args
     */
    private function realGit(array $args): ?string
    {
        $process = new Process(['git', ...$args], $this->rootDir);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    private function realRunner(array $argv, array $env, OutputInterface $output): int
    {
        return new Process($argv, $this->rootDir, $env, timeout: null)->run(static function (string $type, string $chunk) use ($output): void {
            $output->write($chunk);
        });
    }
}
