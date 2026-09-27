<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * A left-clickable status cell's column range and the action it runs.
 *
 * Carries an ACTION ID rather than a closure, unlike its right-click sibling
 * {@see StatusMenuSpan}. The menus come from contributors who already hold
 * whatever their menu needs; these cells are core's own, and an id keeps the
 * status bar — which is rebuilt on every frame — from having to reach the
 * editor and the command registry just to describe itself.
 */
final readonly class StatusClickSpan
{
    /** @param string $tooltip what the cell is and what a click on it does, for the pointer resting on it (translated) */
    public function __construct(
        public int $from,
        public int $to,
        public string $action,
        public string $tooltip = '',
    ) {}

    /** The same span shifted right by wherever the segment landed on the row. */
    public function shifted(int $by): self
    {
        return new self($this->from + $by, $this->to + $by, $this->action, $this->tooltip);
    }

    public function contains(int $col): bool
    {
        return $col >= $this->from && $col < $this->to;
    }
}
