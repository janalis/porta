<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * Where a drop from outside the IDE landed, which is what decides what it
 * does — see {@see ExternalDrop}.
 */
enum DropZone
{
    /** The project tree: copy into the folder under the drop. */
    case Tree;

    /** An open file's text: ask whether to insert the path or open it. */
    case Editor;

    /** The tab strip, or an editor showing no file: open what was dropped. */
    case Empty;

    /** A field that takes typing — a picker, a prompt, a search box: the paths, as text. */
    case Text;
}
