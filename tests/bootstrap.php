<?php

declare(strict_types=1);

use Porta\Support\Files;
use Porta\Tests\Support\LoopErrors;
use Revolt\EventLoop;
use Symfony\Component\Filesystem\Filesystem;

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
// The trust answers are the same kind of file: one written there would trust a
// project, or keep one restricted, for every later run.
foreach (['config.json', 'trusted-projects.json'] as $portaLeaked) {
    if (!is_file(__DIR__.'/Support/fixtures/home/.porta/'.$portaLeaked)) {
        continue;
    }
    unlink(__DIR__.'/Support/fixtures/home/.porta/'.$portaLeaked);
}

// Every booted Ide starts the background tool-update check, which is due on a
// home that never ran it — and running it is a request to Packagist, GitHub,
// crates.io and npm from the middle of the suite. A note saying "checked just
// now" keeps it quiet for the run; the check's own tests use a home of their own.
file_put_contents(__DIR__.'/Support/fixtures/home/.porta/ide-updates.json', json_encode(['checkedAt' => time()], JSON_THROW_ON_ERROR));
file_put_contents(__DIR__.'/Support/fixtures/home/.porta/tool-updates.json', json_encode(['checkedAt' => time(), 'latest' => []], JSON_THROW_ON_ERROR));
// The plugin-update check's note is the store's own catalog: an empty one
// fetched just now keeps every booted Ide off Packagist the same way.
$portaStoreCache = __DIR__.'/Support/fixtures/home/.porta/cache/store';
if (!is_dir($portaStoreCache) && !mkdir($portaStoreCache, recursive: true) && !is_dir($portaStoreCache)) {
    throw new RuntimeException('Cannot create '.$portaStoreCache);
}
file_put_contents($portaStoreCache.'/catalog.json', json_encode(['fetchedAt' => time(), 'entries' => []], JSON_THROW_ON_ERROR));

// Every TempProject lives under the temp dir, and nearly every scenario writes
// a `.porta.json` that enables a plugin or names a server: all of them are the
// suite's own, so all of them are trusted. A test of the untrusted path builds
// its own WorkspaceTrust, or clears this. putenv() so subprocesses inherit it.
$portaTrustedFolders = (string) realpath(sys_get_temp_dir());
define('PORTA_TEST_TRUSTED_FOLDERS', $portaTrustedFolders);
putenv('PORTA_TRUSTED_FOLDERS='.$portaTrustedFolders);

// The mcp plugin is bundled with `claudeCode` on, so every booted Ide that
// enables it writes a lock file where the claude CLI looks for editors — the
// developer's real ~/.claude/ide, where a live Claude Code would see the
// suite's IDEs, and where a test that reads the folder raced the real
// sessions' locks. Each worker gets its own, made here and removed at exit.
$claudeConfigDir = sys_get_temp_dir().'/porta-test-claude-'.bin2hex(random_bytes(6));
Files::ensureDir($claudeConfigDir.'/ide', permissions: 0o700);
putenv('CLAUDE_CONFIG_DIR='.$claudeConfigDir);
$_ENV['CLAUDE_CONFIG_DIR'] = $claudeConfigDir;
register_shutdown_function(static function () use ($claudeConfigDir): void {
    new Filesystem()->remove($claudeConfigDir);
});

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
