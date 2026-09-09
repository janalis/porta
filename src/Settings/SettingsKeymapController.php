<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;
use Porta\Config\CommandRegistry;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
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
        private readonly Translator $translator,
        private readonly PortaJsonWriter $writer,
        private readonly SettingsScope $scope,
        private readonly ConfigFiles $files,
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
        // Read once for the whole list rather than per action: it is a decode
        // of two small files, and asking 200-odd times would turn opening a
        // settings page into filesystem work proportional to the catalogue.
        $overrides = IdeConfig::load($this->files->rootDir())->keymap;
        $rows = [];
        foreach ($this->actions->labels() as $action => $label) {
            $binding = $this->editor->getKeybindings()->getBindings($action)[0] ?? $this->paneBinding($action) ?? $this->ideKeys->getBindings($action)[0] ?? '';
            $rows[] = new KeybindingRow($action, $this->translator->trans($label), $binding, array_key_exists($action, $overrides));
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
        $persisted = $this->writer->persistKeymap($this->scope->current(), $action, [$token]);
        if ($persisted) {
            // The file is the keymap's truth: re-merge it into the live map,
            // then rebake the menus' shortcut labels from the fresh map.
            $this->ideKeys->rebuild();
            $this->menuRebuilder->rebuild();
        }
        $saved = SettingsFlash::savedSuffix($this->translator, $persisted);
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
        $overruled = $persisted ? $this->layerOverruling($action, $token) : null;
        if (null !== $overruled) {
            // Notifier::notify() already requests a render.
            $this->notifier->warn($this->translator->trans('%action% keeps its binding — %file% sets it', [
                '%action%' => $this->translator->trans($this->actions->label($action)),
                '%file%' => $this->files->label($overruled),
            ]));

            return;
        }
        $this->notifier->flash($this->translator->trans('Rebound %action% to %key%', [
            '%action%' => $this->translator->trans($this->actions->label($action)),
            '%key%' => ShortcutFormatter::format($token),
        ])
        .$saved
        .$note);
    }

    /**
     * Drop $action's override so it goes back to the shipped default.
     *
     * The counterpart the page never had. A rebind could be made and never
     * unmade: pressing Enter on a row and then any key wrote an override —
     * even one identical to the default, which is how a `.porta.json` ends up
     * carrying `cursor_left: ["left"]` — and nothing short of hand-editing the
     * file could take it back.
     *
     * An override is not only a binding, which is why leaving one behind is
     * not harmless: it PINS the action against every future change to the
     * default, silently and forever.
     */
    public function resetBinding(string $action): void
    {
        $scope = $this->scope->current();
        if (!array_key_exists($action, self::fileKeymap($this->files->path($scope)))) {
            // Nothing to undo HERE. Said plainly rather than reported as a
            // failed write, and named by file, because the other scope may
            // well be the one holding it.
            $this->notifier->flash($this->translator->trans('%action% is not bound in %file%', [
                '%action%' => $this->translator->trans($this->actions->label($action)),
                '%file%' => $this->files->label($scope),
            ]));

            return;
        }

        $removed = $this->writer->removeKeymap($scope, $action);
        if ($removed) {
            $this->ideKeys->rebuild();
            $this->menuRebuilder->rebuild();
        }
        // Re-read AFTER the write: the default is only what fires if the other
        // file does not bind this action too, and that is exactly the case
        // where "back to X" would name a key the next keypress will not use.
        $effective = IdeConfig::load($this->files->rootDir())->keymap[$action] ?? null;
        if (null !== $effective) {
            // Notifier::notify() already requests a render.
            $this->notifier->warn($this->translator->trans('%action% keeps its binding — %file% sets it', [
                '%action%' => $this->translator->trans($this->actions->label($action)),
                '%file%' => $this->files->label(ConfigScope::Project === $scope ? ConfigScope::Global : ConfigScope::Project),
            ]));

            return;
        }
        $this->notifier->flash($this->translator->trans('Reset %action% to %key%', [
            '%action%' => $this->translator->trans($this->actions->label($action)),
            '%key%' => ShortcutFormatter::format(ActionCatalog::ACTIONS[$action]['keys'][0] ?? ''),
        ])
            .SettingsFlash::savedSuffix($this->translator, $removed));
    }

    /**
     * The `keymap` block of ONE config file, unmerged.
     *
     * {@see IdeConfig::load()} answers what the two files decided together,
     * which cannot say which of them wrote a line — and "reset" is a write to
     * one named file, so it needs the unmerged answer.
     *
     * @return array<array-key, mixed>
     */
    private static function fileKeymap(?string $path): array
    {
        /** @var mixed $config */
        $config = null === $path || !is_file($path) ? null : json_decode((string) file_get_contents($path), associative: true);
        /** @var mixed $keymap */
        $keymap = \is_array($config) ? $config['keymap'] ?? null : null;

        return \is_array($keymap) ? $keymap : [];
    }

    /**
     * The OTHER config file that overrules this rebind, or null when none does.
     *
     * `keymap` merges per ACTION: a file naming an action replaces its whole
     * chord list, never adds to it, because merging two lists of keys for one
     * action produces a binding nobody asked for. The consequence is that
     * rebinding in one file while the other also binds that action writes
     * correctly and changes nothing — and "Rebound X to Y" would then be the
     * only thing on screen and false.
     *
     * Asked of the EFFECTIVE keymap rather than of the files, so it answers
     * what the merge decided instead of re-deriving it. That is also what
     * keeps this clear of the settled decision against provenance on this
     * page: the question here is not where a binding came from — which would
     * mean unpicking three merged WIDGET keymaps — but whether the write that
     * just happened reached the top of the config stack.
     */
    private function layerOverruling(string $action, string $chord): ?ConfigScope
    {
        if ([$chord] === (IdeConfig::load($this->files->rootDir())->keymap[$action] ?? null)) {
            return null;
        }

        return ConfigScope::Project === $this->scope->current() ? ConfigScope::Global : ConfigScope::Project;
    }
}
