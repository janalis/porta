<?php

declare(strict_types=1);

namespace Porta\Search\Everywhere;

/**
 * One result in the Search Everywhere modal: a category, a primary `label`
 * (basename / command / symbol name / matched text), a dimmed `detail`
 * (directory or `file:line`), and an `activate` closure that performs the
 * result's action (open the file, run the command, jump to the location). The
 * heterogeneous sources all normalise into this so the modal renders and
 * activates uniformly.
 */
final readonly class SearchHit
{
    /**
     * @param \Closure(): void $activate
     * @param bool             $keepsOpen a row that changes the LIST rather than leaving it
     *                                    (the "more results" row): activating it must not
     *                                    close the modal it is asking to grow
     * @param bool             $enabled   false for a command whose context is absent: the row is
     *                                    drawn dim and struck through, as its menu item is greyed,
     *                                    and picking it does nothing
     */
    public function __construct(
        public SearchCategory $category,
        public string $label,
        public string $detail,
        public \Closure $activate,
        public bool $keepsOpen = false,
        public bool $enabled = true,
    ) {}
}
