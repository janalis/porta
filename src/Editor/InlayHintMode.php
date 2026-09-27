<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Where the editor shows the server's inlay hints (the "Interface > Inlay
 * hints" setting): nowhere — and not asked for — spliced into the line as dim
 * virtual text, or on a hover card over the word they annotate.
 */
enum InlayHintMode: string
{
    case None = 'none';
    case Inline = 'inline';
    case Tooltip = 'tooltip';
}
