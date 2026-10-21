<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * One place a tool can be installed from: a manager and what to ask it for.
 *
 * $package is the composer package, brew formula or cask, crate or npm
 * package; for {@see ToolManager::Github} it is the `owner/repo`, with the
 * asset rules in $asset; for {@see ToolManager::Plugin} it is the id of the
 * declaring plugin's own command.
 */
final readonly class ToolSource
{
    public function __construct(
        public ToolManager $manager,
        public string $package,
        public ?GithubAsset $asset = null,
    ) {}
}
