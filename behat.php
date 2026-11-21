<?php

declare(strict_types=1);

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Filter\TagFilter;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Janalis\Tuink\Context\TuiContext;
use Janalis\Tuink\ServiceContainer\TuinkExtension;
use Porta\Tests\Behat\Context\PortaContext;

require __DIR__.'/tests/Behat/bootstrap.php';

// The process driver runs the real entry point, so it gets the same
// environment as a variable list: the bootstrap above has set it here.
$processEnv = [];
foreach (['LANG', 'PORTA_HOME', 'PORTA_KEYMAP_PLATFORM', 'PORTA_EMBEDDED_PLUGINS', 'PORTA_GRAPHICS', 'PORTA_PIXEL_MOUSE', 'PORTA_TRUSTED_FOLDERS', 'PORTA_CACHE_DIR', 'CLAUDE_CONFIG_DIR'] as $name) {
    $processEnv[$name] = (string) getenv($name);
}

$tuink = [
    // The compact layout below 30 rows is a layout of its own; scenarios that
    // want it say so with "in a 80x24 terminal".
    'columns' => 120,
    'rows' => 32,
    'timeout' => (float) (getenv('PORTA_BEHAT_TIMEOUT') ?: 5.0),
    'snapshots' => '%paths.base%/tests/Behat/features/__snapshots__',
    'in_process' => ['factory' => 'tests/Behat/factory.php'],
    'process' => [
        'command' => [PHP_BINARY, __DIR__.'/bin/porta'],
        'cwd' => '%workspace%',
        'env' => $processEnv,
    ],
];

// The real entry point is slow to boot and cannot be read into: it runs
// only the scenarios tagged @process, and the in-process profile skips them.
$suite = static fn (string $tags): Suite => new Suite('default')
    ->withPaths('%paths.base%/tests/Behat/features')
    ->withContexts(TuiContext::class, PortaContext::class)
    ->withFilter(new TagFilter($tags));

return new Config()
    ->withProfile(new Profile('default')
        ->withSuite($suite('~@process'))
        ->withExtension(new Extension(TuinkExtension::class, $tuink)))
    ->withProfile(new Profile('process')
        ->withSuite($suite('@process'))
        ->withExtension(new Extension(TuinkExtension::class, ['default_driver' => 'process'] + $tuink)));
