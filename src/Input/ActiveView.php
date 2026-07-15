<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * Which full-area view owns hover routing: the settings view, the git view, or
 * the normal editor layout. Replaces the former `bool $gitViewOpen,
 * bool $settingsViewOpen` flag pair on {@see MouseRouter}'s hover routing.
 */
enum ActiveView
{
    case Editor;
    case Git;
    case Settings;
}
