<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Debug\DebugSessionObserver;

/** Feeds every session event to the recorder as one "name:detail" string. */
final readonly class RecordingDebugObserver implements DebugSessionObserver
{
    /** @param \Closure(string): void $recorder */
    public function __construct(
        private \Closure $recorder,
    ) {}

    public function onStopped(string $reason): void
    {
        ($this->recorder)("stopped:{$reason}");
    }

    public function onContinued(): void
    {
        ($this->recorder)('continued');
    }

    public function onOutput(string $category, string $text): void
    {
        ($this->recorder)("output:{$category}:".\trim($text));
    }

    public function onTerminated(): void
    {
        ($this->recorder)('terminated');
    }

    public function onError(string $message): void
    {
        ($this->recorder)("error:{$message}");
    }
}
