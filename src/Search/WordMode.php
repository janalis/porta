<?php

declare(strict_types=1);

namespace Porta\Search;

/** Whether a match may fall anywhere or must sit on whole-word boundaries. */
enum WordMode
{
    case Anywhere;
    case WholeWord;
}
