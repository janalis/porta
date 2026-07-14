<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Every Ide boot in the suite shares one container dump here instead of each
// recompiling (~124 ms) or touching the developer's real ~/.cache/porta.
// putenv() deliberately, so exec()'d subprocess tests inherit the pin.
$portaCacheDir = dirname(__DIR__).'/.phpunit.cache/container';
putenv('PORTA_CACHE_DIR='.$portaCacheDir);
$_ENV['PORTA_CACHE_DIR'] = $portaCacheDir;

// Coverage runs compile fresh instead: compiling autoloads every Porta class
// in every worker, keeping pcov's runtime line maps authoritative everywhere.
// A dump-served worker never loads rarely-used classes (light/high-contrast
// themes), so php-code-coverage falls back to static analysis for them — and
// its executable-line map differs from pcov's on match expressions, yielding
// phantom uncovered lines that break the 100% gate.
if ('1' === ini_get('pcov.enabled')) {
    putenv('PORTA_CONTAINER_CACHE=off');
}
