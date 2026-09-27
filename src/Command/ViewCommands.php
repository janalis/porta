<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Editor\BookmarkActions;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\EditorDecorationSink;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\LastEditNavigator;
use Porta\Editor\RecentLocationActions;
use Porta\Layout\LayoutController;
use Porta\Lsp\CodeLensController;
use Porta\Lsp\FoldingController;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Settings\SettingsController;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\WorkbenchActions;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/** View/window palette commands: tabs, overlays, panels and the full views. */
#[AsTaggedItem(priority: 200)]
final readonly class ViewCommands implements CommandProvider
{
    public function __construct(
        private WorkbenchActions $workbench,
        private BufferManager $buffers,
        #[Autowire(service: 'porta.go_to_file')]
        private SearchEverywhereController $goToFile,
        private SettingsController $settings,
        private RunController $run,
        private BottomDock $dock,
        private LayoutController $layout,
        private ViewRegistry $views,
        private BufferService $bufferService,
        private FoldingController $folding,
        private BookmarkActions $bookmarks,
        private EditorDisplayOptions $display,
        private CodeLensController $lenses,
        private EditorDecorationSink $decorations,
        private RecentLocationActions $recentLocations,
        private LastEditNavigator $lastEdit,
        private FileTreeWidget $tree,
        private Tui $tui,
        private string $docsDir,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('toggle_bookmark', fn () => $this->bookmarks->toggle(), keyBound: KeyDispatch::Bound)
            ->add('ide_bookmarks', fn () => $this->bookmarks->show(), keyBound: KeyDispatch::Bound)
            ->add('ide_recent_locations', fn () => $this->recentLocations->show(), keyBound: KeyDispatch::Bound)
            ->add('ide_last_edit', fn () => $this->lastEdit->goBack(), keyBound: KeyDispatch::Bound)
            ->add(
                'ide_maximise_panel',
                fn () => $this->layout->toggleBottomPanelMaximised(),
                keyBound: KeyDispatch::Bound,
                // Offered only when there is something to maximise, the way
                // `open_as_text` is gated on the tab being a viewer.
                when: fn (): bool => null !== $this->dock->visiblePanel(),
            )
            ->add('toggle_soft_wrap', fn (): bool => $this->display->setSoftWrap(!$this->display->softWrap), keyBound: KeyDispatch::Bound, checked: fn (): bool => $this->display->softWrap)
            ->add('toggle_code_lenses', $this->toggleCodeLenses(...), keyBound: KeyDispatch::Bound, checked: fn (): bool => $this->display->codeLenses)
            ->add(
                'ide_project_tree',
                function (): void {
                    $this->tui->setFocus($this->tree);
                    $this->tui->requestRender();
                },
                keyBound: KeyDispatch::Bound,
            )
            ->add(
                'tree_select_opened_file',
                function (): void {
                    // Reveal AND focus: the point of the chord is to carry on
                    // navigating in the tree from where the caret already is.
                    $active = $this->buffers->active();
                    if (null !== $active) {
                        $this->tree->revealPath($active->path);
                    }
                    $this->tui->setFocus($this->tree);
                    $this->tui->requestRender();
                },
                keyBound: KeyDispatch::Bound,
            )
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
            ->add('ide_notifications', fn () => $this->workbench->showNotificationLog(), keyBound: KeyDispatch::Bound, label: 'Notification Log')
            ->add('ide_dismiss_toast', fn () => $this->workbench->dismissToast(), keyBound: KeyDispatch::Bound, label: 'Dismiss Notification')
            ->add('ide_lsp_log', fn () => $this->workbench->showLspLog(), label: 'LSP: Show Server Log')
            ->add('cycle_diagnostic_messages', fn () => $this->settings->cycleChoice('diagnosticMessages'), label: 'Cycle Diagnostic Messages')
            ->add('cycle_inlay_hints', fn () => $this->settings->cycleChoice('inlayHints'), label: 'Cycle Inlay Hints')
            ->add('cycle_line_numbers', fn () => $this->settings->cycleChoice('lineNumbers'), keyBound: KeyDispatch::Bound)
            ->add('toggle_auto_pairs', fn () => $this->settings->toggle('autoPairs'), label: 'Toggle Auto-Pairs', checked: fn (): bool => $this->settings->isOn('autoPairs'))
            ->add('toggle_format_on_save', fn () => $this->settings->toggle('formatOnSave'), label: 'Toggle Format on Save', checked: fn (): bool => $this->settings->isOn('formatOnSave'))
            ->add('fold_all', fn () => $this->folding->foldAll(), keyBound: KeyDispatch::Bound)
            ->add('unfold_all', fn () => $this->folding->unfoldAll(), keyBound: KeyDispatch::Bound)
            ->add('ide_terminal', fn () => $this->run->toggleTerminal(), keyBound: KeyDispatch::Bound)
            ->add('dock_next_panel', fn () => $this->dock->cycle(1), keyBound: KeyDispatch::Bound, when: fn (): bool => null !== $this->dock->visiblePanel())
            ->add('dock_prev_panel', fn () => $this->dock->cycle(-1), keyBound: KeyDispatch::Bound, when: fn (): bool => null !== $this->dock->visiblePanel())
            ->add('ide_terminal_new', fn () => $this->run->newTerminalSession(), keyBound: KeyDispatch::Bound)
            ->add('ide_run', fn () => $this->run->openRunMenu(), keyBound: KeyDispatch::Bound);
        $commands
            ->add('ide_settings', fn () => $this->views->toggle('settings'), keyBound: KeyDispatch::Bound)
            // No ActionCatalog entry and no default key: a keyless catalog entry
            // must be bound by some widget, which is wrong for an IDE command,
            // and this is a menu/palette action rather than a keystroke. Hence
            // the explicit label.
            ->add(
                'ide_welcome',
                fn () => $this->views->toggle('welcome'),
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

    /**
     * Turning lenses ON asks for them straight away — otherwise nothing shows
     * until the next save, which reads as the toggle not working. Turning them
     * OFF clears what is on screen, so the rows go with the setting.
     */
    private function toggleCodeLenses(): void
    {
        $on = !$this->display->codeLenses;
        $this->display->setCodeLenses($on);
        $on ? $this->lenses->requestCodeLenses() : $this->decorations->setCodeLenses([]);
    }
}
