<?php

declare(strict_types=1);

namespace Porta\Layout;

/**
 * What a {@see PaneSplit} shows while its area is too narrow for two sides.
 */
enum NarrowSplit
{
    /** The lead alone: the editor without its preview. */
    case LeadAlone;

    /** The rest alone: the editor without the pane flanking it on its lead side. */
    case RestAlone;

    /** The lead over the rest, the divider gone: a view's columns become rows. */
    case Stacked;
}
