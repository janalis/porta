<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Config\IdeConfig;
use Porta\Container\Ansi;
use Porta\Container\IdeContainerFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Shared scaffolding for the container-introspection tests. It forces
 * PORTA_CONTAINER_CACHE=off so IdeContainerFactory yields the in-memory
 * ContainerBuilder (its definitions and tags are introspectable, unlike a
 * dumped container), and restores the caller's env in tearDown — paratest
 * reuses the worker, so a leaked value would perturb later tests.
 */
abstract class ContainerTestCase extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PORTA_CACHE_DIR', 'PORTA_CONTAINER_CACHE'] as $key) {
            $this->savedEnv[$key] = \getenv($key);
        }
        \putenv('PORTA_CONTAINER_CACHE=off'); // force the introspectable builder path, no dump
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            \putenv(false === $value ? $key : $key.'='.$value);
        }
    }

    /** Build the container as an introspectable ContainerBuilder (no dump). */
    protected function buildContainer(string $rootDir = '/tmp/porta-container-test'): ContainerBuilder
    {
        $container = new IdeContainerFactory()->build($rootDir, IdeConfig::load($rootDir), Ansi::Enabled);
        $this->assertInstanceOf(ContainerBuilder::class, $container, 'PORTA_CONTAINER_CACHE=off yields the introspectable builder');

        return $container;
    }
}
