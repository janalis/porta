<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;

/**
 * Which pane previews a file beside the editor: a plugin's claim first
 * ({@see ViewerProviderSource::previewFor()}), then core's own.
 *
 * Plugins first for the reason a plugin's pane replaces a core pane of the
 * same id: a plugin that renders markdown better than core's renderer is
 * allowed to take it over, and core's claim is what is left when nothing did.
 * Core registers its claims here rather than through the plugin API for the
 * reason {@see EditorPanes} gives — core never goes through `Porta\Plugin`.
 */
#[AsIdeService]
final class PreviewRoutes
{
    /** @var array<string, \Closure(string): bool> core's claims, by the pane id they route to */
    private array $core = [];

    public function __construct(
        private readonly ViewerProviderSource $plugins,
    ) {}

    /** @param \Closure(string): bool $claims */
    public function claim(string $paneId, \Closure $claims): void
    {
        $this->core[$paneId] = $claims;
    }

    public function previewFor(string $path): ?string
    {
        return $this->plugins->previewFor($path) ?? \array_find_key($this->core, static fn (\Closure $claims): bool => true === $claims($path));
    }
}
