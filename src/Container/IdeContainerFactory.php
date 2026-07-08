<?php

declare(strict_types=1);

namespace Porta\Container;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Builds and compiles the Porta service container — the composition root for
 * every service expressible as service+scalar injection. Runtime, per-project
 * values (rootDir, locale) arrive as parameters; the service definitions live
 * in config/services.php (outside src/, so outside the coverage gate). The
 * container is rebuilt per Ide boot because rootDir differs per project, so it
 * cannot be dumped/frozen and reused.
 */
final class IdeContainerFactory
{
    public function build(string $rootDir, string $locale, bool $ansi): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('porta.root_dir', $rootDir);
        $container->setParameter('porta.locale', $locale);
        $container->setParameter('porta.ansi', $ansi);

        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'))->load('services.php');

        $container->compile();

        return $container;
    }
}
