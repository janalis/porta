<?php

declare(strict_types=1);

use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Config\LayoutStore;
use Porta\Editor\BufferManager;
use Porta\Git\GitInfo;
use Porta\History\LocalHistory;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\GitSidebar;
use Porta\Layout\LayoutController;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\WorkspaceEditor;
use Porta\Ui\CommandPalette;
use Porta\Ui\Notifier;
use Porta\Ui\PanelManager;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

/**
 * Porta's service definitions. Lives outside src/ so this wiring stays off the
 * 100%-line-coverage gate (phpunit's <source> includes only src/). Every
 * service is shared (the DI default), matching the app-lifetime-singleton
 * lifecycle; never use lazy: true — it would proxy-subclass a final class.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Private by default: autoregistration below relies on the removal pass
    // pruning the ~137 non-service classes (closure-wired controllers, DTOs,
    // value objects, per-buffer objects) that register but nothing public
    // references. Making defaults public would keep them all and fail autowiring.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$rootDir', param('porta.root_dir'))
        ->bind('$locale', param('porta.locale'));

    // Autoregister every Porta\ class. Only the orchestrator and the container
    // factory are excluded; everything else that isn't a real service registers
    // private and is pruned at compile.
    $services->load('Porta\\', '../src/*')
        ->exclude([
            '../src/Ide.php',
            '../src/Container/',
        ]);

    // Ide resolves these by id (service-locator boundary), so they must be
    // public to survive compilation rather than being pruned.
    foreach ([
        Translator::class,
        GitInfo::class,
        LocalHistory::class,
        SessionStore::class,
        EditorConfigResolver::class,
        BufferManager::class,
        LayoutStore::class,
        WorkspaceEditor::class,
        CompletionController::class,
        PanelManager::class,
        CommandPalette::class,
        HitTester::class,
        LayoutController::class,
    ] as $publicId) {
        $services->set($publicId)->public();
    }

    // Synthetic infrastructure: Ide builds these manually (they need per-instance
    // setId/setStyle or the terminal), then ->set()s the instances after boot.
    // Declared here so container-built controllers can depend on them by type.
    foreach ([Renderer::class, Tui::class, Notifier::class, LspSessionPool::class, CodeEditorWidget::class, FileTreeWidget::class, StatusBarWidget::class, ResultsPanelWidget::class, TerminalPanelWidget::class, SettingsNavWidget::class, GitSidebar::class] as $syntheticId) {
        $services->set($syntheticId)->synthetic();
    }
};
