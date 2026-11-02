<?php

declare(strict_types=1);

use Porta\Plugin\Runtime\PluginAutoloader;
use Porta\Plugin\Runtime\PluginManifest;

// Required by tests/bootstrap.php AND rector.php: Rector has to resolve plugin
// classes for the same reason PHPUnit does, and one copy of this loop is what
// keeps the two from drifting apart.
//
// Plugins own their code, so their classes have to resolve here the way they do
// in a running IDE: composer maps `Porta\ => src/` only, and CI installs with
// --classmap-authoritative, which has never heard of `Porta\GraphViewer`. A
// plugin's own tests are loaded by path, but the code they exercise is not.
$pluginFiles = glob(dirname(__DIR__).'/plugins/*/porta.php');
foreach (false === $pluginFiles ? [] : $pluginFiles as $pluginFile) {
    $manifest = PluginManifest::beside($pluginFile);
    PluginAutoloader::register($manifest);
    // And the plugin's own test SUPPORT, under `<prefix>Tests\`. Test CASES are
    // loaded by path, so no plugin needed this until one grew a shared double;
    // it is test-only, which is why it is wired here rather than in the
    // manifest a running IDE reads.
    foreach ($manifest->autoloadDirs() as $prefix => $directories) {
        foreach ($directories as $directory) {
            $testsDir = \dirname($directory).'/tests';
            if (is_dir($testsDir)) {
                PluginAutoloader::register(
                    new PluginManifest($manifest->name, \dirname($directory), [$prefix.'Tests\\' => ['tests']]),
                );
            }
        }
    }
}
