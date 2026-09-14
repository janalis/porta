<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * One of the flags a project search toggles from the find-in-files modal.
 *
 * Backed by the short key the modal already uses for its option tags and click
 * zones — those zones share one name space with non-option zones (`scope`,
 * `find_window`), so a zone name becomes an option only through
 * {@see self::tryFrom()}.
 */
enum SearchOption: string
{
    case Regex = 'regex';
    case CaseSensitive = 'case';
    case WholeWord = 'word';
    case PreserveCase = 'preserve';
    case Tokens = 'tokens';
}
