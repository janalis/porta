<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The document position an editor cell shows: its line, byte column and
 * display column, the code lens when the cell is a lens row, and the inlay
 * hint when the cell is one — a hint answers the character it sits before.
 */
final readonly class EditorPoint
{
    public function __construct(
        public int $line,
        public int $col,
        public int $displayCol,
        /** The lens's index on its line when the cell is a lens row. */
        public ?int $lens = null,
        public ?InlayHint $hint = null,
    ) {}
}
