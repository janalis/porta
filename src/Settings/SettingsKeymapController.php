<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ActionCatalog;
use Porta\Config\KeybindingRow;
use Porta\Config\PortaJsonWriter;
use Porta\Editor\EditorKeymapProvider;
use Porta\I18n\Translator;
use Porta\Ui\ShortcutFormatter;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;

/**
 * The keybindings model behind the settings view's Keybindings tab: lists every
 * rebindable action with its current binding, and persists a rebind into
 * .porta.json's keymap block. The live {@see Keybindings} instance has no
 * mutator, so a rebind takes effect next launch — flagged with an "applies on
 * restart" flash.
 */
final class SettingsKeymapController
{
    public function __construct(
        private readonly EditorKeymapProvider $editor,
        private readonly Keybindings $ideKeys,
        private readonly Notifier $notifier,
        private readonly string $rootDir,
        private readonly Translator $translator,
        private readonly KeyParser $parser = new KeyParser(),
    ) {}

    /**
     * One row per catalog action, its current primary binding resolved the way
     * the menu does: the editor's merged keybindings first, then the IDE-level
     * ones.
     *
     * @return list<KeybindingRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (array_keys(ActionCatalog::ACTIONS) as $action) {
            $binding = $this->editor->getKeybindings()->getBindings($action)[0] ?? $this->ideKeys->getBindings($action)[0] ?? '';
            $rows[] = new KeybindingRow($action, $this->translator->trans(ActionCatalog::label($action)), $binding);
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
        $saved = $persisted ? '' : ' ('.$this->translator->trans('not saved').')';
        $this->notifier->flash($this->translator->trans('Rebound %action% to %key% · applies on restart', [
            '%action%' => $this->translator->trans(ActionCatalog::label($action)),
            '%key%' => ShortcutFormatter::format($token),
        ])
            .$saved);
    }
}
