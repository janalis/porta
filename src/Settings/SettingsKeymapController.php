<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ActionRegistry;
use Porta\Config\KeybindingRow;
use Porta\Config\LiveKeybindings;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\EditorKeymapProvider;
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
 * IDE-level dispatch and labels are what go live here.)
 */
final class SettingsKeymapController
{
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly EditorKeymapProvider $editor,
        private readonly LiveKeybindings $ideKeys,
        private readonly MenuRebuilder $menuRebuilder,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        private readonly KeyParser $parser = new KeyParser(),
    ) {}

    /**
     * One row per rebindable action — core's and every loaded plugin's — its
     * current primary binding resolved the way
     * the menu does: the editor's merged keybindings first, then the IDE-level
     * ones.
     *
     * @return list<KeybindingRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->actions->labels() as $action => $label) {
            $binding = $this->editor->getKeybindings()->getBindings($action)[0] ?? $this->ideKeys->getBindings($action)[0] ?? '';
            $rows[] = new KeybindingRow($action, $this->translator->trans($label), $binding);
        }

        return $rows;
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
        $persisted = PortaJsonWriter::persistKeymap($this->rootDir, $action, [$token]);
        if ($persisted) {
            // The file is the keymap's truth: re-merge it into the live map,
            // then rebake the menus' shortcut labels from the fresh map.
            $this->ideKeys->rebuild();
            $this->menuRebuilder->rebuild();
        }
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash($this->translator->trans('Rebound %action% to %key%', [
            '%action%' => $this->translator->trans($this->actions->label($action)),
            '%key%' => ShortcutFormatter::format($token),
        ])
            .$saved);
    }
}
