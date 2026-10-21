<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * What one install or update produced: how it ended, the version the tool
 * now names ('' when not known yet), and, for a failure, why — flash-sized.
 */
final readonly class ToolResult
{
    public function __construct(
        public ToolOutcome $outcome,
        public string $version = '',
        public string $why = '',
    ) {}

    public function succeeded(): bool
    {
        return ToolOutcome::Failed !== $this->outcome;
    }
}
