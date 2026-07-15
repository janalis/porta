<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Whether {@see ResultsView::build()} emits a flat match list or groups matches
 * under collapsible headers. Replaces the former `bool $grouped` flag.
 */
enum ResultLayout
{
    case Grouped;
    case Flat;
}
