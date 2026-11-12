<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

/** Manual upgrades may change requirements; background upgrades must preserve them. */
enum PluginUpdateMode
{
    case Manual;
    case Automatic;
}
