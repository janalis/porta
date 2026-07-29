<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
    protected function configure(): void
    {
        $this
            ->addArgument('clover', InputArgument::OPTIONAL, 'Path to the Clover XML report', 'coverage/clover.xml')
            ->addArgument('threshold', InputArgument::OPTIONAL, 'Required line-coverage percentage', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $cloverPath */
        $cloverPath = $input->getArgument('clover');
        /** @var string $thresholdArgument */
        $thresholdArgument = $input->getArgument('threshold');
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

        $rows = $this->rows($xml);
        $width = ([] === $rows ? 0 : max(array_map(static fn (array $row): int => \strlen($row['name']), $rows))) + 2;
        foreach ($rows as $row) {
            $output->writeln(\sprintf(
                "  %-{$width}s %7.2f%% (%d/%d)",
                $row['name'],
                (100 * $row['covered']) / $row['statements'],
                $row['covered'],
                $row['statements'],
            ));
        }

        $totals = self::element($xml, '/coverage/project/metrics');
        $totalStatements = self::number($totals, 'statements');
        $totalCovered = self::number($totals, 'coveredstatements');
        $percentage = $totalStatements > 0 ? (100 * $totalCovered) / $totalStatements : 0.0;

        $output->writeln('');
        $output->writeln(\sprintf('  TOTAL: %.2f%% (%d/%d) — required: %.2f%%', $percentage, $totalCovered, $totalStatements, $threshold));

        if (($percentage + 1e-9) < $threshold) {
            $errors->writeln('');
            $errors->writeln("coverage-check: FAILED — below the {$threshold}% line-coverage gate.");

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('coverage-check: OK');

        return Command::SUCCESS;
    }

    /**
     * One row per covered file, sorted by display name; files with no
     * executable statements are skipped (they cannot be under-covered).
     *
     * @return list<array{name: string, covered: int, statements: int}>
     */
    private function rows(\SimpleXMLElement $xml): array
    {
        $files = $xml->xpath('//file');
        $rows = [];
        foreach (\is_array($files) ? $files : [] as $file) {
            $metrics = self::element($file, 'metrics');
            $statements = self::number($metrics, 'statements');
            if (0 === $statements) {
                continue;
            }
            $name = (string) $file['name'];
            $rows[] = [
                'name' => basename(\dirname($name)).'/'.basename($name),
                'covered' => self::number($metrics, 'coveredstatements'),
                'statements' => $statements,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $rows;
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
