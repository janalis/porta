<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** An action can select only an element of its still-current fence result. */
final readonly class FenceContext
{
    /** @param \Closure(string): void $select */
    public function __construct(
        public int $number,
        private \Closure $select,
    ) {}

    public function select(string $id): void
    {
        ($this->select)($id);
    }
}
