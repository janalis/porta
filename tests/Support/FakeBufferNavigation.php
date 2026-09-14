<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\BufferNavigationHandler;

final readonly class FakeBufferNavigation implements BufferNavigationHandler
{
    public function __construct(
        private ?\Closure $cycleBuffer = null,
        private ?\Closure $openRecentFiles = null,
        private ?\Closure $closeBuffer = null,
    ) {}

    public function cycleBuffer(int $offset): void
    {
        if (null !== $this->cycleBuffer) {
            ($this->cycleBuffer)($offset);
        }
    }

    public function openRecentFiles(): void
    {
        if (null !== $this->openRecentFiles) {
            ($this->openRecentFiles)();
        }
    }

    public function closeBuffer(): void
    {
        if (null !== $this->closeBuffer) {
            ($this->closeBuffer)();
        }
    }
}
