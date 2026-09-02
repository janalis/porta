<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Editor\Bookmarks;

/**
 * Hydrates and saves bookmarks at the session boundaries, the way
 * {@see \Porta\Debug\DebugStatePersistence} does for breakpoints: restored
 * before the first buffer opens, so the boot-time buffer-switch pass paints
 * them into the gutter for free.
 */
#[AsIdeService]
final readonly class BookmarkPersistence
{
    public function __construct(
        private BookmarkStore $store,
        private Bookmarks $bookmarks,
    ) {}

    public function restore(): void
    {
        $this->bookmarks->replaceAll($this->store->load());
    }

    public function save(): void
    {
        $this->store->save($this->bookmarks->all());
    }
}
