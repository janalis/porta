<?php

declare(strict_types=1);

namespace Porta\Plugin\Store;

/**
 * One package's entry in {@see Curation}: being there at all makes it
 * verified; the rest is optional.
 */
final readonly class Curated
{
    /**
     * @param StoreCategory|null $category overrides the one the package names, when set
     * @param string|null        $replaces the bundled plugin this package takes over from
     */
    public function __construct(
        public ?StoreCategory $category = null,
        public bool $featured = false,
        public ?string $replaces = null,
    ) {}
}
