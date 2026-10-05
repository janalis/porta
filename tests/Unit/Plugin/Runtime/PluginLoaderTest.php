<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigWarning;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PluginLoaderTest extends TestCase
{
    use PreservesPortaHome;

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
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, $globalBase, new PluginSettings($project->dir, []))->loadInto($manager);

        return $manager;
    }

    /** A warning rendered the way an 'en' Translator would (untranslated: identity substitution), for readable substring assertions. */
    private static function render(ConfigWarning $warning): string
    {
        return \strtr($warning->key, $warning->params);
    }

    /** @param list<ConfigWarning> $warnings */
    private static function renderAll(array $warnings, string $glue = "\n"): string
    {
        return \implode($glue, \array_map(self::render(...), $warnings));
    }

    public function testLoadsAValidPluginAndMissingDirsAreSilent(): void
    {
        $empty = $this->load(new TempProject());
        $this->assertSame([], $empty->warnings);
        $this->assertSame([], $empty->defaultKeys);

        $project = new TempProject(['.porta/plugins/demo/plugin.php' => $this->pluginSource('demo')]);
        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('demo'));
        $this->assertSame(['demo:hello' => ['f8']], $manager->defaultKeys);
        $this->assertSame([], $manager->warnings);
    }

    /**
     * A plugin is named by its directory, so a directory named in digits is a
     * plugin named in digits — an int wherever the name keys an array, which
     * was a TypeError at boot (PluginOrder handed the key to a string param).
     */
    public function testAPluginInADirectoryNamedInDigitsLoads(): void
    {
        $manager = $this->load(new TempProject(['.porta/plugins/2048/plugin.php' => $this->pluginSource('2048')]));

        $this->assertTrue($manager->hasPlugin('2048'));
        $this->assertSame([], $manager->warnings);
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

    /**
     * A plugin that loads fine does not swallow the warning for one after it.
     *
     * The loop walks every manifest and acts only on the skipped ones, so it
     * skips the plugins that loaded. As a `break` that reads as "stop at the
     * first healthy plugin", and every existing case here stayed green because
     * each has ALL its plugins skipped — there is no healthy one in front to
     * stop at.
     *
     * Which is the ordinary shape of a real install: several plugins that work,
     * one that does not. The one that does not is the only one the user needs
     * told about, and it would have been the silent one.
     */
    public function testAWarningSurvivesAHealthyPluginOrderedBeforeIt(): void
    {
        $project = new TempProject([
            // Discovery is alphabetical, so `alpha` is reached first and loads.
            '.porta/plugins/alpha/plugin.php' => $this->pluginSource('alpha'),
            // Skipped for an unmet PLUGIN requirement, which is what this loop
            // reports — a platform requirement is warned about elsewhere.
            '.porta/plugins/zulu/plugin.php' => $this->pluginSource('zulu'),
            '.porta/plugins/zulu/composer.json' => '{"extra":{"porta":{"requires":["nowhere"]}}}',
        ]);
        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('alpha'), 'the healthy one loaded');
        $this->assertFalse($manager->hasPlugin('zulu'), 'and the other could not');
        $this->assertStringContainsString('"zulu"', self::renderAll($manager->warnings), 'the user is told which one, and why');
    }

    public function testAnUnmetPlatformRequirementDeclinesByNameAndTakesDependentsAlong(): void
    {
        $project = new TempProject([
            '.porta/plugins/future/plugin.php' => $this->pluginSource('future'),
            '.porta/plugins/future/composer.json' => '{"require": {"php": ">=99.0", "ext-porta_nonexistent": "*"}}',
            '.porta/plugins/rider/plugin.php' => $this->pluginSource('rider'),
            '.porta/plugins/rider/composer.json' => '{"extra": {"porta": {"requires": ["future"]}}}',
        ]);
        $manager = $this->load($project);

        $warnings = self::renderAll($manager->warnings);
        $this->assertFalse($manager->hasPlugin('future'), 'a plugin this host cannot run never loads');
        $this->assertStringContainsString('Plugin "future" needs PHP >=99.0 (this is '.\PHP_VERSION.'), ext-porta_nonexistent — not loaded', $warnings);
        $this->assertFalse($manager->hasPlugin('rider'), 'its dependents skip like any absent requirement');
        $this->assertStringContainsString('"rider"', $warnings);
    }

    public function testAMismatchedPluginApiMajorDeclinesAndAMatchingOneLoads(): void
    {
        $project = new TempProject([
            '.porta/plugins/oldapi/plugin.php' => $this->pluginSource('oldapi'),
            '.porta/plugins/oldapi/composer.json' => '{"extra": {"porta": {"api": 999}}}',
            '.porta/plugins/current/plugin.php' => $this->pluginSource('current'),
            '.porta/plugins/current/composer.json' => '{"require": {"php": ">=8.4"}, "extra": {"porta": {"api": 1}}}',
        ]);
        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('oldapi'));
        $this->assertStringContainsString('Plugin "oldapi" needs plugin API 999 (this Porta speaks 1) — not loaded', self::renderAll($manager->warnings));
        $this->assertTrue($manager->hasPlugin('current'), 'a met platform and a matching API major load normally');
    }

    public function testTheLiveLoadPathRegistersThePluginsOwnClasses(): void
    {
        // A just-installed plugin's classes have to resolve from the first
        // line of its plugin.php, exactly like the boot pre-pass — this
        // activate() only works through the manifest's autoload registration.
        $project = new TempProject([
            'live/plugin.php' => <<<'PHP'
                <?php
                use Porta\Plugin\Api\AbstractPlugin;
                use Porta\Plugin\Api\PluginApi;

                return new class('live') extends AbstractPlugin {
                    public function activate(PluginApi $api): void
                    {
                        \Porta\LiveDemo\Greeting::hello();
                    }
                };
                PHP,
            'live/composer.json' => '{"autoload": {"psr-4": {"Porta\\\\LiveDemo\\\\": "src/"}}}',
            'live/src/Greeting.php' => "<?php\n\nnamespace Porta\\LiveDemo;\n\nfinal class Greeting\n{\n    public static function hello(): string\n    {\n        return 'hi';\n    }\n}\n",
        ]);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []));

        $this->assertNull($loader->loadFile($project->path('live/plugin.php'), $manager));
        $this->assertTrue($manager->hasPlugin('live'));
    }

    public function testAPluginsOwnTranslationsServeOnceItCommits(): void
    {
        $catalog =
            '<?xml version="1.0" encoding="utf-8"?><xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">'
            .'<file source-language="en" target-language="fr" datatype="plaintext" original="messages"><body>'
            .'<trans-unit id="1"><source>Hello from %name%</source><target>Bonjour de %name%</target></trans-unit>'
            .'</body></file></xliff>';
        $project = new TempProject([
            'core/.keep' => '',
            'tr/plugin.php' => $this->pluginSource('tr'),
            'tr/translations/messages.fr.xlf' => $catalog,
            'broken/plugin.php' => $this->pluginSource('broken', "throw new \\RuntimeException('boom');"),
            'broken/translations/messages.fr.xlf' => \str_replace(search: 'Hello from %name%', replace: 'Broken %name%', subject: $catalog),
        ]);
        $translator = new Translator('fr', $project->path('core'));
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), translator: $translator);

        $this->assertNull($loader->loadFile($project->path('tr/plugin.php'), $manager));
        $this->assertInstanceOf(ConfigWarning::class, $loader->loadFile($project->path('broken/plugin.php'), $manager));

        $this->assertSame('Bonjour de tr', $translator->trans('Hello from %name%', ['%name%' => 'tr']));
        $this->assertSame('Broken x', $translator->trans('Broken %name%', ['%name%' => 'x']), 'a plugin that failed to load contributes no strings either');
    }

    public function testTheLiveLoadPathAppliesTheSameCompatibilityCheck(): void
    {
        // loadFile is the just-installed/re-enabled door — a plugin declined
        // at boot must not slip in through it.
        $project = new TempProject([
            'future/plugin.php' => $this->pluginSource('future'),
            'future/composer.json' => '{"require": {"php": ">=99.0"}}',
        ]);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []));

        $error = $loader->loadFile($project->path('future/plugin.php'), $manager);

        $this->assertInstanceOf(ConfigWarning::class, $error);
        $this->assertStringContainsString('needs PHP >=99.0', self::render($error));
        $this->assertFalse($manager->hasPlugin('future'));
    }

    public function testATypoedComposerJsonWarnsWithTheDecoderReason(): void
    {
        $project = new TempProject([
            '.porta/plugins/typo/plugin.php' => $this->pluginSource('typo'),
            '.porta/plugins/typo/composer.json' => '{"autoload": ',
        ]);
        $manager = $this->load($project);

        $warnings = self::renderAll($manager->warnings);
        $this->assertStringContainsString('Plugin "typo" has an unreadable composer.json', $warnings);
        $this->assertStringContainsString('Syntax error', $warnings, 'the decoder names the reason');
        $this->assertTrue($manager->hasPlugin('typo'), 'the plugin still loads on the defaults');
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

        $warnings = self::renderAll($manager->warnings);
        $this->assertStringContainsString('Plugin "a-parse-error" failed to load', $warnings);
        $this->assertStringContainsString('Plugin "b-wrong-return" failed to load: plugin.php must return a PortaPlugin instance', $warnings);
        $this->assertStringContainsString('invalid plugin name "bad:name"', $warnings);
        $this->assertStringContainsString('Plugin "d-activate-throws" failed to load: mid-activate', $warnings);
        $this->assertStringContainsString('unavailable during activate()', $warnings);
        $this->assertStringContainsString('duplicate plugin name "good"', $warnings);
        $this->assertCount(6, $manager->warnings);

        $this->assertTrue($manager->hasPlugin('good'), 'the healthy sibling loaded');
        $this->assertFalse($manager->hasPlugin('thrower'));
        $this->assertSame(['good:hello' => ['f8']], $manager->defaultKeys, 'atomicity: failed plugins contributed nothing');
    }

    public function testACorruptedVendorAutoloadDumpDoesNotCrashBootAndSiblingsStillLoad(): void
    {
        // PluginAutoloader::register() `require`s composer's generated map
        // files; an install interrupted mid-write leaves one truncated, which
        // throws a \ParseError. That must degrade to this plugin contributing
        // nothing, exactly like any other loadInto() failure — not escape the
        // fault barrier and take the whole boot down.
        $project = new TempProject([
            '.porta/plugins/corrupt/plugin.php' => $this->pluginSource('corrupt'),
            '.porta/plugins/corrupt/composer.json' => '{"autoload": {"psr-4": {"Corrupt\\\\": "src/"}}}',
            '.porta/plugins/corrupt/vendor/composer/autoload_psr4.php' => '<?php this is not valid php syntax {{{',
            '.porta/plugins/good/plugin.php' => $this->pluginSource('good'),
        ]);

        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('corrupt'), 'a corrupted autoload dump degrades to a warning, not a crash');
        $this->assertStringContainsString('Plugin "corrupt" failed to load', self::renderAll($manager->warnings));
        $this->assertTrue($manager->hasPlugin('good'), 'the sibling still loads');
    }

    public function testTheLiveLoadPathAlsoContainsACorruptedVendorAutoloadDump(): void
    {
        $project = new TempProject([
            'corrupt/plugin.php' => $this->pluginSource('corrupt'),
            'corrupt/composer.json' => '{"autoload": {"psr-4": {"Corrupt\\\\": "src/"}}}',
            'corrupt/vendor/composer/autoload_psr4.php' => '<?php this is not valid php syntax {{{',
        ]);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []));

        $error = $loader->loadFile($project->path('corrupt/plugin.php'), $manager);

        $this->assertInstanceOf(ConfigWarning::class, $error);
        $this->assertStringContainsString('Plugin "corrupt" failed to load', self::render($error));
        $this->assertFalse($manager->hasPlugin('corrupt'));
    }

    public function testLoadFileReturnsTheErrorInsteadOfWarning(): void
    {
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->pluginSource('demo'),
            '.porta/plugins/broken/plugin.php' => '<?php return 42;',
        ]);
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $loader = new PluginLoader($project->dir, null, new PluginSettings($project->dir, []));

        $this->assertNull($loader->loadFile($project->path('.porta/plugins/demo/plugin.php'), $manager));
        $this->assertTrue($manager->hasPlugin('demo'), 'a direct load commits like a boot load');

        $error = $loader->loadFile($project->path('.porta/plugins/broken/plugin.php'), $manager);
        $this->assertEquals(new ConfigWarning('Plugin "%name%" failed to load: %message%', ['%name%' => 'broken', '%message%' => 'plugin.php must return a PortaPlugin instance']), $error);
        $this->assertSame([], $manager->warnings, 'the caller owns the failure, no warning is queued');
    }

    public function testEmbeddedDirResolvesTheEnvOverrideThenThePackage(): void
    {
        // phpunit.xml.dist pins the env for every test; assert both arms.
        $this->assertSame('/nonexistent', PluginLoader::embeddedDir());

        $pinned = (string) \getenv('PORTA_EMBEDDED_PLUGINS');
        \putenv('PORTA_EMBEDDED_PLUGINS=');
        try {
            $this->assertStringEndsWith('/plugins', PluginLoader::embeddedDir(), 'empty env → the package dir');
            $this->assertFileExists(PluginLoader::embeddedDir().'/psalm/plugin.php', 'and it holds the bundled plugins');
        } finally {
            \putenv('PORTA_EMBEDDED_PLUGINS='.$pinned);
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

        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
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
        $off = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), $embedded->dir)->loadInto($off);
        $this->assertFalse($off->hasPlugin('bundled'), 'a bundled plugin is off by default');
        $this->assertTrue($off->hasPlugin('local'), 'a project plugin loads by default');

        // Opting the bundled plugin in loads it.
        $on = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
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
            '.porta/plugins/dependent/composer.json' => '{"extra":{"porta":{"requires":["needy"]}}}',
            '.porta/plugins/dependent/plugin.php' => $this->pluginSource('dependent'),
            '.porta/plugins/unrelated/plugin.php' => $this->pluginSource('unrelated'),
        ]);

        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('needy'));
        $this->assertFalse($manager->hasPlugin('dependent'), 'the skip propagates');
        $this->assertTrue($manager->hasPlugin('unrelated'), 'and stops there');
        $this->assertSame(['needy'], $manager->pendingInstalls, 'recorded for the drain that runs once the IDE is up');
        $this->assertSame([], $manager->warnings, 'and not complained about — boot is going to fix it');
    }

    public function testAPluginOwedOnlyItsAutoloadDumpIsPendingToo(): void
    {
        // Nothing is missing from vendor/; the maps that would carry a classmap
        // were simply never dumped. Same answer — it owes a composer run — so
        // it joins the same queue.
        $project = new TempProject([
            '.porta/plugins/undumped/composer.json' => '{"autoload":{"classmap":["lib/"]}}',
            '.porta/plugins/undumped/plugin.php' => $this->pluginSource('undumped'),
        ]);

        $manager = $this->load($project);

        $this->assertFalse($manager->hasPlugin('undumped'));
        $this->assertSame(['undumped'], $manager->pendingInstalls);
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
        $this->assertSame([], $manager->warnings, 'and is not warned about');
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
        $warnings = self::renderAll($manager->warnings, ' | ');
        $this->assertStringContainsString('Plugin "shadowy" vendors symfony/clock, which Porta ships', $warnings);
        $this->assertStringNotContainsString('vend/lib', $warnings, 'a package Porta does not ship is not its business');
    }

    public function testDisabledPluginNamesAreSkippedInBothTheOptOutAndEmbeddedTiers(): void
    {
        $embedded = new TempProject(['bundled/plugin.php' => $this->pluginSource('bundled')]);
        $project = new TempProject([
            '.porta/plugins/demo/plugin.php' => $this->pluginSource('demo'),
            '.porta/plugins/keep/plugin.php' => $this->pluginSource('keep'),
        ]);

        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        new PluginLoader($project->dir, null, new PluginSettings($project->dir, []), $embedded->dir, ['demo', 'bundled'], ['bundled'])->loadInto($manager);

        $this->assertFalse($manager->hasPlugin('demo'), 'a disabled project plugin is skipped');
        $this->assertTrue($manager->hasPlugin('keep'), 'a non-disabled sibling still loads');
        $this->assertFalse($manager->hasPlugin('bundled'), 'disabling an embedded plugin wins even though it is also opted in via enabledPlugins');
    }

    public function testALeftoverPluginJsonIsNamedRatherThanIgnored(): void
    {
        // Its fields moved to composer.json and it is not read any more. The
        // plugin still loads — a plugin that ships no classes and requires
        // nothing loses nothing — but a plugin that relied on it would lose its
        // classes in silence, which is the one failure worth a warning.
        $project = new TempProject([
            '.porta/plugins/legacy/plugin.json' => '{"namespace":"Legacy","autoload":"src"}',
            '.porta/plugins/legacy/plugin.php' => $this->pluginSource('legacy'),
        ]);

        $manager = $this->load($project);

        $this->assertTrue($manager->hasPlugin('legacy'));
        $warnings = self::renderAll($manager->warnings, ' | ');
        $this->assertStringContainsString('Plugin "legacy" still has a plugin.json', $warnings);
        $this->assertStringContainsString('autoload.psr-4', $warnings, 'and says where the fields went');
    }
}
