<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** Mutable result for one fence, kept while an edited replacement is pending. */
final class FenceBlock
{
    public ?FenceResult $result = null;
    public ?string $resultSource = null;
    public ?string $error = null;
    public bool $pending = true;
    /** @var (\Closure(): void)|null */
    public ?\Closure $cancel = null;

    public function __construct(
        public readonly string $language,
        public readonly string $source,
        public readonly int $width,
    ) {}

    public function interactive(): bool
    {
        return !$this->pending && null !== $this->result && $this->source === $this->resultSource;
    }

    public function stop(): void
    {
        $cancel = $this->cancel;
        $this->cancel = null;
        $this->pending = false;
        $cancel?->__invoke();
    }
}
