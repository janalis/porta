<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A file was activated in the project tree — Enter on the selected node, or a
 * click on one.
 *
 * "Activated", not "opened": what the tree knows is that the user chose this
 * path. Whether that opens a buffer, and in which pane, is the answer of
 * whoever listens.
 */
final readonly class TreeFileActivated
{
    public function __construct(
        public string $path,
    ) {}
}
