<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Editor\Bookmarks;
use Porta\Support\ThreeWay;

/**
 * Hydrates and saves bookmarks at the session boundaries, the way
 * {@see \Porta\Debug\DebugStatePersistence} does for breakpoints: restored
 * before the first buffer opens, so the boot-time buffer-switch pass paints
 * them into the gutter for free.
 */
#[AsIdeService]
final class BookmarkPersistence
{
    /** @var array<string, list<int>> what this window restored: the base its changes are told from */
    private array $loaded = [];

    public function __construct(
        private readonly BookmarkStore $store,
        private readonly Bookmarks $bookmarks,
    ) {}

    public function restore(): void
    {
        $this->bookmarks->replaceAll($this->store->load());
        $this->loaded = $this->bookmarks->all();
    }

    /**
     * Save this window's changes over the file as it is now — another window
     * on the project may have written it since ({@see ThreeWay}). Written
     * whole, an idle second window deleted the bookmarks the first had added;
     * merged, what it changed is nothing, and the file stays as it is.
     */
    public function save(): void
    {
        $current = $this->bookmarks->all();
        $disk = $this->store->load();
        $merged = [];
        foreach (\array_keys($disk + $current + $this->loaded) as $path) {
            $lines = \array_keys(ThreeWay::merge(self::set($disk[$path] ?? []), self::set($this->loaded[$path] ?? []), self::set($current[$path] ?? [])));
            \sort($lines);
            if ([] !== $lines) {
                $merged[$path] = $lines;
            }
        }
        $this->store->save($merged);
    }

    /**
     * @param list<int> $lines
     *
     * @return array<int, true>
     */
    private static function set(array $lines): array
    {
        return \array_fill_keys($lines, value: true);
    }
}
