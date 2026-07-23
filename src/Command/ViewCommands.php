<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Lsp\FoldingController;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Settings\SettingsController;
use Porta\Ui\BottomDock;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** View/window palette commands: tabs, overlays, panels and the full views. */
#[AsTaggedItem(priority: 200)]
final class ViewCommands implements CommandProvider
{
    public function __construct(
        private readonly WorkbenchActions $workbench,
        private readonly BufferManager $buffers,
        private readonly SearchEverywhereController $goToFile,
        private readonly SettingsController $settings,
        private readonly RunController $run,
        private readonly BottomDock $dock,
        private readonly WorkspaceViews $views,
        private readonly BufferService $bufferService,
        private readonly FoldingController $folding,
        private readonly string $docsDir,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('move_tab_left', fn () => $this->workbench->moveTab(-1), keyBound: KeyDispatch::Bound)
            ->add('move_tab_right', fn () => $this->workbench->moveTab(1), keyBound: KeyDispatch::Bound)
            ->add('goto_file', function (): void {
                // The picker composites over the editor pane (like Search
                // Everywhere), so an image view is swapped for a text buffer first.
                if ($this->bufferService->ensureTextEditorActive()) {
                    $this->goToFile->open();
                }
            })
            ->add(
                'close_other_tabs',
                function (): void {
                    if (null !== ($active = $this->buffers->active())) {
                        $this->bufferService->closeTabsExcept($active->path);
                    }
                },
                label: 'Close Other Tabs',
            )
            ->add('close_all_tabs', fn () => $this->bufferService->closeTabsExcept(null), label: 'Close All Tabs')
            ->add('ide_notifications', fn () => $this->workbench->showNotificationLog(), label: 'Notification Log')
            ->add('ide_lsp_log', fn () => $this->workbench->showLspLog(), label: 'LSP: Show Server Log')
            ->add('toggle_inline_diagnostics', fn () => $this->settings->toggle('inlineDiagnostics'), label: 'Toggle Inline Diagnostics')
            ->add('cycle_line_numbers', fn () => $this->settings->cycleLineNumbers(), keyBound: KeyDispatch::Bound)
            ->add('toggle_auto_pairs', fn () => $this->settings->toggle('autoPairs'), label: 'Toggle Auto-Pairs')
            ->add('toggle_format_on_save', fn () => $this->settings->toggle('formatOnSave'), label: 'Toggle Format on Save')
            ->add('fold_all', fn () => $this->folding->foldAll(), label: 'Fold All')
            ->add('unfold_all', fn () => $this->folding->unfoldAll(), label: 'Unfold All')
            ->add('ide_terminal', fn () => $this->run->toggleTerminal(), keyBound: KeyDispatch::Bound)
            ->add('dock_next_panel', fn () => $this->dock->cycle(1), keyBound: KeyDispatch::Bound, when: fn (): bool => null !== $this->dock->visiblePanel())
            ->add('dock_prev_panel', fn () => $this->dock->cycle(-1), keyBound: KeyDispatch::Bound, when: fn (): bool => null !== $this->dock->visiblePanel())
            ->add('ide_terminal_new', fn () => $this->run->newTerminalSession(), keyBound: KeyDispatch::Bound)
            ->add('ide_run', fn () => $this->run->openRunMenu(), keyBound: KeyDispatch::Bound)
            ->add('ide_git', fn () => $this->views->toggleGit(), keyBound: KeyDispatch::Bound)
            ->add('ide_settings', fn () => $this->views->toggleSettings(), keyBound: KeyDispatch::Bound)
            // No ActionCatalog entry and no default key: a keyless catalog entry
            // must be bound by some widget, which is wrong for an IDE command,
            // and this is a menu/palette action rather than a keystroke. Hence
            // the explicit label.
            ->add(
                'ide_welcome',
                fn () => $this->views->toggleWelcome(),
                label: 'Welcome',
            )
            ->add(
                'ide_help',
                function (): void {
                    $this->views->closeAll();
                    $this->bufferService->openHelp($this->docsDir.'/README.md');
                },
                keyBound: KeyDispatch::Bound,
            );
    }
}
