<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which parameter-name hints a call shows (the "Parameter name hints"
 * setting): none, PhpStorm's default of only those before a literal — where
 * `f(true, 3)` says nothing on its own and `f($user)` already does — or all.
 */
enum InlayParameterHints: string
{
    case None = 'none';
    case Literals = 'literals';
    case All = 'all';
}
