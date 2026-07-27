<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * The three picker surfaces {@see OverlayHost} owns. Only naming and
 * precedence are shared — the surfaces' verbs deliberately are not (their
 * open signatures, input paths and click semantics all differ), so this
 * stays a tag, not an interface.
 */
enum PickerSurface
{
    case ListOverlay;
    case Search;
    case Prompt;
}
