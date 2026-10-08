<?php

declare(strict_types=1);

namespace Porta\Markdown\Node;

/** The five GitHub alert kinds a block quote can open with (`> [!NOTE]`). */
enum AlertKind: string
{
    case Note = 'NOTE';
    case Tip = 'TIP';
    case Important = 'IMPORTANT';
    case Warning = 'WARNING';
    case Caution = 'CAUTION';
}
