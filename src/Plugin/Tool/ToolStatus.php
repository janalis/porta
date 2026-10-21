<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * One declared tool as it stands right now: whether it is there, where, what
 * version it says it is, and which of its sources manages it — the one an
 * update goes through, or null when Porta did not and could not install it.
 */
final readonly class ToolStatus
{
    /**
     * @param string|null     $path    where it was found; null when missing, or when
     *                                 a plugin that registered it in code only said "it is there"
     * @param string          $version what it names itself ('' unknown or not probed yet)
     * @param ToolSource|null $manager the source that manages the installed copy
     */
    public function __construct(
        public ToolRequirement $tool,
        public bool $available,
        public ?string $path = null,
        public string $version = '',
        public ?ToolSource $manager = null,
    ) {}

    /** Whether a missing tool costs its plugin its job, rather than one feature. */
    public function blocks(): bool
    {
        return !$this->available && $this->tool->role->blocksWhenMissing();
    }
}
