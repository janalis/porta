<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Container\SingleImplAliasPass;
use Porta\Editor\ActiveFileProvider;
use Porta\Overlay\Region\CompositeFloatingRegions;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The single-impl alias derivation: a `Porta\` interface with exactly one
 * implementing service is aliased to it; two-plus implementers, an abstract
 * definition, and an already-wired interface are left untouched.
 */
final class SingleImplAliasPassTest extends TestCase
{
    private function derive(ContainerBuilder $container): void
    {
        new SingleImplAliasPass()->process($container);
    }

    public function testAliasesAnInterfaceWithExactlyOneImplementingService(): void
    {
        $container = new ContainerBuilder();
        // CodeEditorWidget implements ActiveFileProvider (among others).
        $container->setDefinition(CodeEditorWidget::class, new Definition(CodeEditorWidget::class));

        $this->derive($container);

        self::assertTrue($container->hasAlias(ActiveFileProvider::class));
        self::assertSame(CodeEditorWidget::class, (string) $container->getAlias(ActiveFileProvider::class));
    }

    public function testLeavesAMultiImplementorInterfaceUnaliased(): void
    {
        $container = new ContainerBuilder();
        // Both implement FloatingRegions → ambiguous, no derived alias.
        $container->setDefinition(CompositeFloatingRegions::class, new Definition(CompositeFloatingRegions::class));
        $container->setDefinition(MenuOverlayController::class, new Definition(MenuOverlayController::class));

        $this->derive($container);

        self::assertFalse($container->hasAlias(FloatingRegions::class), 'a 2-impl interface stays an explicit binding');
    }

    public function testSkipsAbstractDefinitionsAndAlreadyWiredInterfaces(): void
    {
        $container = new ContainerBuilder();
        // Abstract: its class is ignored entirely.
        $abstract = new Definition(CodeEditorWidget::class);
        $abstract->setAbstract(true);
        $container->setDefinition('some.abstract', $abstract);
        // A classless definition is skipped too.
        $container->setDefinition('classless', new Definition());

        $this->derive($container);

        self::assertFalse($container->hasAlias(ActiveFileProvider::class), 'an abstract-only implementor derives nothing');

        // Pre-existing alias is left as-is (not overwritten).
        $container->setDefinition(CodeEditorWidget::class, new Definition(CodeEditorWidget::class));
        $container->setAlias(ActiveFileProvider::class, 'preset');
        $this->derive($container);
        self::assertSame('preset', (string) $container->getAlias(ActiveFileProvider::class), 'an already-wired interface is untouched');
    }
}
