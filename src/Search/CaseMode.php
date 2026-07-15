<?php

declare(strict_types=1);

namespace Porta\Search;

/** Whether matching honours letter case. */
enum CaseMode
{
    case Sensitive;
    case Insensitive;
}
