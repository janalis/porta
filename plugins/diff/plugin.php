<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * Diffs for the plugins that show them: the unified-diff model with its
 * word-level highlights, and the unified or side-by-side pane git and review
 * both draw a diff in.
 *
 * It registers nothing of its own yet — what it ships is classes, which a
 * plugin that `requires` it can autoload. Enabled by default
 * ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}), since git, which
 * is too, needs it.
 */
return new class('diff') extends AbstractPlugin {
    public function activate(PluginApi $api): void {}
};
