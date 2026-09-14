<?php

declare(strict_types=1);

// Required by tests/bootstrap.php AND rector.php: Rector has to resolve plugin
// classes for the same reason PHPUnit does, and one copy of this loop is what
// keeps the two from drifting apart.
//
// Plugins own their code, so their classes have to resolve here the way they do
// in a running IDE: composer maps `Porta\ => src/` only, and CI installs with
// --classmap-authoritative, which has never heard of `Porta\GraphViewer`. A
// plugin's own tests are loaded by path, but the code they exercise is not.
$pluginFiles = glob(dirname(__DIR__).'/plugins/*/plugin.php');
foreach (false === $pluginFiles ? [] : $pluginFiles as $pluginFile) {
    $manifest = Porta\Plugin\Runtime\PluginManifest::beside($pluginFile);
    Porta\Plugin\Runtime\PluginAutoloader::register($manifest);
    // And the plugin's own test SUPPORT, under `<prefix>Tests\`. Test CASES are
    // loaded by path, so no plugin needed this until one grew a shared double;
    // it is test-only, which is why it is wired here rather than in the
    // manifest a running IDE reads.
    foreach ($manifest->autoloadDirs() as $prefix => $directories) {
        foreach ($directories as $directory) {
            $testsDir = \dirname($directory).'/tests';
            if (is_dir($testsDir)) {
                Porta\Plugin\Runtime\PluginAutoloader::register(
                    new Porta\Plugin\Runtime\PluginManifest($manifest->name, \dirname($directory), [$prefix.'Tests\\' => ['tests']]),
                );
            }
        }
    }
}
