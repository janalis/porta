<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The single registry of IDE-level commands. Each command has a stable id, a
 * handler, and flags for whether it is bound to a key (dispatched from the input
 * listener) and/or listed in the Find Action palette. This replaces the parallel
 * `if/elseif` keybinding chain and the hand-maintained palette-command list, which
 * previously duplicated the same handlers. Labels come from {@see ActionCatalog}
 * unless a command supplies its own.
 */
final class CommandRegistry
{
    /** @var list<array{id: string, label: string, handler: \Closure(): void, keyBound: bool, inPalette: bool}> */
    private array $commands = [];

    /** @var array<string, array{id: string, label: string, handler: \Closure(): void, keyBound: bool, inPalette: bool}> first registration per id, for O(1) dispatch */
    private array $byId = [];

    public function add(string $id, \Closure $handler, bool $keyBound = false, bool $inPalette = true, ?string $label = null): self
    {
        $command = [
            'id' => $id,
            'label' => $label ?? ActionCatalog::label($id),
            'handler' => $handler,
            'keyBound' => $keyBound,
            'inPalette' => $inPalette,
        ];
        $this->commands[] = $command;
        $this->byId[$id] ??= $command;

        return $this;
    }

    /** @return list<string> ids that carry a keybinding, for the input dispatcher */
    public function keyBoundIds(): array
    {
        return array_values(array_map(
            static fn (array $c) => $c['id'],
            array_filter($this->commands, static fn (array $c) => $c['keyBound']),
        ));
    }

    /** The registered label for $id (including custom labels), or null when unknown. */
    public function label(string $id): ?string
    {
        return $this->byId[$id]['label'] ?? null;
    }

    /** Run the handler registered under $id (no-op when unknown). */
    public function run(string $id): void
    {
        if (isset($this->byId[$id])) {
            $this->byId[$id]['handler']();
        }
    }

    /**
     * The palette-visible commands, in registration order.
     *
     * @return list<array{label: string, run: callable(): void}>
     */
    public function paletteCommands(): array
    {
        $out = [];
        foreach ($this->commands as $command) {
            if ($command['inPalette']) {
                $out[] = ['label' => $command['label'], 'run' => $command['handler']];
            }
        }

        return $out;
    }
}
