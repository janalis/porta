<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;

/**
 * The global input front door, registered as the Tui's input listener: mouse
 * sequences route to the pane under the pointer, modal overlays capture the
 * keyboard, and whatever remains is matched against the key-bound commands
 * before falling through to the focused widget's own bindings.
 */
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
        private readonly Keybindings $keybindings,
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

        // The Search Everywhere modal likewise captures the keyboard while
        // open, so its query field and result navigation take every key.
        // Both editor-hosted modals are skipped while the editor is detached
        // (image tab active) — an invisible modal must never trap the keyboard.
        if (null !== $this->editor->getContext() && $this->editor->isSearchOverlayOpen()) {
            $event->stopPropagation();
            $this->editor->handleSearchOverlayInput($data);
            $this->tui->requestRender();

            return;
        }

        // The Find-in-Files modal captures the keyboard the same way.
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
