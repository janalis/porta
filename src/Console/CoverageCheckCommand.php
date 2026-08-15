<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * The coverage gate: parses a Clover XML report, prints the per-file table and
 * fails when total line coverage is below the threshold.
 *
 * Runs from the dev console (`bin/porta-dev`, via `composer coverage:check`)
 * rather than `bin/porta` — a maintainer gate, not an IDE feature. The exit
 * codes are the contract CI reads: 2 for unusable input, 1 for a real
 * shortfall. Every read of the document goes through {@see element()} /
 * {@see number()} so a malformed report degrades to zeros instead of
 * exploding on a missing node.
 */
#[AsCommand(name: 'coverage:check', description: 'Fail when Clover line coverage is below a threshold')]
final class CoverageCheckCommand extends Command
{
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
        #[Argument('Path to the Clover XML report', name: 'clover')]
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
        $threshold = is_numeric($thresholdArgument) ? (float) $thresholdArgument : 0.0;
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if (!is_file($cloverPath)) {
            $errors->writeln("coverage-check: {$cloverPath} not found — run `composer coverage` first.");

            return Command::INVALID;
        }

        // Parse errors belong to us, not to PHP's global error log — and the
        // previous setting is restored so a caller's choice survives.
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) file_get_contents($cloverPath));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (false === $xml) {
            $errors->writeln("coverage-check: {$cloverPath} is not valid Clover XML.");

            return Command::INVALID;
        }

        $rows = $this->rows($xml, $only);
        $io = new SymfonyStyle($input, $output);
        $io->table(['File', 'Coverage'], array_map(static fn (array $row): array => [
            $row['name'],
            \sprintf('%.2f%% (%d/%d)', (100 * $row['covered']) / $row['statements'], $row['covered'], $row['statements']),
        ], $rows));

        // A scoped run sums the rows it kept; an unscoped one reads the
        // report's own totals, which also count files with nothing executable.
        $totals = self::element($xml, '/coverage/project/metrics');
        $totalStatements = null === $only ? self::number($totals, 'statements') : array_sum(array_column($rows, 'statements'));
        $totalCovered = null === $only ? self::number($totals, 'coveredstatements') : array_sum(array_column($rows, 'covered'));
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

        if (($percentage + 1e-9) < $threshold) {
            $errors->writeln('');
            $errors->writeln("coverage-check: FAILED — below the {$threshold}% line-coverage gate.");

            return Command::FAILURE;
        }

        // The arch gate's doctrine applies to this allowlist too: an excuse
        // whose file is gone from the tree entirely is dead config, and a
        // stale allowlist is not a gate. (An entry merely PRESENT in this
        // run's report stays legitimate — a machine without the wrapped
        // binary still needs it.)
        $stale = null === $require ? [] : array_values(array_filter($this->mayBeAbsent, static fn (string $path): bool => !is_file($path)));
        if ([] !== $stale) {
            $errors->writeln('');
            $errors->writeln('coverage-check: FAILED — MAY_BE_ABSENT excuses files that no longer exist; remove the entries:');
            foreach ($stale as $path) {
                $errors->writeln('  '.$path);
            }

            return Command::FAILURE;
        }

        $absent = null === $require ? [] : $this->absentFrom($xml, $require);
        if ([] !== $absent) {
            $errors->writeln('');
            $errors->writeln('coverage-check: FAILED — these files are absent from the report entirely, so nothing loaded them:');
            foreach ($absent as $path) {
                $errors->writeln('  '.$path);
            }

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('coverage-check: OK');

        return Command::SUCCESS;
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
     * @return list<string> project-relative, sorted
     */
    private function absentFrom(\SimpleXMLElement $xml, string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $graded = [];
        foreach (\is_array($files = $xml->xpath('//file')) ? $files : [] as $file) {
            $graded[(string) $file['name']] = true;
        }

        $root = getcwd();
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
            $relative = false === $root ? $path : str_replace($root.'/', replace: '', subject: $path);
            // Suffix-matched, not equality: the gate runs from the project root
            // where the path is already relative, but nothing should depend on
            // the caller's working directory to decide what is excused.
            $excused = array_filter($this->mayBeAbsent, static fn (string $tail): bool => str_ends_with($relative, $tail));
            if (\array_key_exists((string) realpath($path), $graded) || [] !== $excused) {
                continue;
            }
            $absent[] = $relative;
        }
        sort($absent);

        return $absent;
    }

    /**
     * One row per covered file, sorted by display name; files with no
     * executable statements are skipped (they cannot be under-covered), as are
     * files outside $only when a scope is given.
     *
     * Anything under a `vendor/` directory is skipped outright: a plugin may
     * ship its composer dependencies beside its own code, and the plugin pass
     * scopes by the `/plugins/` path prefix, which would otherwise pull a
     * third party's uncovered lines into our 100% gate.
     *
     * @return list<array{name: string, covered: int, statements: int}>
     */
    private function rows(\SimpleXMLElement $xml, ?string $only = null): array
    {
        $files = $xml->xpath('//file');
        $rows = [];
        foreach (\is_array($files) ? $files : [] as $file) {
            $metrics = self::element($file, 'metrics');
            $statements = self::number($metrics, 'statements');
            $path = (string) $file['name'];
            if (0 === $statements || str_contains($path, needle: '/vendor/') || null !== $only && !str_contains($path, needle: $only)) {
                continue;
            }
            $rows[] = [
                'name' => self::displayName($path),
                'covered' => self::number($metrics, 'coveredstatements'),
                'statements' => $statements,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
    }

    /**
     * Enough of the path to know which file it is: the last two segments, which
     * for `src/` reads as `Workbench/View/ViewRegistry.php`. Plugin-owned code is
     * named from its plugin instead — `src/ArchitectureArtifact.php` would read
     * as core's `src/`, and which plugin owns a file is the interesting half.
     */
    private static function displayName(string $path): string
    {
        $at = strpos($path, needle: '/plugins/');

        return false === $at ? basename(\dirname($path)).'/'.basename($path) : substr($path, $at + 9);
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
        $value = null === $metrics ? null : $metrics->attributes();

        return null === $value || null === $value[$attribute] ? 0 : (int) (string) $value[$attribute];
    }
}
