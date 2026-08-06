<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

/**
 * A top-level menu contributed by something core does not know about, plus the
 * commands that stay live while its view owns the main area.
 *
 * Both halves have to travel together, and that is the point. Core's menu model
 * carried a `DEFS['Git']` group, a `VIEW_SAFE['Git']` list and — in the menu
 * rebuilder — a `'Git' !== $title || $git->isRepository()` gate that existed for
 * exactly one group. A contributor that supplied its menu but not its view-safe
 * list would grey out its own items the moment its view opened, which is a bug
 * with no error.
 */
final readonly class MenuContribution
{
    /**
     * @param string                $title    untranslated; the model translates it
     * @param string                $after    the core menu title this one follows
     * @param list<string>          $items    `'i:<command-id>'` entries and {@see MenuModel::SEP}
     * @param (\Closure(): bool)|null $visible hides the whole group; null means always
     * @param string                $viewId   the view whose open state $viewSafe applies to, '' for none
     * @param list<string>          $viewSafe command ids that stay enabled while that view is open
     */
    public function __construct(
        public string $title,
        public string $after,
        public array $items,
        public ?\Closure $visible = null,
        public string $viewId = '',
        public array $viewSafe = [],
    ) {}
}
