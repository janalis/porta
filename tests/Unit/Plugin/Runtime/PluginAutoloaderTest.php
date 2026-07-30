<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginAutoloader;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Tests\Support\Probe;

/** Classes shipped by a plugin, from a directory composer knows nothing about. */
final class PluginAutoloaderTest extends TestCase
{
    /** @var array<string, string> */
    private array $prefixes = [];

    protected function setUp(): void
    {
        // The map is process-global and the test bootstrap has already filled it
        // with the bundled plugins: perturb it, then put it back, or the rest of
        // this paratest worker loses their classes.
        $this->prefixes = self::registered();
        Probe::setStatic(PluginAutoloader::class, 'prefixes', []);
    }

    protected function tearDown(): void
    {
        Probe::setStatic(PluginAutoloader::class, 'prefixes', $this->prefixes);
    }

    private static function fixture(): string
    {
        return \dirname(__DIR__, levels: 3).'/Support/fixtures/thirdparty/acme-tools/plugin.php';
    }

    /** @return array<string, string> */
    private static function registered(): array
    {
        /** @var array<string, string> */
        return Probe::getStatic(PluginAutoloader::class, 'prefixes');
    }

    public function testAPluginsOwnClassResolves(): void
    {
        PluginAutoloader::register(PluginManifest::beside(self::fixture()));

        $this->assertArrayHasKey('Acme\\Tools\\', self::registered());
        $this->assertTrue(class_exists('Acme\\Tools\\Greeting'), 'loaded from the plugin, not from composer');
        $this->assertSame('hello from acme-tools', \Acme\Tools\Greeting::text());
    }

    public function testAPluginWithoutANamespaceRegistersNothing(): void
    {
        PluginAutoloader::register(new PluginManifest('plain', \dirname(self::fixture())));

        $this->assertSame([], self::registered());
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
