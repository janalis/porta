<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchSource;

/**
 * Settings as Search Everywhere results: one hit per setting, activating it
 * opens its page and lands on its row.
 *
 * Lives in `Settings` rather than beside the other five sources because
 * `Settings` already imports `Search` and the reverse edge does not exist.
 * Putting it there would make the two namespaces mutually dependent to spare
 * one caller from building an object — hence
 * {@see \Porta\Search\Everywhere\SearchEverywhereFactory::createScoped()},
 * which takes a source it did not assemble.
 *
 * The page is the hit's dimmed detail, which is not decoration: `Indent style`
 * is the label of thirteen different rows, and the page is the only thing that
 * tells them apart.
 */
final readonly class SettingsSearchSource implements SearchSource
{
    /**
     * More than the overlay shows for one category, so its own cap is what
     * truncates rather than this — a limit here would silently discard hits
     * the ranking had already decided were better.
     */
    private const int LIMIT = 50;

    /** @param \Closure(string, string): void $reveal (section id, row key) */
    public function __construct(
        private SettingsSearchIndex $index,
        private \Closure $reveal,
    ) {}

    #[\Override]
    public function category(): SearchCategory
    {
        return SearchCategory::Settings;
    }

    #[\Override]
    public function query(string $query, \Closure $emit): void
    {
        $reveal = $this->reveal;
        $hits = [];
        foreach ($this->index->search($query, self::LIMIT) as $entry) {
            $hits[] = new SearchHit(
                SearchCategory::Settings,
                $entry->label,
                $entry->page,
                static function () use ($reveal, $entry): void {
                    $reveal($entry->section, $entry->key);
                },
            );
        }

        $emit($hits);
    }

    /** Nothing is in flight: the index is in memory and scored synchronously. */
    #[\Override]
    public function cancel(): void {}
}
