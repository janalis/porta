<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * A command: in the open file at $path, replace the bytes from $start to $end
 * of its text with $replacement — when they still read $expected, so an edit
 * asked for against a text that has since changed does nothing. Dispatched by
 * the markdown preview when a task's box is ticked; performed by
 * {@see \Porta\Editor\SourceEdits} as one undoable edit. The seam an editable
 * preview's every edit goes through.
 */
final readonly class SourceEditRequested
{
    public function __construct(
        public string $path,
        public int $start,
        public int $end,
        public string $expected,
        public string $replacement,
    ) {}
}
