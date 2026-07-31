<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginEvents;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\TempProject;

final class PluginLoaderTest extends TestCase
{
    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $home = getenv('PORTA_HOME');
        $this->portaHome = $home ? $home : null;
    }

    protected function tearDown(): void
    {
        putenv(null !== $this->portaHome ? 'PORTA_HOME='.$this->portaHome : 'PORTA_HOME');
    }

    /** A plugin.php source registering one command named after the plugin. */
    private function pluginSource(string $name, string $extra = ''): string
    {
        return <<<PHP
            <?php
            use Porta\\Plugin\\Api\\PluginApi;
            use Porta\\Plugin\\Api\\PortaPlugin;

            return new class('{$name}') implements PortaPlugin {
                public function __construct(private readonly string \$name) {}
                public function name(): string { return \$this->name; }
                public function activate(PluginApi \$api): void
                {
                    \$api->registerCommand('hello', 'Hello from {$name}', static function (): void {}, ['f8']);
                    {$extra}
                }
            };
            PHP;
    }

    private function load(TempProject $project, ?string $globalBase = null): PluginManager
    {
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, $globalBase, new PluginSettings($project->dir, []))->loadInto($manager);

        return $manager;
    }

    public function testLoadsAValidPluginAndMissingDirsAreSilent(): void
    {
        $empty = $this->load(new TempProject());
        $this->assertSame([], $empty->warnings());
        $this->assertSame([], $empty->defaultKeys());

        $project = new TempProject(['.porta/plugins/demo/plugin.php' => $this->pluginSource('demo')]);
        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('demo'));
        $this->assertSame(['demo:hello' => ['f8']], $manager->defaultKeys());
        $this->assertSame([], $manager->warnings());
    }

    public function testProjectBeatsGlobalOnDirectoryNameAndGlobalOnlyLoads(): void
    {
        $global = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->pluginSource('globaldemo'),
            '.porta/plugins/extra/plugin.php' => $this->pluginSource('extra'),
        ]);
        $project = new TempProject(['.porta/plugins/demo/plugin.php' => $this->pluginSource('demo')]);

        $manager = $this->load($project, $global->dir);
        $this->assertTrue($manager->hasPlugin('demo'), 'the project copy won');
        $this->assertFalse($manager->hasPlugin('globaldemo'), 'the shadowed global copy never ran');
        $this->assertTrue($manager->hasPlugin('extra'), 'non-colliding global plugins load');
    }

    public function testFailuresAreContainedPerPluginAndSiblingsStillLoad(): void
    {
        $project = new TempProject([
            '.porta/plugins/a-parse-error/plugin.php' => '<?php this is not php at all {{{',
            '.porta/plugins/b-wrong-return/plugin.php' => '<?php return 42;',
            '.porta/plugins/c-bad-name/plugin.php' => $this->badNameSource(),
            '.porta/plugins/d-activate-throws/plugin.php' => $this->pluginSource('thrower', 'throw new \RuntimeException("mid-activate");'),
            '.porta/plugins/e-action-during-activate/plugin.php' => $this->pluginSource('eager', '$api->flash("too soon");'),
            '.porta/plugins/f-good/plugin.php' => $this->pluginSource('good'),
            '.porta/plugins/g-duplicate/plugin.php' => $this->pluginSource('good'),
        ]);
        $manager = $this->load($project);

        $warnings = implode("\n", $manager->warnings());
        $this->assertStringContainsString('Plugin "a-parse-error" failed to load', $warnings);
        $this->assertStringContainsString('Plugin "b-wrong-return" failed to load: plugin.php must return a PortaPlugin instance', $warnings);
        $this->assertStringContainsString('invalid plugin name "bad:name"', $warnings);
        $this->assertStringContainsString('Plugin "d-activate-throws" failed to load: mid-activate', $warnings);
        $this->assertStringContainsString('unavailable during activate()', $warnings);
        $this->assertStringContainsString('duplicate plugin name "good"', $warnings);
        $this->assertCount(6, $manager->warnings());

        $this->assertTrue($manager->hasPlugin('good'), 'the healthy sibling loaded');
        $this->assertFalse($manager->hasPlugin('thrower'));
        $this->assertSame(['good:hello' => ['f8']], $manager->defaultKeys(), 'atomicity: failed plugins contributed nothing');
    }

    public function testLoadFileReturnsTheErrorInsteadOfWarning(): void
    {
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->pluginSource('demo'),
            '.porta/plugins/broken/plugin.php' => '<?php return 42;',
        ]);
        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []));

        $this->assertNull($loader->loadFile($project->path('.porta/plugins/demo/plugin.php'), $manager));
        $this->assertTrue($manager->hasPlugin('demo'), 'a direct load commits like a boot load');

        $error = $loader->loadFile($project->path('.porta/plugins/broken/plugin.php'), $manager);
        $this->assertSame('Plugin "broken" failed to load: plugin.php must return a PortaPlugin instance', $error);
        $this->assertSame([], $manager->warnings(), 'the caller owns the failure, no warning is queued');
    }

    public function testGlobalBaseResolvesPortaHomeThenHome(): void
    {
        putenv('PORTA_HOME=/tmp/porta-test-home');
        $this->assertSame('/tmp/porta-test-home', PluginLoader::globalBase());

        putenv('PORTA_HOME');
        $home = getenv('HOME');
        $this->assertSame(\is_string($home) && '' !== $home ? $home : null, PluginLoader::globalBase(), 'falls back to HOME');

        putenv('PORTA_HOME='); // empty string → treated as unset, falls through to HOME
        $this->assertSame(\is_string($home) && '' !== $home ? $home : null, PluginLoader::globalBase());
    }

    public function testEmbeddedDirResolvesTheEnvOverrideThenThePackage(): void
    {
        // phpunit.xml.dist pins the env for every test; assert both arms.
        $this->assertSame('/nonexistent', PluginLoader::embeddedDir());

        $pinned = (string) getenv('PORTA_EMBEDDED_PLUGINS');
        putenv('PORTA_EMBEDDED_PLUGINS=');
        try {
            $this->assertStringEndsWith('/plugins', PluginLoader::embeddedDir(), 'empty env → the package dir');
            $this->assertFileExists(PluginLoader::embeddedDir().'/psalm/plugin.php', 'and it holds the bundled plugins');
        } finally {
            putenv('PORTA_EMBEDDED_PLUGINS='.$pinned);
        }
    }

    public function testEmbeddedPluginsAreShadowedByNameAndLoadOnlyWhenOptedIn(): void
    {
        $embedded = new TempProject([
            'demo/plugin.php' => $this->pluginSource('embeddeddemo'),
            'extra/plugin.php' => $this->pluginSource('embeddedextra'),
            'own/plugin.php' => $this->pluginSource('own'),
        ]);
        $global = new TempProject(['.porta/plugins/extra/plugin.php' => $this->pluginSource('globalextra')]);
        $project = new TempProject(['.porta/plugins/demo/plugin.php' => $this->pluginSource('demo')]);

        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        // Bundled plugins are off by default; only 'own' is opted into enabledPlugins.
        new PluginLoader($project->dir, $global->dir, new PluginSettings($project->dir, []), $embedded->dir, [], ['own'])->loadInto($manager);

        $this->assertTrue($manager->hasPlugin('demo'), 'the project copy loads (and shadowed the embedded one)');
        $this->assertFalse($manager->hasPlugin('embeddeddemo'));
        $this->assertTrue($manager->hasPlugin('globalextra'), 'the global copy loads (and shadowed the embedded one)');
        $this->assertFalse($manager->hasPlugin('embeddedextra'));
        $this->assertTrue($manager->hasPlugin('own'), 'the opted-in embedded plugin loads');
    }

    public function testEmbeddedPluginsAreOffByDefaultUnlessEnabled(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => $this->pluginSource('bundled')]);
        $project = new TempProject(['.porta/plugins/local/plugin.php' => $this->pluginSource('local')]);

        // No enabledPlugins → the bundled plugin stays off; the project plugin loads by default.
        $off = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), $embedded->dir)->loadInto($off);
        $this->assertFalse($off->hasPlugin('bundled'), 'a bundled plugin is off by default');
        $this->assertTrue($off->hasPlugin('local'), 'a project plugin loads by default');

        // Opting the bundled plugin in loads it.
        $on = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), $embedded->dir, [], ['bundled'])->loadInto($on);
        $this->assertTrue($on->hasPlugin('bundled'), 'enabledPlugins opts it in');
    }

    private function badNameSource(): string
    {
        return <<<'PHP'
            <?php
            use Porta\Plugin\Api\PluginApi;
            use Porta\Plugin\Api\PortaPlugin;

            return new class implements PortaPlugin {
                public function name(): string { return 'bad:name'; }
                public function activate(PluginApi $api): void {}
            };
            PHP;
    }

    public function testAPluginThatHasNotInstalledItsDependenciesIsDeclinedByName(): void
    {
        // A composer.json with no vendor/ beside it: its classes would load and
        // then fatal on the first vendored symbol, far from the cause. Its
        // dependents go with it, because it never enters the order.
        $project = new TempProject([
            '.porta/plugins/needy/composer.json' => '{"require":{"vend/lib":"^1.0"}}',
            '.porta/plugins/needy/plugin.php' => $this->pluginSource('needy'),
            '.porta/plugins/dependent/plugin.json' => '{"requires":["needy"]}',
            '.porta/plugins/dependent/plugin.php' => $this->pluginSource('dependent'),
            '.porta/plugins/unrelated/plugin.php' => $this->pluginSource('unrelated'),
        ]);

        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('needy'));
        $this->assertFalse($manager->hasPlugin('dependent'), 'the skip propagates');
        $this->assertTrue($manager->hasPlugin('unrelated'), 'and stops there');
        $warnings = implode(' | ', $manager->warnings());
        $this->assertStringContainsString('Plugin "needy" needs its dependencies installed', $warnings);
        $this->assertStringContainsString('composer install', $warnings, 'and says what to do about it');
    }

    public function testAPluginOwedOnlyItsAutoloadDumpIsToldThatInstead(): void
    {
        // Same refusal, different reason: nothing is missing from vendor/, the
        // maps that would carry a classmap were simply never dumped. Saying
        // "needs its dependencies installed" to a plugin with no dependencies
        // sends the reader looking for something that is not there.
        $project = new TempProject([
            '.porta/plugins/undumped/composer.json' => '{"autoload":{"classmap":["lib/"]}}',
            '.porta/plugins/undumped/plugin.php' => $this->pluginSource('undumped'),
        ]);

        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('undumped'));
        $this->assertStringContainsString('Plugin "undumped" declares autoload maps it has not dumped', implode(' | ', $manager->warnings()));
    }

    public function testAPlatformOnlyRequirementIsNotAnUninstalledDependency(): void
    {
        // The trap this predicate exists to avoid: every plugin is about to
        // have a composer.json, and most will declare only `php`.
        $project = new TempProject([
            '.porta/plugins/modern/composer.json' => '{"require":{"php":">=8.4"}}',
            '.porta/plugins/modern/plugin.php' => $this->pluginSource('modern'),
        ]);

        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('modern'), 'it loads');
        $this->assertSame([], $manager->warnings(), 'and is not warned about');
    }

    public function testVendoringAPackagePortaShipsIsNamedAtBoot(): void
    {
        // Porta's loader is ahead of the plugin map, so the plugin's copy is
        // never reached. Silently getting a different major than you pinned
        // surfaces as a TypeError in some unrelated pass; this is the pointer.
        $project = new TempProject([
            '.porta/plugins/shadowy/composer.json' => '{"require":{"symfony/clock":"^1.0"}}',
            '.porta/plugins/shadowy/vendor/composer/installed.json' => '{"packages":[{"name":"symfony/clock"},{"name":"vend/lib"}]}',
            '.porta/plugins/shadowy/plugin.php' => $this->pluginSource('shadowy'),
        ]);

        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('shadowy'), 'it still loads — this is a warning, not a refusal');
        $warnings = implode(' | ', $manager->warnings());
        $this->assertStringContainsString('Plugin "shadowy" vendors symfony/clock, which Porta ships', $warnings);
        $this->assertStringNotContainsString('vend/lib', $warnings, 'a package Porta does not ship is not its business');
    }
}
