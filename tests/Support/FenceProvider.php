<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Help\Fence\FenceRenderQueue;
use Porta\Help\Fence\FenceResult;
use Porta\Help\Fence\MarkdownFenceRegistry;

/** One manually completed provider shared by session, layout and widget interaction regressions. */
final class FenceProvider
{
    public readonly FakeScheduler $scheduler;
    public readonly MarkdownFenceRegistry $registry;
    public readonly FenceRenderQueue $queue;
    /** @var list<array{source: string, success: \Closure(FenceResult): void, error: \Closure(string): void}> */
    public array $requests = [];

    public function __construct()
    {
        $this->scheduler = new FakeScheduler();
        $this->registry = new MarkdownFenceRegistry();
        $this->queue = new FenceRenderQueue($this->scheduler);
        $this->registry->add([[
            'plugin' => 'diagrams',
            'languages' => ['mermaid'],
            'render' => function (string $language, string $source, string $path, int $width, \Closure $success, \Closure $error): \Closure {
                $this->requests[] = ['source' => $source, 'success' => $success, 'error' => $error];

                return static function (): void {};
            },
        ]]);
    }

    public function drain(): void
    {
        while ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }
}
