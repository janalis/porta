<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsIdeService;
use Porta\Event\SettingsSectionRequested;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchSource;
use Porta\Workbench\View\ViewRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Settings as Search Everywhere results: one hit per setting, activating it
 * opens its page and lands on its row.
 *
 * Lives in `Settings` rather than beside the other five sources because
 * `Settings` already imports `Search` and the reverse edge does not exist.
 * Putting it there would make the two namespaces mutually dependent to spare
 * one constructor line — so the aggregating factory takes it by container id,
 * typed as {@see SearchSource}, and the composition root is the only thing that
 * names the class.
 *
 * The page is the hit's dimmed detail, which is not decoration: `Indent style`
 * is the label of thirteen different rows, and the page is the only thing that
 * tells them apart.
 */
#[AsIdeService]
final readonly class SettingsSearchSource implements SearchSource
{
    /**
     * More than the overlay shows for one category, so its own cap is what
     * truncates rather than this — a limit here would silently discard hits
     * the ranking had already decided were better.
     */
    private const int LIMIT = 50;

    public function __construct(
        private SettingsSearchIndex $index,
        private ViewRegistry $views,
        private EventDispatcherInterface $events,
    ) {}

    #[\Override]
    public function category(): SearchCategory
    {
        return SearchCategory::Settings;
    }

    #[\Override]
    public function query(string $query, \Closure $emit): void
    {
        $hits = [];
        foreach ($this->index->search($query, self::LIMIT) as $entry) {
            $hits[] = new SearchHit(
                SearchCategory::Settings,
                $entry->label,
                $entry->page,
                fn () => $this->reveal($entry->section, $entry->key),
            );
        }

        $emit($hits);
    }

    /** Nothing is in flight: the index is in memory and scored synchronously. */
    #[\Override]
    public function cancel(): void {}

    /**
     * Show the picked setting.
     *
     * The view is opened BEFORE the event goes out, and the order is not
     * cosmetic: `SettingsViewController::open()` resets to the General page, so
     * opening afterwards would throw away the very section the event carried.
     * Opening an already-open view is a no-op, which is what makes this safe
     * when the picker was reached from inside the view.
     */
    private function reveal(string $section, string $key): void
    {
        $this->views->open('settings');
        $this->events->dispatch(new SettingsSectionRequested($section, $key));
    }
}
