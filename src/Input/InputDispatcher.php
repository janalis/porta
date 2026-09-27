<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\ActionCatalog;
use Porta\Config\CommandRegistry;
use Porta\Config\LiveKeybindings;
use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\State\OverlayHost;
use Porta\Overlay\State\TooltipCenter;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * The global input front door, registered as the Tui's input listener: mouse
 * sequences route to the pane under the pointer, modal overlays capture the
 * keyboard, and whatever remains is matched against the key-bound commands
 * before falling through to the focused widget's own bindings.
 */
#[AsIdeService]
final readonly class InputDispatcher
{
    public function __construct(
        private Tui $tui,
        private MouseRouter $mouseRouter,
        private ContextMenu $contextMenu,
        private MenuBarWidget $menuBar,
        private WelcomeModalController $welcomeModal,
        private CodeEditorWidget $editor,
        private CommandRegistry $commands,
        private LiveKeybindings $keybindings,
        private OverlayHost $overlays,
        private TreeDragRouter $treeDrag,
        private Notifier $notifier,
        private Translator $translator,
        private TooltipCenter $tooltips,
    ) {}

    public function dispatch(InputEvent $event): void
    {
        $data = $event->getData();

        if (null !== ($mouse = MouseEvent::parse($data))) {
            $event->stopPropagation();
            // A press, a release or a wheel is the user acting, not reading:
            // the card goes. A bare move is the hover path's to judge.
            if (!$mouse->isMove()) {
                $this->tooltips->dismiss();
            }
            $this->mouseRouter->route($mouse);

            return;
        }

        $this->tooltips->dismiss();

        // Escape abandons a file-tree drag in flight. Deliberately NOT swallowed:
        // the drag is a mouse gesture with the button still held, and Escape's
        // own jobs — closing a menu, leaving a view — are unrelated and still
        // have to run. Every fresh left press cancels it too, so this only
        // matters for a drag the user talks themselves out of mid-gesture.
        if ("\x1b" === $data) {
            $this->treeDrag->cancel();
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
            if (!$this->keybindings->matches($data, $id) || !$this->commands->isEnabled($id)) {
                continue;
            }

            $event->stopPropagation();
            $this->commands->run($id);

            return;
        }

        $this->explainAnAdoptedVerbNobodyImplements($data);
    }

    /**
     * A key the catalog binds, that nothing answers to, said out loud.
     *
     * An ADOPTABLE id is core naming a verb that a bundled plugin implements —
     * `Alt+8` is Show TODOs, implemented by `plugins/todo`. With that plugin
     * unlisted the command is never registered, so it is not even in the loop
     * above: the key matches nothing and the press does nothing, in silence.
     * The menu at least hides its own entry; the KEY had no such courtesy.
     *
     * The trap that gets you here is `enabledPlugins`: it REPLACES the bundled
     * defaults rather than adding to them, so a list written to add a language
     * server drops every default it does not repeat. That is deliberate and
     * stays — this is the sentence that makes it visible, at the moment the
     * missing verb is actually reached for rather than at every boot.
     */
    private function explainAnAdoptedVerbNobodyImplements(string $data): void
    {
        foreach (\array_keys(ActionCatalog::ACTIONS) as $id) {
            // `has()` is the short circuit that makes this free: with every
            // bundled plugin loaded it is true for all eighteen adoptable ids
            // and nothing reaches the descriptor match below. The cost appears
            // only for someone who turned one off — who is the person this
            // sentence exists for.
            //
            // Deliberately NOT gated on the catalog declaring a default key:
            // thirteen of the eighteen have none (git's verbs live on its own
            // menu), and `matches()` reads the LIVE map, so a user who bound
            // one of those by hand is exactly as entitled to the explanation.
            if (!ActionCatalog::isAdoptable($id) || $this->commands->has($id) || !$this->keybindings->matches($data, $id)) {
                continue;
            }

            $this->notifier->flash($this->translator->trans(
                '%action% comes from a bundled plugin that "enabledPlugins" does not list',
                ['%action%' => $this->translator->trans(ActionCatalog::label($id))],
            ));

            return;
        }
    }
}
