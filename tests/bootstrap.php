<?php

declare(strict_types=1);

use Porta\Tests\Support\LoopErrors;
use Revolt\EventLoop;

require __DIR__.'/../vendor/autoload.php';

require __DIR__.'/plugin-autoload.php';

// The pristine process PATH, snapshotted once before any test runs. Tests that
// set PATH=/nonexistent (to keep an Ide boot from spawning git/LSP) restore to
// THIS via the PreservesPath trait — never to getenv('PATH') at setUp time,
// which would inherit a prior test's corruption and leak "/nonexistent" into a
// later paratest worker method (→ a git test flashing "Git not available").
define('PORTA_TEST_PRISTINE_PATH', (string) getenv('PATH'));

// The same trick for PORTA_HOME, which steers BOTH the global plugin dir and —
// since the config layer grew a global scope — the global config file. A test
// that points it at a temp home and fails to put it back would have later tests
// reading a home that no longer exists. Empty when unset, which the trait
// turns back into a clear rather than a set-to-empty.
define('PORTA_TEST_PRISTINE_PORTA_HOME', (string) getenv('PORTA_HOME'));

// phpunit.xml.dist points PORTA_HOME at a COMMITTED fixture home, so the
// global config scope's file there is shared by every test in the suite and
// outlives the run. Tests pin their session scope to Project for that reason,
// but a stale file from a run that predates the pin — or from a hand-run
// experiment — would silently reconfigure every later boot, and the failure
// surfaces somewhere with no connection to the cause. Removing it here makes
// that class of mystery impossible to carry between runs.
$portaLeakedGlobalConfig = __DIR__.'/Support/fixtures/home/.porta/config.json';
if (is_file($portaLeakedGlobalConfig)) {
    unlink($portaLeakedGlobalConfig);
}

// Every Ide boot in the suite shares one container dump here instead of each
// recompiling (~124 ms) or touching the developer's real ~/.cache/porta.
// putenv() deliberately, so exec()'d subprocess tests inherit the pin.
$portaCacheDir = dirname(__DIR__).'/.phpunit.cache/container';
putenv('PORTA_CACHE_DIR='.$portaCacheDir);
$_ENV['PORTA_CACHE_DIR'] = $portaCacheDir;

// Coverage runs compile fresh instead: compiling autoloads every Porta class
// in every worker, so every src file lands in some worker's pcov data — the
// other half of this fix is `includeUncoveredFiles="false"` in
// phpunit.xml.dist, which keeps php-code-coverage's static analyser (whose
// executable-line map disagrees with pcov's on match expressions) out of the
// paratest merge entirely. It is also what collapses every worker's PathReducer
// base to `<repo>/src` so the coverage merge resolves at all (docs/internals/decisions/history-01.md,
// "The verify loop, measured").
//
// Both reasons are about the PARALLEL `src` run. A caller that knows it is
// neither parallel nor grading `src` — `composer coverage:plugins`, one serial
// process filtered to `plugins/` — says so by setting the variable itself, and
// then pays the container compile once instead of once per Ide boot. So does
// `composer coverage:src`, ~45s faster: `CoverageSweepExtension` loads every
// `src` class once per worker inside the first test's coverage window, which is
// the first reason kept for one compile instead of ~458; and `--require=src`
// fails a file absent from the report, so a worker's data lost in the merge
// leaves a file absent or a line uncovered — red at 100% either way, never a
// silent green. An explicit value always wins;
// the pcov sniff is only the default for a run that did not say (a scoped
// `composer coverage`, which no gate grades).
if (false === getenv('PORTA_CONTAINER_CACHE') && '1' === ini_get('pcov.enabled')) {
    putenv('PORTA_CONTAINER_CACHE=off');
}

// A loop-callback throw must fail a test loudly. Ide::run() installs its
// production handler (toast + error.log, loop survives) only when none is
// present — and under test this one is, so a throwing await() stays a
// failure instead of becoming a toast over a hung worker.
EventLoop::setErrorHandler(LoopErrors::rethrow());
