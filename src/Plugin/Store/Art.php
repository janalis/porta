<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * What the store shows of a plugin beyond its words: its icon and the first
 * of its pictures as PNGs, and the addresses of every picture its page
 * shows — each found by {@see StoreArt}.
 */
final readonly class Art
{
    /**
     * @param string|null  $icon  a square PNG, or null: the tile keeps its initials
     * @param string|null  $hero  the first picture of $shots as a PNG, or null
     * @param list<string> $shots the pictures the page shows, as addresses
     */
    public function __construct(
        public ?string $icon = null,
        public ?string $hero = null,
        public array $shots = [],
    ) {}
}
