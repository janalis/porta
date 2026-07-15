<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Whether a find-bar option (regex / whole-word) is enabled, so its tag renders
 * lit or faint. Replaces the former `bool $on` flag on
 * {@see FindBarWidget}'s option tag.
 */
enum ToggleState
{
    case On;
    case Off;
}
