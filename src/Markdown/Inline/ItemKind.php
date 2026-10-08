<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/** What an {@see InlineItem} becomes once the inline parser is done with it. */
enum ItemKind
{
    case Text;
    case SoftBreak;
    case HardBreak;
    case Code;
    case Html;
    case Emphasis;
    case Strong;
    case Strikethrough;
    case Link;
    case Autolink;
    case Image;
    case FootnoteReference;
    case Emoji;
}
