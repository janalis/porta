<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\KeybindingRow;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\EditorKeymapProvider;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Ui\ShortcutFormatter;
use Porta\Workbench\Menu\MenuRebuilder;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * The keybindings model behind the settings view's Keybindings tab: lists every
 * rebindable action with its current binding, and persists a rebind into
 * .porta.json's keymap block. A rebind applies LIVE: the persisted file is
 * re-merged through {@see LiveKeybindings::rebuild()} and the menus rebake
 * their shortcut labels. (Widget-level defaults still resolve through each
 * widget's own merged keymap, which reads the boot context instance — the
 * IDE-level dispatch and labels are what go live here — and a rebind that lands
 * on that side of the line says "applies on restart" rather than pretending.)
 */
final class SettingsKeymapController
{
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly CommandRegistry $commands,
        private readonly EditorKeymapProvider $editor,
        private readonly EditorPanes $panes,
        private readonly LiveKeybindings $ideKeys,
        private readonly MenuRebuilder $menuRebuilder,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        private readonly PortaJsonWriter $writer,
        private readonly KeyParser $parser = new KeyParser(),
    ) {}

    /**
     * One row per rebindable action — core's and every loaded plugin's — with
     * the binding that actually fires.
     *
     * Three tiers, the menu's two plus one: the editor's merged keybindings,
     * then the registered PANES', then the IDE-level map. The middle tier is
     * not optional — a pane's actions are bound on the pane and nowhere else,
     * so resolving through the other two shows every plugin pane's key as
     * unbound, which reads as "no key" for a key that works. Panes are
     * consulted in registration order; each widget's merged map already has the
     * user's keymap laid over its defaults, so a rebind shows through at once.
     *
     * @return list<KeybindingRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->actions->labels() as $action => $label) {
            $binding = $this->editor->getKeybindings()->getBindings($action)[0] ?? $this->paneBinding($action) ?? $this->ideKeys->getBindings($action)[0] ?? '';
            $rows[] = new KeybindingRow($action, $this->translator->trans($label), $binding);
        }

        return $rows;
    }

    /** $action's key on the first registered pane that binds it, or null. */
    private function paneBinding(string $action): ?string
    {
        foreach ($this->panes->all() as $pane) {
            $binding = $pane->getKeybindings()->getBindings($action)[0] ?? null;
            if (null !== $binding) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * Persist a captured keypress as $action's binding. The raw key data is
     * parsed into a storable token; an unparseable chord flashes instead.
     */
    public function rebind(string $action, string $rawKey): void
    {
        $parsed = $this->parser->parse($rawKey);
        if (null === $parsed) {
            $this->notifier->flash($this->translator->trans('Unsupported key — try another'));

            return;
        }

        $token = $parsed['key'];
        $persisted = $this->writer->persistKeymap($this->rootDir, $action, [$token]);
        if ($persisted) {
            // The file is the keymap's truth: re-merge it into the live map,
            // then rebake the menus' shortcut labels from the fresh map.
            $this->ideKeys->rebuild();
            $this->menuRebuilder->rebuild();
        }
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        // Two dispatch paths, and only one of them re-reads the rebuilt map:
        // `InputDispatcher` matches every key-bound COMMAND against it before
        // any widget sees the key, and IDE-scope catalog actions ride the same
        // map — those go live. Everything else is matched by the WIDGET,
        // against the keymap it merged from the boot WidgetContext (vendor-
        // owned, final, holding the instance handed to the Tui at startup):
        // every pure editor action, and every pane action a plugin declares.
        // Persisting it is real and it fires on the next launch — saying so
        // beats a flash that claims a rebind the next keypress will not honour.
        $live = null !== (ActionCatalog::ACTIONS[$action]['keys'] ?? null) || \in_array($action, $this->commands->keyBoundIds(), strict: true);
        $note = $persisted && !$live ? ' · '.$this->translator->trans('applies on restart') : '';
        $this->notifier->flash($this->translator->trans('Rebound %action% to %key%', [
            '%action%' => $this->translator->trans($this->actions->label($action)),
            '%key%' => ShortcutFormatter::format($token),
        ])
        .$saved
        .$note);
    }
}
