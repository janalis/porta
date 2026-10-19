<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** Provider controls are invoked only by an explicitly followed generated action. */
final readonly class FenceAction
{
    /** @param \Closure(FenceContext): void $run */
    public function __construct(
        public string $id,
        public string $label,
        public \Closure $run,
    ) {
        if ('' === $label || \strlen($label) > 512 || 1 !== \preg_match('/^[a-z][a-z0-9-]{0,63}$/', $id)) {
            throw new \InvalidArgumentException('Invalid fence action');
        }
    }
}
