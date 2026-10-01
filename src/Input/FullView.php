<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Whether a view window (Settings, Git, Review, the docs) is open over the
 * editor, so the editor's popups beneath it can't claim clicks. Replaces the
 * former `bool $fullViewOpen` flag on {@see MouseRouter}'s modal-popup click
 * routing.
 */
enum FullView
{
    case Open;
    case Closed;
}
