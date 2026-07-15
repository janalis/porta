<?php

declare(strict_types=1);

namespace Porta\Search;

/** Whether a query is a literal string or a PCRE pattern. */
enum MatchMode
{
    case Literal;
    case Regex;
}
