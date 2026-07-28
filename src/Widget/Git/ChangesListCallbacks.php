<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\ChangeEntry;

/**
 * The changes pane's outbound hooks, wired in one call instead of four fluent
 * setters — the {@see DiffViewCallbacks} shape. Every slot is nullable: a bare
 * widget fires nothing.
 */
final readonly class ChangesListCallbacks
{
    /**
     * @param (callable(ChangeEntry): void)|null $onSelect      selection moved to this entry
     * @param (callable(ChangeEntry): void)|null $onToggleStage checkbox toggled
     * @param (callable(ChangeEntry): void)|null $onDiscard     discard confirmed
     * @param (callable(): void)|null            $onShowHistory `2`: switch to the History tab
     */
    public function __construct(
        public mixed $onSelect = null,
        public mixed $onToggleStage = null,
        public mixed $onDiscard = null,
        public mixed $onShowHistory = null,
    ) {}
}
