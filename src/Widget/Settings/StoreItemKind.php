<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

/** What a {@see StoreItem} is, which decides what Enter and Space do on it. */
enum StoreItemKind
{
    /** A plugin: Enter opens its page, Space presses its button. */
    case Tile;
    /** A shelf's "See all ›": the category's whole shelf. */
    case SeeAll;
    /** A category on the Categories tab: its whole shelf. */
    case Category;
    /** The Updates tab's Update all button. */
    case UpdateAll;
    /** The Installed tab's install field: a git URL, a path or a package. */
    case Install;
    /** The Installed tab's scope select: where an install lands. */
    case Scope;
}
