<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BufferService;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\FileOpener;
use Porta\Overlay\Presenter\FindModalPresenter;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\Presenter\ToastPresenter;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\Region\CompositeFloatingRegions;
use Porta\Overlay\Region\FloatingRegions;
use Porta\Ui\BidiTui;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\View\ContributedView;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The interface-alias contract, now carried by Symfony's loader instead of a
 * compiler pass: PhpFileLoader::load() ends in
 * registerAliasesForSinglyImplementedInterfaces(), which aliases every Porta
 * interface with exactly one discovered implementation and leaves a
 * multi-impl interface alone (those stay explicit alias() calls in
 * services.php). Asserted on a LOAD-ONLY builder — compiling strips private
 * aliases, which is why the compiled half below checks the resolved
 * REFERENCES instead.
 */
final class ContainerAliasesTest extends ContainerTestCase
{
    public function testTheLoaderDerivesTheSingleImplAliases(): void
    {
        $builder = new ContainerBuilder();
        $loader = new PhpFileLoader($builder, new FileLocator(\dirname(__DIR__, levels: 3).'/config'));
        $loader->load('services.php');

        $expected = [
            ActiveFileProvider::class => CodeEditorWidget::class,
            EditorTextAccess::class => CodeEditorWidget::class,
            FileOpener::class => BufferService::class,
            // Explicit multi-impl binding in services.php, untouched by the derivation.
            FloatingRegions::class => CompositeFloatingRegions::class,
            // Explicit on purpose: BidiTui's def turns synthetic after the
            // loader's interface counting, so this must never rely on derivation.
            FrameHooks::class => BidiTui::class,
        ];
        foreach ($expected as $interface => $implementation) {
            $this->assertTrue($builder->hasAlias($interface), $interface.' is aliased');
            $this->assertSame($implementation, (string) $builder->getAlias($interface));
        }

        // A multi-impl interface (three FullScreenView controllers) must NOT
        // be auto-aliased — instances travel through attach(), never autowiring.
        $this->assertFalse($builder->hasAlias(FullScreenView::class), 'multi-impl interfaces stay unaliased');
    }

    /**
     * The FrameHooks seam is the one alias whose absence fails SILENTLY (the
     * presenters take it nullable and simply never lay out), so the compiled
     * wiring is pinned: each presenter's definition must reference BidiTui —
     * after compile, ResolveReferencesToAliasesPass has rewritten the
     * FrameHooks alias to its target.
     */
    public function testEveryPresenterReceivesTheFrameHooksSynthetic(): void
    {
        $container = $this->buildContainer();

        foreach ([FindModalPresenter::class, PickerModalPresenter::class, ToastPresenter::class, WelcomeModalController::class] as $presenter) {
            $references = array_filter(
                $container->getDefinition($presenter)->getArguments(),
                static fn (mixed $argument): bool => $argument instanceof Reference && BidiTui::class === (string) $argument,
            );
            $this->assertNotSame([], $references, $presenter.' is wired to the BidiTui frame hooks, not a silent null');
        }
    }
}
