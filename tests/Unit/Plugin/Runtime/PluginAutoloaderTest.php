<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginAutoloader;
use Porta\Plugin\Runtime\PluginManifest;

/** Classes shipped by a plugin, from a directory composer knows nothing about. */
final class PluginAutoloaderTest extends TestCase
{
    protected function tearDown(): void
    {
        PluginAutoloader::reset();
    }

    private static function fixture(): string
    {
        return \dirname(__DIR__, levels: 3).'/Support/fixtures/thirdparty/acme-tools/plugin.php';
    }

    public function testAPluginsOwnClassResolves(): void
    {
        PluginAutoloader::register(PluginManifest::beside(self::fixture()));

        $this->assertArrayHasKey('Acme\\Tools\\', PluginAutoloader::prefixes());
        $this->assertTrue(class_exists('Acme\\Tools\\Greeting'), 'loaded from the plugin, not from composer');
        $this->assertSame('hello from acme-tools', \Acme\Tools\Greeting::text());
    }

    public function testAPluginWithoutANamespaceRegistersNothing(): void
    {
        PluginAutoloader::register(new PluginManifest('plain', \dirname(self::fixture())));

        $this->assertSame([], PluginAutoloader::prefixes());
    }

    public function testAnUnknownClassIsLeftToPhpToComplainAbout(): void
    {
        PluginAutoloader::register(PluginManifest::beside(self::fixture()));

        // The autoloader must not fatal, warn, or claim a class it has no file
        // for — PHP's own "class not found" names the class and the namespace,
        // which is the useful message.
        $this->assertFalse(class_exists('Acme\\Tools\\Missing'));
        $this->assertFalse(class_exists('Someone\\Else\\Thing'));
    }
}
