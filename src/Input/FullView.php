<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Whether a full-area view (git or settings) currently owns the main area, so
 * the editor's invisible popups can't claim clicks. Replaces the former
 * `bool $fullViewOpen` flag on {@see MouseRouter}'s modal-popup click routing.
 */
enum FullView
{
    case Open;
    case Closed;
}
