<?php

declare(strict_types=1);

namespace Porta\Overlay\Region;

/**
 * Which of a modal's two frame affordances is under the pointer. Mutually
 * exclusive by geometry — the × lives on the title bar, the resize corner on
 * the bottom border, and no modal is one row tall — so one nullable value
 * replaces two booleans that could never both be true.
 */
enum ModalHotspot
{
    case Close;
    case ResizeCorner;
}
