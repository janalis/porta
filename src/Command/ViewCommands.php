<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\SettingsController;
use Porta\Editor\BufferService;
use Porta\Run\RunController;
use Porta\Ui\WorkbenchActions;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\GoToFileWidget;
use Symfony\Component\Tui\Tui;

/** View/window palette commands: tabs, overlays, panels and the full views. */
final class ViewCommands implements CommandProvider
{
    public function __construct(
        private readonly WorkbenchActions $workbench,
        private readonly GoToFileWidget $goToFile,
        private readonly Tui $tui,
        private readonly SettingsController $settings,
        private readonly RunController $run,
        private readonly WorkspaceViews $views,
        private readonly BufferService $bufferService,
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
            ->add('ide_notifications', fn () => $this->workbench->showNotificationLog(), label: 'Notification Log')
            ->add('toggle_inline_diagnostics', fn () => $this->settings->toggle('inlineDiagnostics'), label: 'Toggle Inline Diagnostics')
            ->add('toggle_relative_line_numbers', fn () => $this->settings->toggle('relativeLineNumbers'), label: 'Toggle Relative Line Numbers')
            ->add('toggle_auto_pairs', fn () => $this->settings->toggle('autoPairs'), label: 'Toggle Auto-Pairs')
            ->add('ide_terminal', fn () => $this->run->toggleTerminal(), keyBound: true)
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
