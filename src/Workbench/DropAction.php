<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * What a drop onto a directory does with what was dropped: the file-tree drag
 * moves unless Alt is held, a paste follows the cut or copy that filled the
 * clipboard, and a drop from outside the IDE always copies.
 */
enum DropAction
{
    case Move;

    /** Nothing moves: no `FileRenamed`, no buffer re-pointed — only the tree has anything to say. */
    case Copy;
}
