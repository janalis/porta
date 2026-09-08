<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * What a click on a list row is asking for.
 *
 * The widget is told the INTENT, not which modifier keys were down: a tree
 * does not care that Ctrl exists, only that this click adds a row to the
 * selection rather than opening it. {@see \Porta\Input\MouseRouter} reads the
 * modifiers off the SGR sequence and decides which of these it is.
 */
enum ClickIntent
{
    /** Select this row alone and act on it — open the file, toggle the directory. */
    case Open;

    /** Add this row to the selection, or take it back out, without acting on it. */
    case Add;

    /** Select every row between the anchor and this one, without acting on it. */
    case Range;
}
