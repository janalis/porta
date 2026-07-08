<?php

declare(strict_types=1);

use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Git\GitInfo;
use Porta\History\LocalHistory;
use Porta\History\SessionStore;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Ui\Notifier;
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

    // Public: Ide resolves these from the container by id (service-locator
    // boundary), so they must survive compilation rather than being inlined.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->public()
        ->bind('$rootDir', param('porta.root_dir'))
        ->bind('$locale', param('porta.locale'));

    // Closure-free leaves: only scalar (rootDir/locale) dependencies.
    $services->set(Translator::class);
    $services->set(GitInfo::class);
    $services->set(LocalHistory::class);
    $services->set(SessionStore::class);
    $services->set(EditorConfigResolver::class);

    // Synthetic infrastructure: Ide builds these manually (they need per-instance
    // setId/setStyle or the terminal), then ->set()s the instances after boot.
    // Declared here so container-built controllers can depend on them by type.
    foreach ([Renderer::class, Tui::class, Notifier::class, LspSessionPool::class, CodeEditorWidget::class, FileTreeWidget::class, StatusBarWidget::class, ResultsPanelWidget::class, TerminalPanelWidget::class] as $syntheticId) {
        $services->set($syntheticId)->synthetic();
    }
};
