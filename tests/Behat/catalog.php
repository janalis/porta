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
$root = dirname(__DIR__, 2);
$files = new AppendIterator();
$pluginFeatures = glob($root.'/plugins/*/tests/features', GLOB_ONLYDIR);
foreach ([__DIR__.'/features', ...(false === $pluginFeatures ? [] : $pluginFeatures)] as $dir) {
    $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)));
}
/** @var SplFileInfo $file */
foreach ($files as $file) {
    if ('feature' !== $file->getExtension()) {
        continue;
    }
    $path = $file->getPathname();
    $features[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
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
