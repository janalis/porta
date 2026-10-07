<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * One inlay hint as the editor keeps it: the byte column it sits before, the
 * label it shows, what kind of hint it is, and on which sides the server asked
 * for a gap between it and the code.
 *
 * The gap is a margin, not part of the label: a pill is shaded under its
 * label only, so `f(name: 42)` reads as a chip beside the argument rather
 * than as a chip that swallowed the space after it.
 */
final readonly class InlayHint
{
    public function __construct(
        public int $col,
        /** Trimmed, whitespace collapsed, control characters removed. */
        public string $label,
        public ?InlayHintKind $kind = null,
        public bool $padLeft = false,
        public bool $padRight = false,
    ) {}

    /** Cells the hint takes in its row: the label and its margins. */
    public function width(): int
    {
        return (int) $this->padLeft + TextMetrics::displayWidth($this->label, 1) + (int) $this->padRight;
    }
}
