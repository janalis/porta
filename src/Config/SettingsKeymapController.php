<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Ui\ShortcutFormatter;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Input\KeyParser;
use Symfony\Component\Tui\Tui;

/**
 * The keybindings model behind the settings view's Keybindings tab: lists every
 * rebindable action with its current binding, and persists a rebind into
 * .porta.json's keymap block. The live {@see Keybindings} instance has no
 * mutator, so a rebind takes effect next launch — flagged with an "applies on
 * restart" flash.
 *
 * @phpstan-type Row array{action: string, label: string, binding: string}
 */
final class SettingsKeymapController
{
    public function __construct(
        private readonly CodeEditorWidget $editor,
        private readonly Keybindings $ideKeys,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly string $rootDir,
        private readonly KeyParser $parser = new KeyParser(),
    ) {}

    /**
     * One row per catalog action, its current primary binding resolved the way
     * the menu does: the editor's merged keybindings first, then the IDE-level
     * ones.
     *
     * @return list<Row>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (ActionCatalog::ACTIONS as $action => $spec) {
            $binding = $this->editor->getKeybindings()->getBindings($action)[0] ?? $this->ideKeys->getBindings($action)[0] ?? '';
            $rows[] = ['action' => $action, 'label' => ActionCatalog::label($action), 'binding' => $binding];
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
            $this->statusBar->flash('Unsupported key — try another');
            $this->tui->requestRender();

            return;
        }

        $token = $parsed['key'];
        $persisted = IdeConfig::persistKeymap($this->rootDir, $action, [$token]);
        $this->statusBar->flash(\sprintf(
            'Rebound %s to %s · applies on restart%s',
            ActionCatalog::label($action),
            ShortcutFormatter::format($token),
            $persisted ? '' : ' (not saved)',
        ));
        $this->tui->requestRender();
    }
}
