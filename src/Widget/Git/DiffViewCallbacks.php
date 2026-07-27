<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

/**
 * The diff view's outbound hooks, wired in one call instead of five fluent
 * setters — the {@see \Porta\Widget\EditorCallbacks} shape, as the find modal
 * now also uses ({@see \Porta\Overlay\State\FindModalCallbacks}).
 *
 * Every slot is nullable: the read-only diff shown in the history pane wires
 * none of the mutating ones.
 */
final readonly class DiffViewCallbacks
{
    /**
     * @param (callable(array<int, array<int, true>>): void)|null $onStageSelection  stage/unstage the selected lines (hunk index => line set)
     * @param (callable(): void)|null                             $onCycleContext    `c`: cycle the diff context (default → ±10 → whole file)
     * @param (callable(): void)|null                             $onLayoutToggle    the split/unified toggle flipped (for persistence; read state via isSplit)
     * @param (callable(): void)|null                             $onToggleWhitespace `w`: hide/show whitespace changes (state lives in the controller)
     * @param (callable(string): void)|null                       $onCopy            ctrl+c: selected line texts, plain (no ± markers)
     */
    public function __construct(
        public mixed $onStageSelection = null,
        public mixed $onCycleContext = null,
        public mixed $onLayoutToggle = null,
        public mixed $onToggleWhitespace = null,
        public mixed $onCopy = null,
    ) {}
}
