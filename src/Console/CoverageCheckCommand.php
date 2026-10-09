<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * The coverage gate: parses a Clover XML report — or a DIRECTORY of them, which
 * is what the sharded plugin pass writes — prints the per-file table and fails
 * when total line coverage is below the threshold.
 *
 * Several reports are merged per LINE, not per file: booting an IDE loads every
 * default-enabled plugin, so one shard's report holds files another shard also
 * covered, and adding their rows would count one file's statements once per
 * shard. A file only one report carries keeps that report's own numbers, which
 * is the single-report path unchanged.
 *
 * Runs from the dev console (`bin/porta-dev`, via `composer coverage:check`)
 * rather than `bin/porta` — a maintainer gate, not an IDE feature. The exit
 * codes are the contract CI reads: 2 for unusable input, 1 for a real
 * shortfall. Every read of the document goes through {@see element()} /
 * {@see number()} so a malformed report degrades to zeros instead of
 * exploding on a missing node.
 */
#[AsCommand(
    name: 'coverage:check',
    description: 'Fail when Clover line coverage is below a threshold',
    help: <<<'HELP'
        Parses a Clover XML report, prints the per-file coverage table, and fails
        (exit 1) when total line coverage is below the threshold, or (exit 2)
        when the report is missing or unusable.

          <info>coverage:check</info>                              coverage/clover.xml against 100%
          <info>coverage:check coverage/plugins 100 --only=/plugins/</info>
                                                          grade only plugin files, every shard's report merged
          <info>coverage:check --require=plugins</info>  fail if a .php file under plugins/ is absent from the report

        A directory argument grades every *.xml in it as ONE report, merged per line.
        HELP,
    usages: ['coverage/clover.xml 100', '--only=/plugins/ --require=plugins', 'coverage/plugins 100 --only=/plugins/'],
)]
final class CoverageCheckCommand extends Command
{
    use CommandErrorStream;

    /** @var list<string> */
    private readonly array $mayBeAbsent;

    /** @param list<string>|null $mayBeAbsent the excuse list; null uses {@see MAY_BE_ABSENT} (the ArchitectureCheckCommand pin-injection pattern) */
    public function __construct(?array $mayBeAbsent = null)
    {
        parent::__construct();
        $this->mayBeAbsent = $mayBeAbsent ?? self::MAY_BE_ABSENT;
    }

