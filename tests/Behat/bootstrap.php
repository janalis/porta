<?php

declare(strict_types=1);

/*
 * The Behat suite's environment: phpunit.xml.dist's <php> block, then the
 * PHPUnit bootstrap itself, so a scenario boots the Ide on the same fixture
 * home, trusted temp dir, container cache and rethrowing loop as a test does.
 */

$portaBehatEnv = [
    'LANG' => 'en_US.UTF-8',
    'LC_ALL' => '',
    'LC_MESSAGES' => '',
    'PORTA_HOME' => dirname(__DIR__).'/Support/fixtures/home',
    'PORTA_KEYMAP_PLATFORM' => 'linux',
    'PORTA_EMBEDDED_PLUGINS' => '/nonexistent',
    // No terminal answers the probes in a scenario: say so, rather than wait out their timeouts.
    'PORTA_GRAPHICS' => 'off',
    'PORTA_PIXEL_MOUSE' => 'off',
];
foreach ($portaBehatEnv as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
date_default_timezone_set('UTC');

require dirname(__DIR__).'/bootstrap.php';
