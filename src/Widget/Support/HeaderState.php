<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * Whether a results-panel group header ({@see DisplayRow}) is expanded (its
 * matches shown) or collapsed. Replaces the former `bool $collapsed` flag on
 * the header factories.
 */
enum HeaderState
{
    case Expanded;
    case Collapsed;
}
