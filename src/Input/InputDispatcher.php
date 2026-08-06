<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\State\OverlayHost;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * The global input front door, registered as the Tui's input listener: mouse
 * sequences route to the pane under the pointer, modal overlays capture the
 * keyboard, and whatever remains is matched against the key-bound commands
 * before falling through to the focused widget's own bindings.
 */
#[AsIdeService]
final class InputDispatcher
{
    public function __construct(
        private readonly Tui $tui,
        private readonly MouseRouter $mouseRouter,
        private readonly ContextMenu $contextMenu,
        private readonly MenuBarWidget $menuBar,
        private readonly WelcomeModalController $welcomeModal,
        private readonly CodeEditorWidget $editor,
        private readonly CommandRegistry $commands,
        private readonly LiveKeybindings $keybindings,
        private readonly OverlayHost $overlays,
    ) {}

    public function dispatch(InputEvent $event): void
    {
        $data = $event->getData();

        if (null !== ($mouse = MouseEvent::parse($data))) {
            $event->stopPropagation();
            $this->mouseRouter->route($mouse);

            return;
        }

        // An open context menu captures the keyboard modally: arrows/Enter/Esc
        // drive it and every other key is swallowed, so nothing leaks to the
        // widget that still owns focus underneath it.
        if ($this->contextMenu->isOpen()) {
            $event->stopPropagation();
            $this->contextMenu->handleInput($data);
            $this->tui->requestRender();

            return;
        }

        // The Welcome modal captures the keyboard whole while open: its widget
        // is never mounted, so no key may fall through to the focused pane
        // beneath the box. An open app menu wins, though — it is reachable by
        // mouse over the modal, and its keys (arrows, Esc) flow through the
        // focused-widget path below, so swallowing here would trap it open.
        if ($this->welcomeModal->isOpen() && !$this->menuBar->isOpen()) {
            $event->stopPropagation();
            $this->welcomeModal->handleKey($data);
            $this->tui->requestRender();

            return;
        }

        // The workspace pickers — Search Everywhere, the list overlay and the
        // mini prompt — capture the keyboard while open. No attachment guard:
        // they are painted above every pane by their own presenter, so they are
        // visible wherever the editor is, and a visible modal must take keys.
        if ($this->overlays->isSearchOpen()) {
            $event->stopPropagation();
            $this->editor->handleSearchOverlayInput($data);
            $this->tui->requestRender();

            return;
        }
        if ($this->overlays->handleInput($data, $this->editor->getKeybindings())) {
            $event->stopPropagation();
            $this->tui->requestRender();

            return;
        }

        // The Find-in-Files modal is an EDITOR feature (it scopes to the open
        // buffer) and stays editor-hosted, so it keeps its attachment guard.
        if (null !== $this->editor->getContext() && $this->editor->isFindModalOpen()) {
            $event->stopPropagation();
            $this->editor->handleFindModalInput($data);
            $this->tui->requestRender();

            return;
        }

        foreach ($this->commands->keyBoundIds() as $id) {
            if (!($this->keybindings->matches($data, $id) && $this->commands->isEnabled($id))) {
                continue;
            }

            $event->stopPropagation();
            $this->commands->run($id);

            return;
        }
    }
}
