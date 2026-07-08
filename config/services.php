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
use Porta\Ui\Palette;
use Porta\Ui\PaletteFactory;
use Porta\Ui\PanelManager;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\GoToFileWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
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
    $services
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$rootDir', param('porta.root_dir'))
        ->bind('$locale', param('porta.locale'));

    // Autoregister every Porta\ class. Only the orchestrator and the container
    // factory are excluded; everything else that isn't a real service registers
    // private and is pruned at compile.
    $services
        ->load('Porta\\', '../src/*')
        ->exclude([
            '../src/Ide.php',
            '../src/Container/',
        ]);

    // The wall clock (symfony/clock). A NativeClock backs Psr\Clock\ClockInterface
    // so time-dependent services autowire it; tests inject a MockClock.
    $services->set(ClockInterface::class, NativeClock::class);

    // The IDE color scheme. Widgets autowire Palette; PaletteFactory picks the
    // concrete instance (dark/light, or monochrome for --no-ansi) from the
    // porta.ansi parameter set by IdeContainerFactory. The theme is wired to the
    // porta.theme parameter separately. Public so Ide can resolve it too.
    $services
        ->set(Palette::class)
        ->factory([PaletteFactory::class, 'create'])
        ->args([param('porta.ansi'), 'dark'])
        ->public();

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

    // Singleton widgets built and configured by the container. Ide resolves each
    // (public) and does only the tree assembly / setProvider(fn…) wiring a
    // container can't express. Only `flex` styles appear here; the direction/gap
    // ContainerWidgets stay hand-assembled in Ide.
    $services->set(CodeEditorWidget::class)->public()->call('setId', ['editor']);
    $services->set(StatusBarWidget::class)->public()->call('setId', ['status']);
    $services->set(ResultsPanelWidget::class)->public()->call('setId', ['results']);
    $services->set(TerminalPanelWidget::class)->public()->call('setId', ['terminal']);
    $services
        ->set(FileTreeWidget::class)
        ->public()
        ->call('setId', ['tree'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 0])]);
    $services->set(Notifier::class)->public();

    // Git-view widgets (each takes the Translator). GitSidebar autowires from the
    // four it fans a width across; Ide does the git-pane tree assembly.
    $services
        ->set(ChangesListWidget::class)
        ->public()
        ->call('setId', ['git-changes'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 0])]);
    $services
        ->set(DiffViewWidget::class)
        ->public()
        ->call('setId', ['git-diff'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 1])]);
    $services->set(CommitBoxWidget::class)->public()->call('setId', ['git-commit']);
    $services->set(HistoryListWidget::class)->public()->call('setId', ['git-history']);
    $services
        ->set(GitTabsWidget::class)
        ->public()
        ->call('setId', ['git-tabs'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 0])]);
    $services->set(GitSidebar::class)->public();

    // Settings-view + overlay widgets (each takes the Translator).
    $services
        ->set(SettingsNavWidget::class)
        ->public()
        ->call('setId', ['settings-nav'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 0])]);
    $services
        ->set(SettingsFormWidget::class)
        ->public()
        ->call('setId', ['settings-form'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 1])]);
    $services
        ->set(KeybindingsListWidget::class)
        ->public()
        ->call('setId', ['settings-keys'])
        ->call('setStyle', [inline_service(Style::class)->args(['$flex' => 1])]);
    $services->set(GoToFileWidget::class)->public()->call('setId', ['goto-file']);
    $services->set(FindBarWidget::class)->public()->call('setId', ['find-bar']);

    // Synthetic infrastructure: external (Renderer/Tui) or closure-wired objects
    // the container can't build. Ide constructs these and ->set()s the instances
    // after boot; declared here so services can depend on them by type.
    foreach ([Renderer::class, Tui::class, LspSessionPool::class] as $syntheticId) {
        $services->set($syntheticId)->synthetic();
    }
};
