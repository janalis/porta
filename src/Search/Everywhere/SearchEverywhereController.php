<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Symfony\Component\Tui\Tui;

/**
 * Orchestrates the Search Everywhere modal: opens the overlay, fans a query out
 * to the sync sources immediately and the async ones (LSP/grep) on a debounce,
 * merges their hits per category (capped, in category order), and pushes the
 * flattened rows to the overlay as results arrive.
 *
 * A monotonic generation token guards against stale async responses: each query
 * (and close) bumps the generation, and a source callback that arrives for an
 * older generation is dropped. An empty query shows recent files.
 */
final class SearchEverywhereController
{
    private int $generation = 0;
    private ?string $debounceWatcher = null;

    /** @var array<int, list<SearchHit>> hits keyed by category order */
    private array $hitsByCategory = [];

    /**
     * @param list<SearchSource>                $syncSources  files, actions (run immediately)
     * @param list<SearchSource>                $asyncSources classes, symbols, text (debounced)
     * @param \Closure(string, bool): void      $openFile     fn(absolutePath, focusEditor), for recent files
     * @param \Closure(float, \Closure): string $delay        schedule; returns a cancel handle
     * @param \Closure(string): void            $cancelDelay  cancel a scheduled handle
     */
    public function __construct(
        private readonly SearchOverlay $overlay,
        private readonly array $syncSources,
        private readonly array $asyncSources,
        private readonly BufferManager $buffers,
        private readonly Tui $tui,
        private readonly string $rootDir,
        private readonly \Closure $openFile,
        private readonly \Closure $delay,
        private readonly \Closure $cancelDelay,
    ) {}

    public function open(): void
    {
        ++$this->generation;
        $this->hitsByCategory = [];
        $this->seedRecentFiles();
        $this->overlay->openSearch(
            'Search Everywhere',
            fn (string $query): mixed => $this->onQuery($query),
            fn (SearchHit $hit): mixed => $this->onPick($hit),
            fn (): mixed => $this->onClose(),
        );
        $this->render();
    }

    private function onQuery(string $text): void
    {
        $generation = ++$this->generation;
        $this->hitsByCategory = [];

        if ('' === $text) {
            $this->seedRecentFiles();
            $this->render();

            return;
        }

        foreach ($this->syncSources as $source) {
            $source->query($text, $this->guard($generation, $source->category()));
        }

        $this->cancelDebounce();
        $this->debounceWatcher = ($this->delay)(0.25, function () use ($generation, $text): void {
            if ($generation !== $this->generation) {
                return;
            }
            foreach ($this->asyncSources as $source) {
                $source->cancel();
                $source->query($text, $this->guard($generation, $source->category()));
            }
        });

        $this->render();
    }

    /** @return \Closure(list<SearchHit>): void */
    private function guard(int $generation, SearchCategory $category): \Closure
    {
        return function (array $hits) use ($generation, $category): void {
            if ($generation !== $this->generation) {
                return; // a newer query (or close) superseded this response
            }
            /** @var list<SearchHit> $hits */
            $this->hitsByCategory[$category->order()] = $hits;
            $this->render();
        };
    }

    private function onPick(SearchHit $hit): void
    {
        $this->close();
        ($hit->activate)();
        $this->tui->requestRender();
    }

    private function onClose(): void
    {
        $this->close();
        $this->tui->requestRender();
    }

    private function close(): void
    {
        ++$this->generation; // any in-flight async response is now stale
        $this->cancelDebounce();
        foreach ($this->asyncSources as $source) {
            $source->cancel();
        }
        $this->overlay->closeSearch();
    }

    private function cancelDebounce(): void
    {
        if (null !== $this->debounceWatcher) {
            ($this->cancelDelay)($this->debounceWatcher);
            $this->debounceWatcher = null;
        }
    }

    private function seedRecentFiles(): void
    {
        $hits = [];
        foreach ($this->buffers->recent() as $buffer) {
            $absolute = $buffer->path;
            $relative = ProjectPaths::relative($this->rootDir, $absolute);
            $dir = \dirname($relative);
            $hits[] = new SearchHit(
                SearchCategory::Files,
                basename($relative),
                '.' === $dir ? '' : $dir,
                function () use ($absolute): void {
                    ($this->openFile)($absolute, true);
                },
            );
        }
        $this->hitsByCategory[SearchCategory::Files->order()] = $hits;
    }

    private function render(): void
    {
        $rows = [];
        foreach (SearchCategory::cases() as $category) {
            $hits = \array_slice($this->hitsByCategory[$category->order()] ?? [], 0, $category->cap());
            if ([] === $hits) {
                continue;
            }
            $rows[] = SearchRow::header($category);
            foreach ($hits as $hit) {
                $rows[] = SearchRow::hit($hit);
            }
        }
        $this->overlay->setSearchRows($rows);
        $this->tui->requestRender();
    }
}
