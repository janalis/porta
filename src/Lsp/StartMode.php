<?php

declare(strict_types=1);

namespace Porta\Lsp;

/** Whether routing to a session may launch its language server or only reuse a running one. */
enum StartMode
{
    case Reuse;
    case Start;
}
