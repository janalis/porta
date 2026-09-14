<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class IdeContainerFactoryTest extends TestCase
{
    private const ENV_KEYS = ['PORTA_CACHE_DIR', 'PORTA_CONTAINER_CACHE', 'XDG_CACHE_HOME', 'HOME'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var array<string, string|null> */
    private array $savedDollarEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = \getenv($key);
        }
        foreach (['PORTA_CACHE_DIR', 'PORTA_ROOT_DIR', 'PORTA_LOCALE', 'PORTA_ANSI', 'PORTA_THEME'] as $key) {
            $this->savedDollarEnv[$key] = \array_key_exists($key, $_ENV) && \is_string($_ENV[$key]) ? $_ENV[$key] : null;
        }
        // These tests exercise the caching paths themselves; an inherited
        // kill switch (e.g. a PORTA_CONTAINER_CACHE=off suite run) must not
        // reach the factory under test.
        \putenv('PORTA_CONTAINER_CACHE');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            \putenv(false === $value ? $key : $key.'='.$value);
        }
        foreach ($this->savedDollarEnv as $key => $value) {
            if (null === $value) {
                unset($_ENV[$key]);
                continue;
            }
            $_ENV[$key] = $value;
        }
    }

    private function build(?bool $trackSources = null): object
    {
        return new IdeContainerFactory($trackSources)->build('/tmp/porta-factory-test', IdeConfig::load('/tmp/porta-factory-test'), Ansi::Enabled);
    }

    public function testAnExplicitLspCommandTravelsIntoTheEnvChannel(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        new IdeContainerFactory()->build('/tmp/porta-factory-test', IdeConfig::load('/tmp/porta-factory-test'), Ansi::Enabled, 'custom-lsp-command');

        $this->assertSame('custom-lsp-command', $_ENV['PORTA_LSP_COMMAND']);
    }

    public function testLspDisabledFlagsTheEnvChannel(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        new IdeContainerFactory()->build('/tmp/porta-factory-test', IdeConfig::load('/tmp/porta-factory-test'), Ansi::Enabled, lsp: \Porta\Container\LspMode::Disabled);

        $this->assertSame('1', $_ENV['PORTA_LSP_DISABLED']);
    }

    public function testTheTranslationCacheDirTravelsIntoTheEnvChannel(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        new IdeContainerFactory()->build('/tmp/porta-factory-test', IdeConfig::load('/tmp/porta-factory-test'), Ansi::Enabled);

        $this->assertSame($project->dir.'/translations', $_ENV['PORTA_TRANSLATION_CACHE_DIR'], 'the container-bound translator dumps its catalogs beside the container cache');
    }

    public function testColdCacheDumpsAndServesTheCompiledContainer(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        $container = $this->build();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertNotInstanceOf(ContainerBuilder::class, $container, 'the dumped class is served, not the builder');
        $dumps = \glob($project->path('container-*.php'));
        $this->assertCount(1, $dumps ? $dumps : [], 'one dump file written');
        $meta = \glob($project->path('container-*.php.meta'));
        $this->assertCount(1, $meta ? $meta : [], 'resource metadata written');
        $this->assertSame('/tmp/porta-factory-test', $_ENV['PORTA_ROOT_DIR'], 'per-boot values seeded into $_ENV');
        $this->assertSame('1', $_ENV['PORTA_ANSI']);
        $locator = $container->get('porta.ide');
        $this->assertInstanceOf(ServiceLocator::class, $locator);
        $this->assertInstanceOf(Translator::class, $locator->get(Translator::class), 'env-bound services resolve from the dump through the locator');
    }

    /**
     * Recomputes the cache filename's hash from the exact same inputs the
     * factory uses, independently — so any reordering, dropped operand, or
     * off-by-one on the two dirname() depths changes the hash and this fails.
     */
    public function testTheCacheFilenameEncodesTheExactHashInputs(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        $this->build();

        $root = \dirname(__DIR__, levels: 3); // tests/Unit/Container -> project root, same depth as src/Container -> root
        $installKey = \hash('xxh32', $root);
        $versions = [];
        foreach (InstalledVersions::getAllRawData() as $dataset) {
            foreach ($dataset['versions'] as $name => $package) {
                $versions[] = $name.'@'.($package['version'] ?? '');
            }
        }
        \sort($versions);
        $hash = \hash('xxh128', \Porta\Ide::VERSION.\PHP_VERSION.$root.'/config'.$project->dir.\implode("\n", $versions));

        $this->assertFileExists($project->path("container-{$installKey}-{$hash}.php"), 'the dump filename encodes exactly this hash');
    }

    public function testWarmCacheReusesTheDumpWithoutRecompiling(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        $first = $this->build();
        $files = \glob($project->path('container-*.php'));
        [$file] = $files ? $files : [''];
        $mtime = \filemtime($file);

        $second = $this->build();

        $this->assertSame($first::class, $second::class, 'both boots share the dumped class');
        $this->assertNotSame($first, $second, 'each boot gets a fresh container instance');
        \clearstatcache();
        $this->assertSame($mtime, \filemtime($file), 'a fresh boot does not rewrite the dump');
    }

    /**
     * Two different configurations share one dump, and both still apply.
     *
     * This is the property the whole global-config design rests on: config
     * reaches services through $_ENV JSON channels and is NOT part of the
     * cache key, which is why `IdeConfig::load()` could learn to merge a
     * second file without the container, the channels or any #[Autowire]
     * consumer changing.
     *
     * The sibling test above reconstructs the hash formula, so it catches an
     * addition to the key — but it duplicates the formula, and a change made
     * in both places passes it. This asserts the behaviour instead: rewrite
     * the config, build again, and there had better still be exactly one dump.
     */
    public function testTwoConfigurationsShareOneDumpAndBothStillApply(): void
    {
        $cache = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$cache->dir);
        $root = new TempProject(['.porta.json' => \json_encode(
            ['settings' => ['theme' => 'light']],
            \JSON_THROW_ON_ERROR,
        )]);

        $first = new IdeContainerFactory()->build($root->dir, IdeConfig::load($root->dir), Ansi::Enabled);
        $files = \glob($cache->path('container-*.php'));
        [$file] = $files ? $files : [''];
        $mtime = \filemtime($file);
        $this->assertStringContainsString('light', (string) ($_ENV['PORTA_SETTINGS'] ?? ''), 'the first config reached the env channel');

        \file_put_contents(
            $root->path('.porta.json'),
            \json_encode(['settings' => ['theme' => 'high-contrast']], \JSON_THROW_ON_ERROR),
        );

        $second = new IdeContainerFactory()->build($root->dir, IdeConfig::load($root->dir), Ansi::Enabled);

        $this->assertSame($first::class, $second::class, 'one dump serves both configurations');
        $this->assertCount(1, (array) \glob($cache->path('container-*.php')), 'and no second dump was written for the second one');
        \clearstatcache();
        $this->assertSame($mtime, \filemtime($file), 'nor was the first rewritten');
        $this->assertStringContainsString(
            'high-contrast',
            (string) ($_ENV['PORTA_SETTINGS'] ?? ''),
            'while the new config still travels, which is the half that makes sharing safe',
        );
    }

    public function testStaleCacheRebuildsTheDumpAndServesTheBuilderWhenTheClassIsLoaded(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        $this->build(); // loads the dumped class into this process
        $files = \glob($project->path('container-*.php'));
        [$file] = $files ? $files : [''];
        \touch($file, mtime: 1); // epoch: older than every tracked resource → stale

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container, 'the loaded class cannot be redeclared, so the fresh builder is served');
        \clearstatcache();
        $this->assertGreaterThan(\time() - 60, \filemtime($file), 'the dump was rewritten for the next process');
    }

    public function testAPackagedInstallServesTheDumpWithoutSourceTracking(): void
    {
        // Without tracking, debug isFresh() would autoload and re-hash every
        // tracked class (~480) on each warm boot; a packaged install trusts
        // the cache key alone, so no resource metadata is even written.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);

        $this->build(trackSources: false);
        $this->assertCount(1, (array) \glob($project->path('container-*.php')), 'the dump is written');
        $this->assertSame([], (array) \glob($project->path('container-*.php.meta*')), 'no resource metadata without tracking');

        $files = \glob($project->path('container-*.php'));
        [$file] = $files ? $files : [''];
        \touch($file, mtime: 1); // older than every source file — stale ONLY under tracking

        $second = $this->build(trackSources: false);

        $this->assertInstanceOf(Container::class, $second);
        $this->assertNotInstanceOf(ContainerBuilder::class, $second, 'the mtime-stale dump is still served: existence is freshness');
        \clearstatcache();
        $this->assertSame(1, \filemtime($file), 'the dump was not rewritten');
    }

    public function testAnExplicitTrackingChoiceBeatsTheDebugEnvOverride(): void
    {
        // PORTA_CONTAINER_CACHE=debug only steers the auto-detection; a
        // constructed choice is a test seam and must win.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        \putenv('PORTA_CONTAINER_CACHE=debug');

        $this->build(trackSources: false);

        $this->assertCount(1, (array) \glob($project->path('container-*.php')));
        $this->assertSame([], (array) \glob($project->path('container-*.php.meta*')), 'explicit no-tracking ignored the env override');
    }

    public function testAChangedPackageSetDumpsItsOwnContainer(): void
    {
        // ConfigCache tracks project files, never vendor, so the installed set
        // is half the cache key: without it a `composer update` could be served
        // a container the PREVIOUS dependency set dumped.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        $this->build();
        $before = (array) \glob($project->path('container-*.php'));
        $this->assertCount(1, $before);

        // InstalledVersions is process-global vendor state: perturb it, then put
        // all three fields reload() touches back.
        $saved = [];
        foreach (['installed', 'installedByVendor', 'installedIsLocalDir'] as $field) {
            $saved[$field] = Probe::getStatic(InstalledVersions::class, $field);
        }

        try {
            $data = InstalledVersions::getAllRawData()[0];
            $data['versions']['symfony/tui']['version'] = '99.0.0.0';
            InstalledVersions::reload($data);
            $this->build();
        } finally {
            foreach ($saved as $field => $value) {
                Probe::setStatic(InstalledVersions::class, $field, $value);
            }
        }

        $after = (array) \glob($project->path('container-*.php'));
        $this->assertCount(1, $after, 'the stale generation was pruned with the new dump');
        $this->assertNotSame($before, $after, 'the upgraded set got its own dump instead of the old one');
        $this->assertCount(1, (array) \glob($project->path('container-*.php.meta')), 'the stale .meta went with it');
    }

    public function testPruningNeverEvictsAnotherInstallsGenerations(): void
    {
        // The per-user cache dir is shared; a dev checkout beside a release
        // must not have its dump evicted on every one of the other's boots.
        // Foreign generations carry a different install key in the filename.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        \file_put_contents($project->path('container-feedf00d-abc123.php'), data: '<?php // another install');
        \file_put_contents($project->path('container-feedf00d-abc123.php.meta'), data: 'meta');

        $this->build();

        $this->assertFileExists($project->path('container-feedf00d-abc123.php'), 'the foreign dump survives this install\'s GC');
        $this->assertFileExists($project->path('container-feedf00d-abc123.php.meta'), 'and so does its metadata');
        $this->assertCount(2, (array) \glob($project->path('container-*.php')), 'both installs coexist');
    }

    public function testPruningSweepsPreInstallKeyLegacyGenerations(): void
    {
        // Before the install key existed, dumps were container-<32hex>.php;
        // the keyed GC never matched them, stranding ~0.5 MB per generation
        // forever. The old naming is globally obsolete, so it is fair game.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        \file_put_contents($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php'), data: '<?php // legacy');
        \file_put_contents($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php.meta'), data: 'meta');
        \file_put_contents($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php.meta.json'), data: '{}');

        $this->build();

        $this->assertFileDoesNotExist($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php'));
        $this->assertFileDoesNotExist($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php.meta'));
        $this->assertFileDoesNotExist($project->path('container-353c64d8016a114fe6c3e529dd2d34ea.php.meta.json'));
        $this->assertCount(1, (array) \glob($project->path('container-*.php')), 'only the fresh dump remains');
    }

    public function testTheLegacyPatternIsAnchoredNotASubstringSearch(): void
    {
        // The legacy shape (container-<32hex>.php…) must match the WHOLE
        // filename, not just appear somewhere inside it — a junk prefix or a
        // trailing suffix must NOT get treated as legacy and swept.
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        $prefixed = $project->path('container-junk-container-353c64d8016a114fe6c3e529dd2d34ea.php');
        $suffixed = $project->path('container-353c64d8016a114fe6c3e529dd2d34eb.phpx');
        \file_put_contents($prefixed, data: 'not legacy: extra prefix before the real shape');
        \file_put_contents($suffixed, data: 'not legacy: trailing garbage after .php');

        $this->build();

        $this->assertFileExists($prefixed, 'a junk prefix disqualifies it — the ^ anchor is load-bearing');
        $this->assertFileExists($suffixed, 'trailing garbage disqualifies it — the $ anchor is load-bearing');
    }

    public function testGcDoesNotSpareAStaleSiblingSharingAHashPrefix(): void
    {
        // The skip check compares against the fresh hash PLUS its '.php'
        // suffix — without it, a stale file whose hash merely starts with
        // the fresh hash's digits (but isn't an exact match) would survive.
        $project = new TempProject();
        $installKey = 'aabbccdd';
        $freshHash = \str_repeat('a', times: 32);
        $staleSibling = $project->path('container-'.$installKey.'-'.$freshHash.'ff.php');
        \file_put_contents($staleSibling, data: "a stale generation whose hash is the fresh hash's own prefix");

        $method = new \ReflectionMethod(IdeContainerFactory::class, 'pruneStaleGenerations');
        $method->invoke(null, $project->dir, $installKey, $freshHash);

        $this->assertFileDoesNotExist($staleSibling, 'a prefix collision is not an exact-hash match — it must still be swept');
    }

    public function testCacheOffCompilesInMemory(): void
    {
        $project = new TempProject();
        \putenv('PORTA_CACHE_DIR='.$project->dir);
        \putenv('PORTA_CONTAINER_CACHE=off');

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container);
        $dumps = \glob($project->path('container-*'));
        $this->assertSame([], $dumps ? $dumps : [], 'no dump files written');
    }

    public function testWithoutAnyCacheLocationTheBuildFallsBackToInMemory(): void
    {
        \putenv('PORTA_CACHE_DIR=');
        \putenv('XDG_CACHE_HOME=');
        \putenv('HOME=');

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container);
        // A directly-served builder must resolve %env()% at build time — a
        // leaked placeholder here would corrupt every $rootDir in the app.
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'porta.ide resolves to a service locator');
        $store = $locator->get(SessionStore::class);
        $this->assertSame('/tmp/porta-factory-test/.porta/session.json', Probe::get($store, 'file'));
    }

    public function testXdgAndHomeFallbacksDeriveThePortaCacheDir(): void
    {
        $xdg = new TempProject();
        \putenv('PORTA_CACHE_DIR=');
        \putenv('XDG_CACHE_HOME='.$xdg->dir);
        $this->build();
        $xdgDumps = \glob($xdg->path('porta/container-*.php'));
        $this->assertCount(1, $xdgDumps ? $xdgDumps : [], 'XDG_CACHE_HOME/porta is used');

        $home = new TempProject();
        \putenv('XDG_CACHE_HOME=');
        \putenv('HOME='.$home->dir);
        $this->build();
        $homeDumps = \glob($home->path('.cache/porta/container-*.php'));
        $this->assertCount(1, $homeDumps ? $homeDumps : [], 'HOME/.cache/porta is the last fallback');
    }

    public function testUnwritableCacheDirServesTheFreshBuildUncached(): void
    {
        $project = new TempProject();
        $locked = $project->path('locked');
        \mkdir($locked, permissions: 0o500);
        \putenv('PORTA_CACHE_DIR='.$locked);

        try {
            $container = $this->build();

            $this->assertInstanceOf(ContainerBuilder::class, $container, 'an unwritable cache dir falls back to the in-memory build');
            $lockedDumps = \glob($locked.'/container-*');
            $this->assertSame([], $lockedDumps ? $lockedDumps : [], 'no dump files written to the locked cache dir');
        } finally {
            \chmod($locked, permissions: 0o700);
        }
    }
}
