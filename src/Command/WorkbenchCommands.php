<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Config\PaletteVisibility;
use Porta\Editor\BufferService;
use Porta\Layout\PaneResizer;
use Porta\Run\RunController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\FindBarWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\FocusCoordinator;
use Porta\Workbench\Menu\ContextMenuOpener;
use Porta\Workbench\WorkbenchActions;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Tui;

/** The key-bound-only workbench chords: quit, focus movement, global overlays. */
#[AsTaggedItem(priority: 700)]
final class WorkbenchCommands implements CommandProvider
{
    public function __construct(
        private readonly WorkbenchActions $workbench,
        private readonly FocusCoordinator $focusCoordinator,
        private readonly ContextMenuOpener $contextMenuOpener,
        private readonly PaneResizer $paneResizer,
        private readonly BufferService $bufferService,
        private readonly SearchEverywhereController $searchEverywhere,
        #[Autowire(service: 'porta.command_palette')]
        private readonly SearchEverywhereController $commandPalette,
        private readonly RunController $run,
        private readonly EditorArea $editorArea,
        private readonly Tui $tui,
        private readonly FileTreeWidget $tree,
        private readonly MenuBarWidget $menuBar,
        private readonly FindBarWidget $findBar,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        // Key-bound only (not offered in the palette).
        $commands
            ->add('ide_quit', fn () => $this->workbench->quit(), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden)
            ->add(
                'ide_toggle_focus',
                function (): void {
                    // Whatever the editor area is showing right now — the editor
                    // or any registered pane. The old list named two of the three
                    // viewers, so a focused HELP pane toggled to itself.
                    $inEditorArea = $this->tui->getFocus() === $this->editorArea->widget();
                    $this->tui->setFocus($inEditorArea ? $this->tree : $this->editorArea->widget());
                    $this->tui->requestRender();
                },
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
            )
            ->add(
                'focus_next',
                fn () => $this->focusCoordinator->cycle(1),
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
                when: fn (): bool => !$this->menuBar->isOpen() && !$this->findBar->isOpen(),
            )
            ->add(
                'focus_previous',
                fn () => $this->focusCoordinator->cycle(-1),
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
                when: fn (): bool => !$this->menuBar->isOpen() && !$this->findBar->isOpen(),
            )
            ->add(
                'show_context_menu',
                fn () => $this->contextMenuOpener->openForFocus(),
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
                // Works in every view — the overlay composites over the current
                // view's panes; focus without a menu just flashes the no-op.
                when: fn (): bool => !$this->menuBar->isOpen(),
            )
            ->add('resize_pane_grow', fn () => $this->paneResizer->resize(1), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: fn (): bool => $this->paneResizer->canResize())
            ->add(
                'resize_pane_shrink',
                fn () => $this->paneResizer->resize(-1),
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
                when: fn (): bool => $this->paneResizer->canResize(),
            )
            ->add(
                'ide_command_palette',
                function (): void {
                    if ($this->bufferService->ensureTextEditorActive()) {
                        $this->commandPalette->open();
                    }
                },
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
            )
            ->add(
                'ide_search_everywhere',
                function (): void {
                    if ($this->bufferService->ensureTextEditorActive()) {
                        $this->searchEverywhere->open();
                    }
                },
                keyBound: KeyDispatch::Bound,
                inPalette: PaletteVisibility::Hidden,
            )
            ->add('ide_run_last', fn () => $this->run->runLast(), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden)
            ->add('ide_menu', fn () => $this->workbench->openMenu(), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden);
    }
}
