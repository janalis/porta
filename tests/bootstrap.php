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
// in every worker, so every src file lands in some worker's pcov data — the
// other half of this fix is `includeUncoveredFiles="false"` in
// phpunit.xml.dist, which keeps php-code-coverage's static analyser (whose
// executable-line map disagrees with pcov's on match expressions) out of the
// paratest merge entirely.
if ('1' === ini_get('pcov.enabled')) {
    putenv('PORTA_CONTAINER_CACHE=off');
}
