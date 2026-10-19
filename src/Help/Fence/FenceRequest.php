<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** One queue slot; completion and cancellation both retire it exactly once. */
final class FenceRequest
{
    public bool $finished = false;
    /** @var (\Closure(): void)|null */
    public ?\Closure $cancel = null;

    /**
     * @param \Closure(\Closure(FenceResult): void, \Closure(string): void): \Closure(): void $start
     * @param \Closure(FenceResult): void $success
     * @param \Closure(string): void $error
     */
    public function __construct(
        public readonly \Closure $start,
        public readonly \Closure $success,
        public readonly \Closure $error,
    ) {}
}
