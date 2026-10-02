<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Loads every `src` class once per worker, inside a coverage window of its own,
 * when `PORTA_COVERAGE_SWEEP=1` (set by `composer coverage:src`).
 *
 * pcov only enters a file in a worker's data if the file was compiled while
 * collection was on, and with `includeUncoveredFiles="false"` a file in no
 * worker's data is absent from the report. Each Ide boot used to recompile the
 * container, which loaded every class inside some test; with the container
 * cache on, a class first loaded outside collection — by a data provider, say —
 * in every worker went missing, and `--require=src` failed the gate on it
 * (docs/internals/decisions/history-08.md, "The two coverage passes run at
 * once"). One sweep per worker restores what the recompile guaranteed, for the
 * price of one compile instead of ~458.
 *
 * The window is its own, not the first test's: PHPUnit discards a test's data
 * when the test is skipped, risky or incomplete, and a file compiled in a
 * discarded window never enters that worker's data again. `ExecutionStarted`
 * comes after coverage is initialised and before any test opens a window, in a
 * paratest worker and in a plain run alike. Off by default: `composer mutate` maps lines to
 * the tests that ran them, and the sweep would hand every file to one test.
 */
final class CoverageSweepExtension implements Extension
{
    private static bool $swept = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if ('1' !== \getenv('PORTA_COVERAGE_SWEEP')) {
            return;
        }
        $facade->registerSubscriber(new class implements ExecutionStartedSubscriber {
            public function notify(ExecutionStarted $event): void
            {
                CoverageSweepExtension::sweep();
            }
        });
    }

    /** Every class under `src`, by the path its FQCN is determined by. */
    public static function sweep(): void
    {
        $coverage = CodeCoverage::instance();
        if (self::$swept || !$coverage->isActive()) {
            return;
        }
        self::$swept = true;
        $coverage->codeCoverage()->start('coverage sweep');
        try {
            self::loadEverything();
        } finally {
            $coverage->codeCoverage()->stop();
        }
    }

    private static function loadEverything(): void
    {
        $src = \dirname(__DIR__, levels: 2).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $class = 'Porta\\'.\str_replace('/', replace: '\\', subject: \substr($file->getPathname(), \strlen($src) + 1, -4));
            // Autoloading is the point. A file whose path names no symbol would
            // escape the sweep silently, so it stops the run instead.
            if (!\class_exists($class) && !\interface_exists($class) && !\trait_exists($class) && !\enum_exists($class)) {
                throw new \LogicException($file->getPathname().' does not declare '.$class.', so the coverage sweep cannot load it.');
            }
        }
    }
}
