<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * The debug panel's outbound hooks, wired in one call instead of four fluent
 * setters — the {@see \Porta\Widget\Git\DiffViewCallbacks} shape. Every slot
 * is nullable: a bare widget fires nothing.
 */
final readonly class DebugPanelCallbacks
{
    /**
     * @param (callable(int, string, int): void)|null                                                                $onFrameSelected  frameId, path, 0-based line
     * @param (callable(int, callable(list<array{name: string, value: string, variablesReference: int}>): void): void)|null $onExpandVariable
     * @param (callable(int, string, string): void)|null                                                             $onEditVariable   a variable's edit key: parent reference, name, current value
     * @param (callable(int): void)|null                                                                             $onRemoveWatch    a watch row's Delete key, by watch index
     */
    public function __construct(
        public mixed $onFrameSelected = null,
        public mixed $onExpandVariable = null,
        public mixed $onEditVariable = null,
        public mixed $onRemoveWatch = null,
    ) {}
}
