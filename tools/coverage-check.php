<?php

declare(strict_types=1);

/**
 * Coverage gate: parses a Clover XML report, prints a per-file table and
 * exits non-zero when total line coverage is below the threshold.
 *
 * Usage: php tools/coverage-check.php coverage/clover.xml 100
 */

[$_, $cloverPath, $threshold] = $argv + [null, 'coverage/clover.xml', '100'];
$threshold = (float) $threshold;

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage-check: {$cloverPath} not found — run `composer coverage` first.\n");
    exit(2);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string((string) file_get_contents($cloverPath));
libxml_clear_errors();
if (false === $xml) {
    fwrite(STDERR, "coverage-check: {$cloverPath} is not valid Clover XML.\n");
    exit(2);
}

$rows = [];
foreach ($xml->xpath('//file') as $file) {
    $metrics = $file->metrics;
    $statements = (int) $metrics['statements'];
    if (0 === $statements) {
        continue;
    }
    $covered = (int) $metrics['coveredstatements'];
    $rows[] = [
        'name' => basename(\dirname((string) $file['name'])).'/'.basename((string) $file['name']),
        'covered' => $covered,
        'statements' => $statements,
    ];
}

usort($rows, static fn (array $a, array $b) => $a['name'] <=> $b['name']);

$width = max(array_map(static fn (array $r) => \strlen($r['name']), $rows)) + 2;
foreach ($rows as $row) {
    printf(
        "  %-{$width}s %7.2f%% (%d/%d)\n",
        $row['name'],
        (100 * $row['covered']) / $row['statements'],
        $row['covered'],
        $row['statements'],
    );
}

$metrics = $xml->project->metrics;
$totalStatements = (int) $metrics['statements'];
$totalCovered = (int) $metrics['coveredstatements'];
$percentage = $totalStatements > 0 ? (100 * $totalCovered) / $totalStatements : 0.0;

printf("\n  TOTAL: %.2f%% (%d/%d) — required: %.2f%%\n", $percentage, $totalCovered, $totalStatements, $threshold);

if (($percentage + 1e-9) < $threshold) {
    fwrite(STDERR, "\ncoverage-check: FAILED — below the {$threshold}% line-coverage gate.\n");
    exit(1);
}

echo "\ncoverage-check: OK\n";
