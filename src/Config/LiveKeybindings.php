<?php

declare(strict_types=1);

namespace Porta\Config;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * The rebuildable keybindings holder — the {@see \Porta\Ui\Theme\Styles}
 * switchable pattern applied to key dispatch. Consumers hold this one
 * instance as a readonly reference; {@see rebuild()} re-runs the factory
 * merge (IDE defaults ← plugin defaults ← platform keymap ← user keymap,
 * user last) and swaps
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
 *   BOOT instance: it is final-with-no-interface, and a widget merges its keys
 *   from that context. So the widgets whose actions the keymap page rebinds —
 *   the editor and the registered panes — are handed each rebuilt map as their
 *   explicit keybindings ({@see onRebuild()}, wired in {@see \Porta\Ide}),
 *   which the vendor merge lets win over the context's. A widget outside that
 *   set keeps reading the boot map.
 */
final class LiveKeybindings
{
    private Keybindings $current;

    /** The `keymapPlatform` setting as last applied; null keeps the boot config's. */
    private ?string $platform = null;

    /** @var list<string> the Cmd chords that have reached the IDE this session ({@see noteInput()}) */
    private array $cmdArrived = [];

    /** @var list<\Closure(Keybindings): void> */
    private array $observers = [];

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
        $this->current = $this->factory->create($this->current->getParser(), IdeConfig::load($this->rootDir)->keymap, $this->platform, $this->cmdArrived);
        foreach ($this->observers as $observer) {
            $observer($this->current);
        }
    }

    /**
     * Hand every rebuilt map to $observer — how a widget that matches keys
     * itself stops reading the boot map (see the class comment).
     *
     * @param \Closure(Keybindings): void $observer
     */
    public function onRebuild(\Closure $observer): void
    {
        $this->observers[] = $observer;
    }

    /** The platform whose keymap is live. */
    public function platform(): KeymapPlatform
    {
        return $this->factory->platform($this->platform);
    }

    /**
     * Switch to another platform's keymap (the `keymapPlatform` setting).
     *
     * Held here rather than re-read from the file on rebuild: the settings
     * page applies a value before it persists it.
     */
    public function usePlatform(string $setting): void
    {
        $this->platform = $setting;
        $this->rebuild();
    }

    /**
     * Watch the input for Cmd chords. Each one that arrives is proof this
     * terminal hands that chord over, so the map is rebuilt with it leading
     * its action's keys — the key the hints and menus name from then on.
     * Once per chord a session: a chord seen before changes nothing.
     *
     * @return bool whether this key rebuilt the map, so the menus' shortcut labels need rebuilding too
     */
    public function noteInput(string $data): bool
    {
        $parsed = $this->current->getParser()->parse($data);
        if (null === $parsed || !\in_array('super', $parsed['modifiers'], strict: true) || \in_array($parsed['key'], $this->cmdArrived, strict: true)) {
            return false;
        }
        $this->cmdArrived[] = $parsed['key'];
        $this->rebuild();

        return true;
    }
}
