<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * One flattened display row in the debugger panel — a stack frame, a scope, a
 * variable, a watch or a section heading. Only the fields relevant to its
 * {@see DebugRowKind} are set; the rest keep their inert defaults (a frame
 * carries frameId/path/line, a scope/variable/watch carries ref, a watch also
 * carries watchIndex, a variable also carries parentRef/name for setVariable).
 * Replaces the former 8-field associative array.
 */
final readonly class DebugRow
{
    public function __construct(
        public DebugRowKind $kind,
        public string $label,
        public int $frameId = -1,
        public string $path = '',
        public int $line = 0,
        public int $ref = 0,
        public int $depth = 0,
        public int $watchIndex = -1,
        public int $parentRef = 0,
        public string $name = '',
    ) {}
}
