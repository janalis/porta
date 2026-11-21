<?php

declare(strict_types=1);

/*
 * `composer behat:catalog`: fails unless every action, command and setting is
 * driven by a feature, or exempted in uncoverable.php with the reason.
 */

use Porta\Tests\Behat\Catalog\Catalog;
use Porta\Tests\Behat\Catalog\Coverage;

require __DIR__.'/bootstrap.php';

$features = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/features', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || 'feature' !== $file->getExtension()) {
        continue;
    }
    $path = $file->getPathname();
    $features[substr($path, strlen(dirname(__DIR__, 2)) + 1)] = (string) file_get_contents($path);
}
ksort($features);

/** @var array<string, string> $exemptions */
$exemptions = require __DIR__.'/uncoverable.php';
$catalog = Catalog::read(dirname(__DIR__, 2).'/plugins');
$coverage = new Coverage($catalog, $features, $exemptions);

$total = array_sum(array_map(count(...), $catalog));
$report = [
    'No feature drives' => $coverage->missing,
    'Not in the catalog' => $coverage->unknown,
    'Stale exemptions (covered, gone, or without a reason)' => $coverage->staleExemptions,
];
foreach ($report as $title => $lines) {
    if ([] === $lines) {
        continue;
    }
    fwrite(STDOUT, sprintf("%s (%d):\n", $title, count($lines)));
    foreach ($lines as $line) {
        fwrite(STDOUT, '  '.$line."\n");
    }
}
fwrite(STDOUT, sprintf("%d of %d catalog ids covered, %d exempted.\n", $total - count($coverage->missing) - count($exemptions), $total, count($exemptions)));

exit($coverage->passes() ? 0 : 1);
