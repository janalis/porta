<?php

declare(strict_types=1);

namespace Porta\Help;

/**
 * The inline style of a markdown segment — each kind opens and closes its own
 * SGR region in {@see MarkdownRenderer}.
 */
enum MarkdownSpanKind
{
    case Plain;
    case Bold;
    case Italic;
    case Code;
    case Link;
}
