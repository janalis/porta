<?php

declare(strict_types=1);

namespace Porta\Lsp;

/**
 * Whether a "go to" request simply jumps to its target, or — when the caret
 * already sits on the symbol's declaration — falls back to listing its usages
 * (PhpStorm's "Go to Declaration or Usages").
 */
enum NavigationMode
{
    case Jump;
    case UsagesOnDeclaration;
}
