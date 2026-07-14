<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\BufferNavigationHandler;

final class FakeBufferNavigation implements BufferNavigationHandler
{
    public function __construct(
        private readonly ?\Closure $cycleBuffer = null,
        private readonly ?\Closure $openRecentFiles = null,
        private readonly ?\Closure $closeBuffer = null,
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
