<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\SettingsController;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Lsp\FoldingController;
use Porta\Run\RunController;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\GoToFileWidget;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Tui\Tui;

/** View/window palette commands: tabs, overlays, panels and the full views. */
#[AsTaggedItem(priority: 200)]
final class ViewCommands implements CommandProvider
{
    public function __construct(
        private readonly WorkbenchActions $workbench,
        private readonly BufferManager $buffers,
        private readonly GoToFileWidget $goToFile,
        private readonly Tui $tui,
        private readonly SettingsController $settings,
        private readonly RunController $run,
        private readonly WorkspaceViews $views,
        private readonly BufferService $bufferService,
        private readonly FoldingController $folding,
        private readonly string $docsDir,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('move_tab_left', fn () => $this->workbench->moveTab(-1), keyBound: true)
            ->add('move_tab_right', fn () => $this->workbench->moveTab(1), keyBound: true)
            ->add('goto_file', function (): void {
                $this->goToFile->open();
                $this->tui->setFocus($this->goToFile);
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
            ->add('toggle_relative_line_numbers', fn () => $this->settings->toggle('relativeLineNumbers'), label: 'Toggle Relative Line Numbers')
            ->add('toggle_auto_pairs', fn () => $this->settings->toggle('autoPairs'), label: 'Toggle Auto-Pairs')
            ->add('toggle_format_on_save', fn () => $this->settings->toggle('formatOnSave'), label: 'Toggle Format on Save')
            ->add('fold_all', fn () => $this->folding->foldAll(), label: 'Fold All')
            ->add('unfold_all', fn () => $this->folding->unfoldAll(), label: 'Unfold All')
            ->add('ide_terminal', fn () => $this->run->toggleTerminal(), keyBound: true)
            ->add('ide_terminal_new', fn () => $this->run->newTerminalSession(), keyBound: true)
            ->add('ide_run', fn () => $this->run->openRunMenu(), keyBound: true)
            ->add('ide_git', fn () => $this->views->toggleGit(), keyBound: true)
            ->add('ide_settings', fn () => $this->views->toggleSettings(), keyBound: true)
            ->add(
                'ide_help',
                function (): void {
                    $this->views->closeAll();
                    $this->bufferService->openHelp($this->docsDir.'/README.md');
                },
                keyBound: true,
            );
    }
}
