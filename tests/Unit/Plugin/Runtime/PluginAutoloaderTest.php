<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginAutoloader;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

/** Classes shipped by a plugin, from a directory composer knows nothing about. */
final class PluginAutoloaderTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $prefixes = [];

    /** @var array<string, string> */
    private array $classes = [];

    protected function setUp(): void
    {
        // The map is process-global and the test bootstrap has already filled it
        // with the bundled plugins: perturb it, then put it back, or the rest of
        // this paratest worker loses their classes. Emptying it also makes the
        // next register() take the first-plugin branch again, which leaves one
        // extra (identical, inert) autoloader on the stack per test — the price
        // of covering that branch, since the bootstrap always takes it first.
        $this->prefixes = self::registered();
        /** @var array<string, string> $classes */
        $classes = Probe::getStatic(PluginAutoloader::class, 'classes');
        $this->classes = $classes;
        Probe::setStatic(PluginAutoloader::class, 'prefixes', []);
        Probe::setStatic(PluginAutoloader::class, 'classes', []);
        // …and the flag, so each test re-takes the first-registration branch.
        // The price is one extra (identical, inert) loader on the stack per
        // test, which is the same price the empty map used to cost.
        Probe::setStatic(PluginAutoloader::class, 'registered', false);
    }

    protected function tearDown(): void
    {
        Probe::setStatic(PluginAutoloader::class, 'prefixes', $this->prefixes);
        Probe::setStatic(PluginAutoloader::class, 'classes', $this->classes);
        Probe::setStatic(PluginAutoloader::class, 'registered', true);
    }

    private static function fixture(): string
    {
        return \dirname(__DIR__, levels: 3).'/Support/fixtures/thirdparty/acme-tools/plugin.php';
    }

    /** @return array<string, list<string>> */
    private static function registered(): array
    {
        /** @var array<string, list<string>> */
        return Probe::getStatic(PluginAutoloader::class, 'prefixes');
    }

    private static function vendored(): string
    {
        return \dirname(__DIR__, levels: 3).'/Support/fixtures/thirdparty/acme-vendored/plugin.php';
    }

    public function testAPluginsOwnClassResolves(): void
    {
        PluginAutoloader::register(PluginManifest::beside(self::fixture()));

        $this->assertArrayHasKey('Acme\\Tools\\', self::registered());
        $this->assertTrue(\class_exists('Acme\\Tools\\Greeting'), 'loaded from the plugin, not from composer');
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
        $this->assertFalse(\class_exists('Acme\\Tools\\Missing'));
        $this->assertFalse(\class_exists('Someone\\Else\\Thing'));
    }

    public function testAVendoredPackageResolvesThroughPortasOwnLoader(): void
    {
        // The point of the whole arrangement: composer's generated maps are
        // READ, its autoloader is never RUN — so the package resolves without
        // a second registered loader anywhere near the front of the stack.
        PluginAutoloader::register(PluginManifest::beside(self::vendored()));

        $this->assertArrayHasKey('Vend\\Lib\\', self::registered(), 'the PSR-4 map was adopted');
        $this->assertSame('psr4-ok', \Vend\Lib\Helper::text());
        $this->assertSame('classmap-ok', \Vend\Mapped\Thing::text(), 'and the classmap half too');
        $this->assertSame('acme psr4-ok classmap-ok', \Acme\Vendored\Greeter::greet(), "the plugin's own class reaches both");
    }

    public function testPortasCopyOfAPackageWinsOverAPluginsVendoredOne(): void
    {
        // The fixture's map points symfony/clock at a directory that does not
        // exist. If our loader could ever be consulted first for it, this class
        // would fail to load; composer's loader is prepended at boot, so it
        // answers before we are asked.
        PluginAutoloader::register(PluginManifest::beside(self::vendored()));

        $this->assertTrue(\class_exists(\Symfony\Component\Clock\NativeClock::class));
        $reflection = new \ReflectionClass(\Symfony\Component\Clock\NativeClock::class);
        $this->assertStringContainsString('/vendor/symfony/clock/', (string) $reflection->getFileName(), "Porta's copy, not the plugin's");
    }

    public function testTheSamePrefixFromTwoPluginsKeepsBothDirectories(): void
    {
        PluginAutoloader::register(PluginManifest::beside(self::vendored()));
        PluginAutoloader::register(new PluginManifest('other', \dirname(self::vendored()), ['Vend\\Lib\\' => ['src']]));

        $this->assertCount(2, self::registered()['Vend\\Lib\\'] ?? [], 'both directories are searched');

        // And registering the identical directory again does not grow it.
        PluginAutoloader::register(new PluginManifest('other', \dirname(self::vendored()), ['Vend\\Lib\\' => ['src']]));
        $this->assertCount(2, self::registered()['Vend\\Lib\\'] ?? []);
    }

    public function testAVendoredPluginsOwnPrefixArrivesTwiceAndIsDedupedOnce(): void
    {
        // Composer dumps the root package's psr-4 into autoload_psr4.php too,
        // so a plugin that vendors registers its own prefix from BOTH halves.
        // Asserted against the committed fixture, never a TempProject: a temp
        // dir is reached through a symlink on macOS (/var → /private/var), so
        // the two strings would differ there and the dedup could not fire.
        PluginAutoloader::register(PluginManifest::beside(self::vendored()));

        $this->assertCount(1, self::registered()['Acme\\Vendored\\'] ?? [], 'the manifest and the dump agree on one directory');
    }

    public function testAClassAlreadyClaimedByAnEarlierPluginsClassmapKeepsItsFirstFile(): void
    {
        $project = new TempProject();
        $project->write('a/vendor/composer/autoload_classmap.php', "<?php\nreturn ['Acme\\\\Shared\\\\Thing' => __DIR__.'/../../real.php'];\n");
        $project->write('a/real.php', "<?php\nnamespace Acme\\Shared;\nfinal class Thing { public static function text(): string { return 'first-plugin-wins'; } }\n");
        $project->write('b/vendor/composer/autoload_classmap.php', "<?php\nreturn ['Acme\\\\Shared\\\\Thing' => __DIR__.'/../../does-not-exist.php'];\n");

        PluginAutoloader::register(new PluginManifest('a', $project->path('a')));
        PluginAutoloader::register(new PluginManifest('b', $project->path('b')));

        $this->assertSame('first-plugin-wins', \Acme\Shared\Thing::text(), 'the later plugin cannot steal a class name the first already claimed');
    }

    public function testAMapShapeComposerNeverEmitsIsIgnoredEntryByEntry(): void
    {
        // Not defensiveness for its own sake: these files are generated by a
        // tool version we do not control, and one surprising entry must not
        // cost the plugin every OTHER entry beside it.
        $fixture = \dirname(self::vendored(), levels: 2).'/acme-malformed/plugin.php';
        PluginAutoloader::register(PluginManifest::beside($fixture));

        $registered = self::registered();
        $this->assertArrayNotHasKey('Bad\\Dirs\\', $registered, 'a non-string directory is not a directory');
        $this->assertSame(['one-directory-not-a-list'], $registered['Bare\\String\\'] ?? [], 'a bare string is read as a one-entry list');
        $this->assertCount(1, $registered, 'and the numeric key claimed no prefix');

        /** @var array<string, string> $classes */
        $classes = Probe::getStatic(PluginAutoloader::class, 'classes');
        $this->assertSame([], $classes, 'neither malformed classmap row was adopted');
    }
}
