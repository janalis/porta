<?php

declare(strict_types=1);

namespace Porta\Config;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * The rebuildable keybindings holder — the {@see \Porta\Ui\Theme\Styles}
 * switchable pattern applied to key dispatch. Consumers hold this one
 * instance as a readonly reference; {@see rebuild()} re-runs the factory
 * merge (IDE defaults ← plugin defaults ← user keymap, user last) and swaps
 * the backing map, so a live-activated plugin's default keys dispatch — and
 * a deactivated plugin's die — without a restart. The user keymap is
 * RE-READ from .porta.json on every rebuild: once the session writes rebinds
 * ({@see \Porta\Config\PortaJsonWriter::persistKeymap}), the file is the
 * keymap's truth, and external edits land on the next rebuild too.
 *
 * Two boundary invariants:
 * - The rebuilt map MUST reuse the current map's {@see \Symfony\Component\Tui\Input\KeyParser}:
 *   the Tui mutates that parser when the terminal negotiates the kitty
 *   protocol, and every widget keymap shares it — a fresh parser would
 *   silently un-negotiate key decoding after the first plugin toggle.
 * - The vendor boundary (Tui/BidiTui, WidgetContext, FocusManager) keeps the
 *   BOOT instance, deliberately: it is final-with-no-interface and its copies
 *   are inert for plugin ids (plugin commands dispatch in InputDispatcher
 *   before any widget sees the key).
 */
final class LiveKeybindings
{
    private Keybindings $current;

    public function __construct(
        private readonly KeybindingsFactory $factory,
        Keybindings $boot,
        private readonly string $rootDir,
    ) {
        $this->current = $boot;
    }

    /** The live map as the vendor type, for pass-down into vendor-typed params. */
    public function current(): Keybindings
    {
        return $this->current;
    }

    public function matches(string $data, string $action): bool
    {
        return $this->current->matches($data, $action);
    }

    /** @return array<array-key, string> the vendor map's own value shape */
    public function getBindings(string $action): array
    {
        return $this->current->getBindings($action);
    }

    /** Re-merge (a plugin toggled, a key rebound) and swap the backing map. */
    public function rebuild(): void
    {
        $this->current = $this->factory->create($this->current->getParser(), IdeConfig::load($this->rootDir)->keymap);
    }
}
