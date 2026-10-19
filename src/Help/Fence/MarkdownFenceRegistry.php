<?php

declare(strict_types=1);

namespace Porta\Help\Fence;

/** Committed renderers in plugin load order; subscriptions have explicit lifetimes. */
final class MarkdownFenceRegistry
{
    /** @var list<array{plugin: string, languages: list<string>, render: \Closure(string, string, string, int, \Closure(FenceResult): void, \Closure(string): void): \Closure(): void}> */
    private array $renderers = [];
    /** @var array<int, \Closure(): void> */
    private array $listeners = [];
    private int $nextListener = 0;

    /** @param list<array{plugin: string, languages: list<string>, render: \Closure(string, string, string, int, \Closure(FenceResult): void, \Closure(string): void): \Closure(): void}> $renderers */
    public function add(array $renderers): void
    {
        if ([] === $renderers) {
            return;
        }
        $this->renderers = [...$this->renderers, ...$renderers];
        $this->changed();
    }

    public function remove(string $plugin): void
    {
        $remaining = \array_values(\array_filter(
            $this->renderers,
            static fn (array $row): bool => $plugin !== $row['plugin'],
        ));
        if ($remaining === $this->renderers) {
            return;
        }
        $this->renderers = $remaining;
        $this->changed();
    }

    /** Live renderer settings invalidate completed/pending fence requests. */
    public function refresh(string $plugin): void
    {
        foreach ($this->renderers as $row) {
            if ($plugin !== $row['plugin']) {
                continue;
            }
            $this->changed();

            return;
        }
    }

    /** @return (\Closure(string, string, string, int, \Closure(FenceResult): void, \Closure(string): void): \Closure(): void)|null */
    public function renderer(string $language): ?\Closure
    {
        foreach ($this->renderers as $row) {
            if (\in_array(\strtolower($language), $row['languages'], strict: true)) {
                return $row['render'];
            }
        }

        return null;
    }

    /** @param \Closure(): void $listener @return \Closure(): void */
    public function onChange(\Closure $listener): \Closure
    {
        $id = ++$this->nextListener;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    private function changed(): void
    {
        foreach ($this->listeners as $listener) {
            $listener();
        }
    }
}
