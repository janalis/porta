<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\IdeContainerFactory;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
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
            $this->savedEnv[$key] = getenv($key);
        }
        foreach (['PORTA_CACHE_DIR', 'PORTA_ROOT_DIR', 'PORTA_LOCALE', 'PORTA_ANSI', 'PORTA_THEME'] as $key) {
            $this->savedDollarEnv[$key] = array_key_exists($key, $_ENV) && \is_string($_ENV[$key]) ? $_ENV[$key] : null;
        }
        // These tests exercise the caching paths themselves; an inherited
        // kill switch (e.g. a PORTA_CONTAINER_CACHE=off suite run) must not
        // reach the factory under test.
        putenv('PORTA_CONTAINER_CACHE');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv(false === $value ? $key : $key.'='.$value);
        }
        foreach ($this->savedDollarEnv as $key => $value) {
            if (null === $value) {
                unset($_ENV[$key]);
                continue;
            }
            $_ENV[$key] = $value;
        }
    }

    private function build(): object
    {
        return new IdeContainerFactory()->build('/tmp/porta-factory-test', IdeConfig::load('/tmp/porta-factory-test'), true);
    }

    public function testColdCacheDumpsAndServesTheCompiledContainer(): void
    {
        $project = new TempProject();
        putenv('PORTA_CACHE_DIR='.$project->dir);

        $container = $this->build();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertNotInstanceOf(ContainerBuilder::class, $container, 'the dumped class is served, not the builder');
        $dumps = glob($project->path('container-*.php'));
        $this->assertCount(1, $dumps ? $dumps : [], 'one dump file written');
        $meta = glob($project->path('container-*.php.meta'));
        $this->assertCount(1, $meta ? $meta : [], 'resource metadata written');
        $this->assertSame('/tmp/porta-factory-test', $_ENV['PORTA_ROOT_DIR'], 'per-boot values seeded into $_ENV');
        $this->assertSame('1', $_ENV['PORTA_ANSI']);
        $locator = $container->get('porta.ide');
        $this->assertInstanceOf(ServiceLocator::class, $locator);
        $this->assertInstanceOf(Translator::class, $locator->get(Translator::class), 'env-bound services resolve from the dump through the locator');
    }

    public function testWarmCacheReusesTheDumpWithoutRecompiling(): void
    {
        $project = new TempProject();
        putenv('PORTA_CACHE_DIR='.$project->dir);

        $first = $this->build();
        $files = glob($project->path('container-*.php'));
        [$file] = $files ? $files : [''];
        $mtime = filemtime($file);

        $second = $this->build();

        $this->assertSame($first::class, $second::class, 'both boots share the dumped class');
        $this->assertNotSame($first, $second, 'each boot gets a fresh container instance');
        clearstatcache();
        $this->assertSame($mtime, filemtime($file), 'a fresh boot does not rewrite the dump');
    }

    public function testStaleCacheRebuildsTheDumpAndServesTheBuilderWhenTheClassIsLoaded(): void
    {
        $project = new TempProject();
        putenv('PORTA_CACHE_DIR='.$project->dir);

        $this->build(); // loads the dumped class into this process
        $files = glob($project->path('container-*.php'));
        [$file] = $files ? $files : [''];
        touch($file, mtime: 1); // epoch: older than every tracked resource → stale

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container, 'the loaded class cannot be redeclared, so the fresh builder is served');
        clearstatcache();
        $this->assertGreaterThan(time() - 60, filemtime($file), 'the dump was rewritten for the next process');
    }

    public function testCacheOffCompilesInMemory(): void
    {
        $project = new TempProject();
        putenv('PORTA_CACHE_DIR='.$project->dir);
        putenv('PORTA_CONTAINER_CACHE=off');

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container);
        $dumps = glob($project->path('container-*'));
        $this->assertSame([], $dumps ? $dumps : [], 'no dump files written');
    }

    public function testWithoutAnyCacheLocationTheBuildFallsBackToInMemory(): void
    {
        putenv('PORTA_CACHE_DIR=');
        putenv('XDG_CACHE_HOME=');
        putenv('HOME=');

        $container = $this->build();

        $this->assertInstanceOf(ContainerBuilder::class, $container);
        // A directly-served builder must resolve %env()% at build time — a
        // leaked placeholder here would corrupt every $rootDir in the app.
        $locator = $container->get('porta.ide');
        \assert($locator instanceof ServiceLocator, description: 'porta.ide resolves to a service locator');
        $store = $locator->get(SessionStore::class);
        $this->assertSame('/tmp/porta-factory-test/.porta/session.json', new \ReflectionProperty($store, 'file')->getValue($store));
    }

    public function testXdgAndHomeFallbacksDeriveThePortaCacheDir(): void
    {
        $xdg = new TempProject();
        putenv('PORTA_CACHE_DIR=');
        putenv('XDG_CACHE_HOME='.$xdg->dir);
        $this->build();
        $xdgDumps = glob($xdg->path('porta/container-*.php'));
        $this->assertCount(1, $xdgDumps ? $xdgDumps : [], 'XDG_CACHE_HOME/porta is used');

        $home = new TempProject();
        putenv('XDG_CACHE_HOME=');
        putenv('HOME='.$home->dir);
        $this->build();
        $homeDumps = glob($home->path('.cache/porta/container-*.php'));
        $this->assertCount(1, $homeDumps ? $homeDumps : [], 'HOME/.cache/porta is the last fallback');
    }

    public function testUnwritableCacheDirServesTheFreshBuildUncached(): void
    {
        $project = new TempProject();
        $locked = $project->path('locked');
        mkdir($locked, permissions: 0o500);
        putenv('PORTA_CACHE_DIR='.$locked);

        try {
            $container = $this->build();

            $this->assertInstanceOf(ContainerBuilder::class, $container, 'an unwritable cache dir falls back to the in-memory build');
            $lockedDumps = glob($locked.'/container-*');
            $this->assertSame([], $lockedDumps ? $lockedDumps : [], 'no dump files written to the locked cache dir');
        } finally {
            chmod($locked, permissions: 0o700);
        }
    }
}
