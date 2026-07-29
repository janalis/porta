<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Plugin\Runtime\PluginPagesSync;
use Porta\Widget\Settings\SettingsNavWidget;

/**
 * The settings side of {@see PluginPagesSync}: the installed plugin set
 * becomes one nav sub-item per plugin. It is the only class that knows a
 * "plugin page" is a sidebar row, which is what keeps the plugin lifecycle
 * out of `Widget/`.
 */
final class SettingsPluginPages implements PluginPagesSync
{
    public function __construct(
        private readonly SettingsNavWidget $nav,
    ) {}

    public function refresh(array $names): void
    {
        $this->nav->setPluginEntries($names);
    }
}
