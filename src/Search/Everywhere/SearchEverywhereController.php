<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
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

    /**
     * How many more rows one "more results" activation reveals. Growing rather
     * than unbounding, because a grep or a workspace-symbol answer is not
     * bounded by its source and rendering all of it would be the same mistake
     * from the other side.
     */
    private const int MORE_STEP = 20;

    /** @var array<int, list<SearchHit>> hits keyed by category order */
    private array $hitsByCategory = [];

    /** @var array<int, int> how many rows a category currently shows, when it has been grown */
    private array $shown = [];

    /**
     * @param list<SearchSource>                $syncSources  files, actions (run immediately)
     * @param list<SearchSource>                $asyncSources classes, symbols, text (debounced)
     */
    public function __construct(
        private readonly SearchOverlay $overlay,
        private readonly array $syncSources,
        private readonly array $asyncSources,
        private readonly BufferManager $buffers,
        private readonly Tui $tui,
        private readonly ProjectPaths $paths,
        private readonly FileOpener $files,
        private readonly Scheduler $scheduler,
        private readonly Translator $translator,
        private readonly string $title = 'Search Everywhere',
        private readonly bool $seedRecentFiles = true,
    ) {}

    public function open(): void
    {
        ++$this->generation;
        $this->hitsByCategory = [];
        $this->shown = [];
        $this->overlay->openSearch(
            $this->translator->trans($this->title),
            $this->onQuery(...),
            $this->onPick(...),
            $this->onClose(...),
            $this->translator->trans('no results'),
        );
        $this->seedEmpty();
        $this->render();
    }

    private function onQuery(string $text): void
    {
        $generation = ++$this->generation;
        $this->hitsByCategory = [];
        // A new query is a new list; carrying an expansion over would show one
        // category swollen for a search that never asked for it.
        $this->shown = [];

        if ('' === $text) {
            $this->seedEmpty();
            $this->render();

            return;
        }

        foreach ($this->syncSources as $source) {
            $source->query($text, $this->guard($generation, $source->category()));
        }

        $this->cancelDebounce();
        $this->debounceWatcher = $this->scheduler->delay(0.25, function () use ($generation, $text): void {
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
        if ($hit->keepsOpen) {
            ($hit->activate)();

            return;
        }

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
            $this->scheduler->cancel($this->debounceWatcher);
            $this->debounceWatcher = null;
        }
    }

    /**
     * The empty-query state. File-bearing pickers (Search Everywhere, go-to-file)
     * show the recent-files tray; the scoped pickers have none, so their sync
     * sources answer the empty query themselves — the actions source lists every
     * command, a source-less symbol picker just shows its empty message.
     */
    private function seedEmpty(): void
    {
        if ($this->seedRecentFiles) {
            $this->seedRecentFiles();

            return;
        }
        foreach ($this->syncSources as $source) {
            $source->query('', $this->guard($this->generation, $source->category()));
        }
    }

    private function seedRecentFiles(): void
    {
        $hits = [];
        foreach ($this->buffers->recent() as $buffer) {
            $absolute = $buffer->path;
            $relative = $this->paths->relative($absolute);
            $dir = \dirname($relative);
            $hits[] = new SearchHit(
                SearchCategory::Files,
                basename($relative),
                '.' === $dir ? '' : $dir,
                function () use ($absolute): void {
                    $this->files->openFile($absolute, OpenFocus::Editor);
                },
            );
        }
        $this->hitsByCategory[SearchCategory::Files->order()] = $hits;
    }

    private function render(): void
    {
        $rows = [];
        foreach (SearchCategory::cases() as $category) {
            $all = $this->hitsByCategory[$category->order()] ?? [];
            $limit = $this->shown[$category->order()] ?? $category->cap();
            $hits = \array_slice($all, offset: 0, length: $limit);
            if ([] === $hits) {
                continue;
            }
            $rows[] = SearchRow::header($this->translator->trans($category->title()));
            foreach ($hits as $hit) {
                $rows[] = SearchRow::hit($hit);
            }
            $hidden = \count($all) - \count($hits);
            if ($hidden > 0) {
                $rows[] = SearchRow::hit($this->moreHit($category, $hidden, $limit));
            }
        }
        $this->overlay->setSearchRows($rows);
        $this->tui->requestRender();
    }

    /**
     * The row that grows a category, rather than a cap you cannot see past.
     *
     * A hit rather than a row kind of its own, so selection and Enter reach it
     * with nothing added to the overlay — it simply keeps the modal open, since
     * it is asking the list to change rather than to be left.
     */
    private function moreHit(SearchCategory $category, int $hidden, int $limit): SearchHit
    {
        return new SearchHit(
            $category,
            $this->translator->trans('%count% more result|%count% more results', ['%count%' => $hidden]),
            '',
            function () use ($category, $limit): void {
                $this->shown[$category->order()] = $limit + self::MORE_STEP;
                $this->render();
            },
            keepsOpen: true,
        );
    }
}
