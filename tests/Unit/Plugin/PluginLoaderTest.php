<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\PluginEvents;
use Porta\Plugin\PluginLoader;
use Porta\Plugin\PluginManager;
use Porta\Plugin\PluginSettings;
use Porta\Tests\Support\TempProject;

final class PluginLoaderTest extends TestCase
{
    private ?string $portaHome = null;

    protected function setUp(): void
    {
        $this->portaHome = getenv('PORTA_HOME') ?: null;
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
            use Porta\\Plugin\\PluginApi;
            use Porta\\Plugin\\PortaPlugin;

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
            $this->assertStringEndsWith('/.porta/plugins', PluginLoader::embeddedDir(), 'empty env → the package dir');
            $this->assertFileExists(PluginLoader::embeddedDir().'/psalm/plugin.php', 'and it holds the bundled plugins');
        } finally {
            putenv('PORTA_EMBEDDED_PLUGINS='.$pinned);
        }
    }

    public function testEmbeddedPluginsLoadLastAndAreShadowedByNameFromBothScopes(): void
    {
        $embedded = new TempProject([
            'demo/plugin.php' => $this->pluginSource('embeddeddemo'),
            'extra/plugin.php' => $this->pluginSource('embeddedextra'),
            'own/plugin.php' => $this->pluginSource('own'),
        ]);
        $global = new TempProject(['.porta/plugins/extra/plugin.php' => $this->pluginSource('globalextra')]);
        $project = new TempProject(['.porta/plugins/demo/plugin.php' => $this->pluginSource('demo')]);

        $manager = new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, $global->dir, new PluginSettings($project->dir, []), $embedded->dir)->loadInto($manager);

        $this->assertTrue($manager->hasPlugin('demo'), 'the project copy shadowed the embedded one');
        $this->assertFalse($manager->hasPlugin('embeddeddemo'));
        $this->assertTrue($manager->hasPlugin('globalextra'), 'the global copy shadowed the embedded one');
        $this->assertFalse($manager->hasPlugin('embeddedextra'));
        $this->assertTrue($manager->hasPlugin('own'), 'a non-shadowed embedded plugin loads');
    }

    private function badNameSource(): string
    {
        return <<<'PHP'
            <?php
            use Porta\Plugin\PluginApi;
            use Porta\Plugin\PortaPlugin;

            return new class implements PortaPlugin {
                public function name(): string { return 'bad:name'; }
                public function activate(PluginApi $api): void {}
            };
            PHP;
    }
}
