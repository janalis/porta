<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\History\RecentFilesStore;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Ui\Glyphs;
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

    /**
     * What a single-category picker passes as its `$categoryCap`: the same step
     * "more results" reveals, so the popup's window fills and the arrows scroll
     * it. Past that the growth row appears exactly as before — this widens the
     * first page, it does not unbound the list.
     */
    public const int SOLO_CAP = self::MORE_STEP;

    /** @var array<int, list<SearchHit>> hits keyed by category order */
    private array $hitsByCategory = [];

    /** @var array<int, int> how many rows a category currently shows, when it has been grown */
    private array $shown = [];

    /**
     * @var array<int, SearchCategory> the async categories asked and not yet
     *                                 answered for this query, by order — each
     *                                 shows "Searching…" rather than nothing, so
     *                                 a slow server reads as slow, not as empty
     */
    private array $pending = [];

    /** @var array<int, string> why a category answered nothing, by order — its note until the next query */
    private array $notes = [];

    /**
     * @param list<SearchSource> $syncSources  files, actions (run immediately)
     * @param list<SearchSource> $asyncSources classes, symbols, text (debounced)
     * @param ?int               $categoryCap  rows one category shows before a
     *                                         "more results" row takes over;
     *                                         null shares the list by
     *                                         {@see SearchCategory::cap()}. A
     *                                         single-category picker passes
     *                                         {@see SOLO_CAP} — see
     *                                         {@see capFor()} for why the
     *                                         shared rule does not fit it
     */
    public function __construct(
        private readonly SearchOverlay $overlay,
        private readonly array $syncSources,
        private readonly array $asyncSources,
        private readonly BufferManager $buffers,
        private readonly Tui $tui,
        private readonly ProjectPaths $paths,
        private readonly RecentFilesStore $recentFiles,
        private readonly FileOpener $files,
        private readonly Scheduler $scheduler,
        private readonly Translator $translator,
        private readonly string $title = 'Search Everywhere',
        private readonly bool $seedRecentFiles = true,
        private readonly ?int $categoryCap = null,
    ) {}

    public function open(): void
    {
        ++$this->generation;
        $this->hitsByCategory = [];
        $this->shown = [];
        $this->pending = [];
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
        $this->pending = [];
        $this->notes = [];

        if ('' === $text) {
            $this->seedEmpty();
            $this->render();

            return;
        }

        foreach ($this->syncSources as $source) {
            $source->query($text, $this->guard($generation, $source->category()));
        }
        foreach ($this->asyncSources as $source) {
            $category = $source->category();
            $this->pending[$category->order()] = $category;
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
        return function (array $hits, ?string $note = null) use ($generation, $category): void {
            if ($generation !== $this->generation) {
                return; // a newer query (or close) superseded this response
            }
            /** @var list<SearchHit> $hits */
            $this->hitsByCategory[$category->order()] = $hits;
            unset($this->pending[$category->order()]);
            if (null !== $note) {
                $this->notes[$category->order()] = $note;
            }
            $this->render();
        };
    }

    private function onPick(SearchHit $hit): void
    {
        if (!$hit->enabled) {
            return; // greyed like its menu item: there to be read, not run
        }
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
        $paths = \array_map(static fn (Buffer $b): string => $b->path, $this->buffers->recent());
        // Then the ones that are recent but no longer open — the same list the
        // Ctrl+E switcher shows, so the two trays cannot disagree about what
        // "recent" means. Gone from disk means gone from the tray.
        $openPaths = \array_fill_keys($paths, value: true);
        foreach ($this->recentFiles->paths as $path) {
            if (($openPaths[$path] ?? null) !== null || !\is_file($path)) {
                continue;
            }

            $paths[] = $path;
        }

        $hits = [];
        foreach ($paths as $absolute) {
            $relative = $this->paths->relative($absolute);
            $dir = \dirname($relative);
            $hits[] = new SearchHit(
                SearchCategory::Files,
                \basename($relative),
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
            $limit = $this->shown[$category->order()] ?? $this->capFor($category);
            $hits = \array_slice($all, offset: 0, length: $limit);
            $note = \array_key_exists($category->order(), $this->pending) ? Glyphs::WORKING.' '.$this->translator->trans('Searching…') : $this->notes[$category->order()] ?? null;
            if ([] === $hits && null !== $note) {
                $rows[] = SearchRow::header($this->translator->trans($category->title()));
                $rows[] = SearchRow::note($note);

                continue;
            }
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
     * A category's row budget: this picker's own, or the shared one.
     *
     * {@see SearchCategory::cap()} is a SHARING rule — six categories on one
     * list, none of which may crowd the others out of view. A single-category
     * picker shares with nobody, and paying the rule anyway cost rows the popup
     * already had: Ctrl+P over forty matching files showed five of them and a
     * "35 more results" row inside a box that seats twelve.
     */
    private function capFor(SearchCategory $category): int
    {
        return $this->categoryCap ?? $category->cap();
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
