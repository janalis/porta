<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Container\AsIdeService;

/**
 * The single registry of IDE-level commands. Each command has a stable id, a
 * handler, and flags for whether it is bound to a key (dispatched from the input
 * listener) and/or listed in the Find Action palette. This replaces the parallel
 * `if/elseif` keybinding chain and the hand-maintained palette-command list, which
 * previously duplicated the same handlers. Labels come from {@see ActionCatalog}
 * unless a command supplies its own.
 */
#[AsIdeService]
final class CommandRegistry
{
    /** @var list<array{id: string, label: string, handler: \Closure(): void, keyBound: bool, inPalette: bool, when: (\Closure(): bool)|null, checked: (\Closure(): bool)|null}> */
    private array $commands = [];

    /** @var array<string, array{id: string, label: string, handler: \Closure(): void, keyBound: bool, inPalette: bool, when: (\Closure(): bool)|null, checked: (\Closure(): bool)|null}> first registration per id, for O(1) dispatch */
    private array $byId = [];

    /**
     * @param (\Closure(): bool)|null $when gates KEY dispatch (a false `when` lets the key fall
     *                                      through to the focused widget) and MENU items, which
     *                                      grey out on it. The palette still runs the handler
     *                                      directly, so a gated handler must also refuse for
     *                                      itself — and must do so BEFORE it prompts, not after.
     * @param (\Closure(): bool)|null $checked a toggle's state: the menu draws its item with a ✓
     *                                         while this answers true, as a menu's checkbox item
     */
    public function add(
        string $id,
        \Closure $handler,
        KeyDispatch $keyBound = KeyDispatch::Unbound,
        PaletteVisibility $inPalette = PaletteVisibility::Listed,
        ?string $label = null,
        ?\Closure $when = null,
        ?\Closure $checked = null,
    ): self {
        $command = [
            'id' => $id,
            'label' => $label ?? ActionCatalog::label($id),
            'handler' => $handler,
            'keyBound' => KeyDispatch::Bound === $keyBound,
            'inPalette' => PaletteVisibility::Listed === $inPalette,
            'when' => $when,
            'checked' => $checked,
        ];
        $this->commands[] = $command;
        $this->byId[$id] ??= $command;

        return $this;
    }

    /**
     * Remove commands by id (live plugin deactivation): key dispatch and the
     * palette pick the change up automatically since both derive from the list.
     *
     * @param list<string> $ids
     */
    public function remove(array $ids): void
    {
        $drop = \array_fill_keys($ids, value: true);
        $this->commands = \array_values(\array_filter($this->commands, static fn (array $c): bool => null === ($drop[$c['id']] ?? null)));
        foreach ($ids as $id) {
            unset($this->byId[$id]);
        }
        foreach ($this->commands as $command) {
            $this->byId[$command['id']] ??= $command; // restore first-wins for surviving duplicates
        }
    }

    /** Whether $id's key dispatch is currently enabled (unknown ids are not). */
    public function isEnabled(string $id): bool
    {
        if (null === ($this->byId[$id] ?? null)) {
            return false;
        }
        $when = $this->byId[$id]['when'];

        return null === $when || $when();
    }

    /** @return list<string> ids that carry a keybinding, for the input dispatcher */
    public function keyBoundIds(): array
    {
        return \array_values(\array_map(
            static fn (array $c): string => $c['id'],
            \array_filter($this->commands, static fn (array $c): bool => $c['keyBound']),
        ));
    }

    /** Whether anything answers to $id — false for a command whose plugin is disabled or gone. */
    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->byId);
    }

    /** @return (\Closure(): bool)|null a toggle's state predicate, or null when $id is no toggle (or unknown) */
    public function checkedState(string $id): ?\Closure
    {
        return $this->byId[$id]['checked'] ?? null;
    }

    /** The registered label for $id (including custom labels), or null when unknown. */
    public function label(string $id): ?string
    {
        return $this->byId[$id]['label'] ?? null;
    }

    /** Run the handler registered under $id (no-op when unknown). */
    public function run(string $id): void
    {
        if (null !== ($this->byId[$id] ?? null)) {
            $this->byId[$id]['handler']();
        }
    }

    /**
     * The palette-visible commands, in registration order.
     *
     * The id travels with the label because the palette prints each action's
     * KEY beside it — the way anyone learns a shortcut is seeing it next to the
     * thing they just searched for — and only the id can be asked for that.
     * `enabled` is the same `when` the menus grey their items on, so the
     * palette shows an unavailable command as unavailable rather than as a row
     * that silently does nothing.
     *
     * @return list<array{id: string, label: string, run: callable(): void, enabled: bool}>
     */
    public function paletteCommands(): array
    {
        $out = [];
        foreach ($this->commands as $command) {
            if (!$command['inPalette']) {
                continue;
            }

            $out[] = ['id' => $command['id'], 'label' => $command['label'], 'run' => $command['handler'], 'enabled' => null === $command['when'] || $command['when']()];
        }

        return $out;
    }
}
