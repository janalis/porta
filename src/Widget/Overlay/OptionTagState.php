<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

/**
 * Visual state of a find-in-files option tag, by priority: lit when the option
 * is on, otherwise highlighted while hovered, otherwise faint. Replaces the
 * former `bool $on, bool $hovered` flag pair on {@see FindInFilesModal}.
 */
enum OptionTagState
{
    case On;
    case Hovered;
    case Off;
}
