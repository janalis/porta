<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * What an inlay hint says, as the LSP numbers it: an inferred type (`: int`
 * after a variable) or the name of the parameter an argument is passed to
 * (`name:` before it). A hint a server sends without a kind — phpantom's
 * reference counts — has none, and is governed by the inlay mode alone.
 */
enum InlayHintKind: int
{
    case Type = 1;
    case Parameter = 2;
}
