<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/**
 * The roughjs `Random` generator: Park–Miller's multiplier over JavaScript `Math.imul`, so a
 * seed draws the same sequence the pinned browser does. Only the low 32 bits of the state
 * matter, so they are all it keeps.
 */
final class MermaidRoughRandom
{
    public function __construct(
        private int $state,
    ) {}

    public function next(): float
    {
        $this->state = (48_271 * $this->state) & 0xFFFF_FFFF;

        return ($this->state & 0x7FFF_FFFF) / 2_147_483_648;
    }
}