    /**
     * Files whose absence from the report is not a gap.
     *
     * Each is the entry point of a plugin that wraps an EXTERNAL binary, and its
     * tests call `skipWithoutBinary()`. On a machine without that tool the tests
     * skip, `plugin.php` is never required, and pcov has nothing to record — so
     * the file is legitimately missing. On a machine that has the tool it is
     * present and graded like anything else; this list permits an absence, it
     * does not excuse a shortfall.
     *
     * Anything NOT listed here that vanishes from the report is a real gap: a
     * file nothing loads is a file nothing tests.
     *
     * @var list<string>
     */
    private const array MAY_BE_ABSENT = [
        'plugins/chroma/plugin.php',
        'plugins/mago/plugin.php',
        'plugins/php-cs-fixer/plugin.php',
        'plugins/phpactor/plugin.php',
        'plugins/phpantom/plugin.php',
        'plugins/phpstan/plugin.php',
        'plugins/psalm/plugin.php',
    ];

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Path to the Clover XML report, or a directory of them', name: 'clover')]
        string $cloverPath = 'coverage/clover.xml',
        #[Argument('Required line-coverage percentage', name: 'threshold')]
        string $thresholdArgument = '100',
        #[Option('Grade only files whose path contains this string')]
        ?string $only = null,
        #[Option('Fail when a .php file under this directory is absent from the report')]
        ?string $require = null,
    ): int {
        // A non-numeric threshold reads as 0.0 — the same tolerance the old
        // positional-argv script had, rather than a new failure mode.
        $threshold = \is_numeric($thresholdArgument) ? (float) $thresholdArgument : 0.0;
        $errors = self::errorOutput($output);

        $reports = self::reports($cloverPath);
        /** @var array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions */
        $contributions = [];
        /** @var array<string, true> $mentioned every file name any report carries, scope or no scope */
        $mentioned = [];
        $sole = null;

        // Each step reports its own failure and yields its exit code; null
        // means it passed and the next one runs.
        $status = [] === $reports ? self::noReport($errors, $cloverPath) : null;
        $status ??= self::collectAll($reports, $only, $errors, $contributions, $mentioned, $sole);
        $status ??= self::lossyFailure($errors, $contributions);
        $status ??= self::grade($input, $output, $errors, $contributions, $sole, $only, $threshold);
        $status ??= $this->staleFailure($errors, $require);
        $status ??= null === $require ? null : $this->absentFailure($errors, $mentioned, $require);
        if (null === $status) {
            $output->writeln('');
            $output->writeln('coverage-check: OK');
            $status = Command::SUCCESS;
        }

        return $status;
    }

    /** No report to grade: the path is missing, or a directory with no `*.xml`. */
    private static function noReport(OutputInterface $errors, string $cloverPath): int
    {
        $errors->writeln(
            \is_dir($cloverPath)
                ? "coverage-check: {$cloverPath} holds no *.xml report — run `composer coverage` first."
                : "coverage-check: {$cloverPath} not found — run `composer coverage` first.",
        );

        return Command::INVALID;
    }

    /**
     * Reads every report into $contributions and $mentioned; INVALID at the
     * first one that is not Clover XML.
     *
     * @param list<string>                                                                      $reports
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     * @param array<string, true>                                                               $mentioned
     */
    private static function collectAll(
        array $reports,
        ?string $only,
        OutputInterface $errors,
        array &$contributions,
        array &$mentioned,
        ?\SimpleXMLElement &$sole,
    ): ?int {
        $status = null;
        foreach ($reports as $report) {
            // Parse errors belong to us, not to PHP's global error log — and the
            // previous setting is restored so a caller's choice survives.
            $previous = \libxml_use_internal_errors(true);
            $xml = \simplexml_load_string((string) \file_get_contents($report));
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
            if (false === $xml) {
                $errors->writeln("coverage-check: {$report} is not valid Clover XML.");
                $status = Command::INVALID;
                break;
            }
            // One report keeps its own document: the unscoped total is read
            // from `/coverage/project/metrics` below, which has no meaning
            // across several (it would count each shard's `src/` rows again).
            $sole = 1 === \count($reports) ? $xml : null;
            self::collect($xml, $only, $contributions, $mentioned);
        }

        return $status;
    }

    /** @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions */
    private static function lossyFailure(OutputInterface $errors, array $contributions): ?int
    {
        $lossy = self::lossy($contributions);
        if ([] === $lossy) {
            return null;
        }
        $errors->writeln('');
        $errors->writeln('coverage-check: FAILED — these files are split across reports and Clover cannot merge them losslessly; grade them in one report:');
        foreach ($lossy as $path) {
            $errors->writeln('  '.$path);
        }

        return Command::INVALID;
    }

    /**
     * Prints the per-file table and the total; FAILURE when the total is below
     * the threshold.
     *
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     */
    private static function grade(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errors,
        array $contributions,
        ?\SimpleXMLElement $sole,
        ?string $only,
        float $threshold,
    ): ?int {
        $rows = self::rows($contributions);
        $io = new SymfonyStyle($input, $output);
        $io->table(['File', 'Coverage'], \array_map(static fn (array $row): array => [
            $row['name'],
            \sprintf('%.2f%% (%d/%d)', (100 * $row['covered']) / $row['statements'], $row['covered'], $row['statements']),
        ], $rows));

        // A scoped run sums the rows it kept; an unscoped one over a SINGLE
        // report reads that report's own totals, which also count files with
        // nothing executable — and, if one ever reached the report, vendored
        // ones, which the rows skip. That difference is why `composer coverage`
        // excludes vendor at the PCOV level rather than leaving it to this:
        // third-party lines in an unscoped total dilute the gate.
        $totals = null === $sole ? null : self::element($sole, '/coverage/project/metrics');
        $ownTotals = null === $only && null !== $sole;
        $totalStatements = $ownTotals ? self::number($totals, 'statements') : \array_sum(\array_column($rows, 'statements'));
        $totalCovered = $ownTotals ? self::number($totals, 'coveredstatements') : \array_sum(\array_column($rows, 'covered'));
        $percentage = $totalStatements > 0 ? (100 * $totalCovered) / $totalStatements : 0.0;

        $output->writeln('');
        $output->writeln(\sprintf(
            '  TOTAL%s: %.2f%% (%d/%d) — required: %.2f%%',
            null === $only ? '' : ' ('.$only.')',
            $percentage,
            $totalCovered,
            $totalStatements,
            $threshold,
        ));

        if (($percentage + 1e-9) >= $threshold) {
            return null;
        }
        $errors->writeln('');
        $errors->writeln("coverage-check: FAILED — below the {$threshold}% line-coverage gate.");
        // The lines, not only the percentages: a CI log is often all there
        // is of a failed run (its report artifact may never upload), and a
        // file at 98.59% says nothing about where to look.
        foreach (self::misses($contributions) as $name => $lines) {
            $errors->writeln(\sprintf('  %s: %s', $name, \implode(', ', $lines)));
        }

        return Command::FAILURE;
    }

    /**
     * The arch gate's doctrine applies to this allowlist too: an excuse
     * whose file is gone from the tree entirely is dead config, and a
     * stale allowlist is not a gate. (An entry merely PRESENT in this
     * run's report stays legitimate — a machine without the wrapped
     * binary still needs it.)
     */
    private function staleFailure(OutputInterface $errors, ?string $require): ?int
    {
        $stale = null === $require ? [] : \array_values(\array_filter($this->mayBeAbsent, static fn (string $path): bool => !\is_file($path)));
        if ([] === $stale) {
            return null;
        }
        $errors->writeln('');
        $errors->writeln('coverage-check: FAILED — MAY_BE_ABSENT excuses files that no longer exist; remove the entries:');
        foreach ($stale as $path) {
            $errors->writeln('  '.$path);
        }

        return Command::FAILURE;
    }

    /** @param array<string, true> $mentioned */
    private function absentFailure(OutputInterface $errors, array $mentioned, string $require): ?int
    {
        $absent = $this->absentFrom($mentioned, $require);
        if ([] === $absent) {
            return null;
        }
        $errors->writeln('');
        $errors->writeln('coverage-check: FAILED — these files are absent from the report entirely, so nothing loaded them:');
        foreach ($absent as $path) {
            $errors->writeln('  '.$path);
        }

        return Command::FAILURE;
    }

    /**
     * The `.php` files under $directory that the report never mentions.
     *
     * A percentage cannot catch these. Clover is configured with
     * `includeUncoveredFiles="false"`, so a file no test ever loads is not a
     * 0% row — it is not a row at all, and {@see rows()} grades what it finds.
     * `src/` is safe by construction (compiling the container touches every
     * file), but plugin code is loaded only when a plugin actually activates,
     * which is exactly the guarantee a file moving INTO a plugin loses.
     *
     * Vendored and test files are not coverage subjects, matching {@see rows()}.
     *
     * @param array<string, true> $graded every file name the reports mention — the union
     *                                    across them, and unscoped: a file absent from
     *                                    every report is what this looks for, and a
     *                                    `--only` scope must not excuse it
     *
     * @return list<string> project-relative, sorted
     */
    private function absentFrom(array $graded, string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $root = \getcwd();
        $absent = [];
        // Segment-anchored, so a class merely *named* Vendor/Tests stays a
        // coverage subject; dot files stay visible like the raw walker's did
        // (the regex name form, because Finder's '*.php' glob follows shell
        // semantics and never matches a leading dot).
        $tree = new Finder()
            ->files()
            ->in($directory)
            ->name('/\.php$/')
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->notPath('~(^|/)vendor/~')
            ->notPath('~(^|/)tests/~');
        foreach ($tree as $entry) {
            $path = $entry->getPathname();
            $relative = false === $root ? $path : \str_replace($root.'/', replace: '', subject: $path);
            // Suffix-matched, not equality: the gate runs from the project root
            // where the path is already relative, but nothing should depend on
            // the caller's working directory to decide what is excused.
            $excused = \array_filter($this->mayBeAbsent, static fn (string $tail): bool => \str_ends_with($relative, $tail));
            if (\array_key_exists((string) \realpath($path), $graded) || [] !== $excused) {
                continue;
            }
            $absent[] = $relative;
        }
        \sort($absent);

        return $absent;
    }

    /**
     * One row per covered file, sorted by display name; what is skipped and why
     * is {@see collect()}, which does the skipping as it reads.
     *
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     *
     * @return list<array{name: string, covered: int, statements: int}>
     */
    private static function rows(array $contributions): array
    {
        $rows = [];
        foreach ($contributions as $path => $records) {
            $first = $records[0];
            $rows[] = [
                'name' => self::displayName($path),
                // A file only one report carries keeps that report's own count,
                // which is the whole single-report path unchanged. A file
                // several carry is the UNION of the lines they hit: booting an
                // IDE loads every default-enabled plugin, so a shard's report
                // holds files another shard also covered, and summing them
                // would count one file's statements once per shard.
                'covered' => 1 === \count($records) ? $first['covered'] : self::unionCovered($records),
                'statements' => $first['statements'],
            ];
        }
        \usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }

    /**
     * The rows one report contributes, folded into $contributions, and every
     * file it mentions into $mentioned.
     *
     * Files with no executable statements are skipped (they cannot be
     * under-covered), as are files outside $only when a scope is given —
     * during the walk rather than after it, because a sharded plugin run
     * carries all of `src/` in every report and only the `/plugins/` rows are
     * ever graded.
     *
     * Anything under a `vendor/` directory is skipped outright: a plugin may
     * ship its composer dependencies beside its own code, and the plugin pass
     * scopes by the `/plugins/` path prefix, which would otherwise pull a
     * third party's uncovered lines into our 100% gate.
     *
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     * @param array<string, true>                                                               $mentioned
     */
    private static function collect(\SimpleXMLElement $xml, ?string $only, array &$contributions, array &$mentioned): void
    {
        foreach (\is_array($files = $xml->xpath('//file')) ? $files : [] as $file) {
            $path = (string) $file['name'];
            $mentioned[$path] = true;
            $metrics = self::element($file, 'metrics');
            $statements = self::number($metrics, 'statements');
            if (0 === $statements || \str_contains($path, needle: '/vendor/') || (null !== $only && !\str_contains($path, needle: $only))) {
                continue;
            }
            $contributions[$path][] = [
                'statements' => $statements,
                'covered' => self::number($metrics, 'coveredstatements'),
                'lines' => self::statementLines($file),
            ];
        }
    }

    /**
     * Every report to grade: the file itself, or every `*.xml` directly inside
     * a directory of them — the shape the sharded plugin pass writes, one
     * report per shard. A path that is neither is an empty list, and the
     * caller tells the two apart for the message.
     *
     * @return list<string> in `glob()`'s order, which is sorted unless asked otherwise
     */
    private static function reports(string $path): array
    {
        if (\is_file($path)) {
            return [$path];
        }
        $found = \is_dir($path) ? \glob(\rtrim($path, characters: '/').'/*.xml') : false;

        return \is_array($found) ? \array_values($found) : [];
    }

    /**
     * A statement line's hit count, by line number.
     *
     * Only `type="stmt"` entries: Clover writes a method's declaration line as
     * `type="method"` whose count is the MAX over the method's whole range, so
     * a method entry is not one line's truth and cannot be merged.
     *
     * @return array<int, int>
     */
    private static function statementLines(\SimpleXMLElement $file): array
    {
        $lines = [];
        foreach (\is_array($found = $file->xpath('line')) ? $found : [] as $line) {
            if ('stmt' !== (string) $line['type']) {
                continue;
            }
            $lines[(int) (string) $line['num']] = (int) (string) $line['count'];
        }

        return $lines;
    }

    /**
     * Each under-covered file's statement lines that no report saw executed,
     * by display name; files whose reports carry no `<line>` entries are left
     * out, since there is nothing to name.
     *
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     *
     * @return array<string, list<int>>
     */
    private static function misses(array $contributions): array
    {
        $misses = [];
        foreach ($contributions as $path => $records) {
            $seen = [];
            $hit = [];
            foreach ($records as $record) {
                foreach ($record['lines'] as $number => $count) {
                    $seen[$number] = true;
                    if ($count > 0) {
                        $hit[$number] = true;
                    }
                }
            }
            $missed = \array_keys(\array_diff_key($seen, $hit));
            if ([] === $missed) {
                continue;
            }
            \sort($missed);
            $misses[self::displayName($path)] = $missed;
        }
        \ksort($misses);

        return $misses;
    }

    /**
     * How many of a file's statement lines any report saw executed.
     *
     * @param list<array{statements: int, covered: int, lines: array<int, int>}> $records
     */
    private static function unionCovered(array $records): int
    {
        $hit = [];
        foreach ($records as $record) {
            foreach ($record['lines'] as $number => $count) {
                if ($count <= 0) {
                    continue;
                }
                $hit[$number] = true;
            }
        }

        return \count($hit);
    }

    /**
     * Files that appear in more than one report and whose `<line>` entries
     * cannot reconstruct their own metrics — so the union above would grade
     * them on an incomplete map.
     *
     * It happens when a method's declaration line is itself executable: Clover
     * emits it as a `method` entry and omits the `stmt` one, and both the
     * statement and its true count are then unrecoverable from the report.
     * Today no file under `plugins/` is affected and every `src/` file is
     * graded by the single-report pass, so this is a guard, not a workaround —
     * it fails loudly rather than quietly grading a shrunken denominator.
     *
     * @param array<string, list<array{statements: int, covered: int, lines: array<int, int>}>> $contributions
     *
     * @return list<string> sorted
     */
    private static function lossy(array $contributions): array
    {
        $lossy = [];
        foreach ($contributions as $path => $records) {
            if (1 === \count($records)) {
                continue;
            }
            // The line numbers as one string, because two reports of the same
            // file must agree on WHICH lines are statements, not merely on how
            // many — a union over two different maps can report more covered
            // lines than the file has.
            $signature = null;
            foreach ($records as $record) {
                $mine = \array_keys($record['lines']);
                \sort($mine);
                $theirs = \implode(',', $mine);
                if ((null !== $signature && $signature !== $theirs) || \count($mine) !== $record['statements']) {
                    $lossy[] = $path;
                    break;
                }
                $signature = $theirs;
            }
        }
        \sort($lossy);

        return $lossy;
    }

    /**
     * Enough of the path to know which file it is: the last two segments, which
     * for `src/` reads as `Workbench/View/ViewRegistry.php`. Plugin-owned code is
     * named from its plugin instead — `src/ArchitectureArtifact.php` would read
     * as core's `src/`, and which plugin owns a file is the interesting half.
     */
    private static function displayName(string $path): string
    {
        $at = \strpos($path, needle: '/plugins/');

        return false === $at ? \basename(\dirname($path)).'/'.\basename($path) : \substr($path, $at + 9);
    }

    /** The first node matching $path, or null when the document has none. */
    private static function element(\SimpleXMLElement $root, string $path): ?\SimpleXMLElement
    {
        $found = $root->xpath($path);

        return \is_array($found) && [] !== $found ? $found[0] : null;
    }

    /** A Clover count, read from an XML attribute; a missing node or attribute is zero. */
    private static function number(?\SimpleXMLElement $metrics, string $attribute): int
    {
        $value = $metrics?->attributes();

        return null === $value || null === $value[$attribute] ? 0 : (int) (string) $value[$attribute];
    }
}
