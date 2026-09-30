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

    /** @var array<string, true> core claims a setting has turned off, by pane id */
    private array $disabled = [];

    public function __construct(
        private readonly ViewerProviderSource $plugins,
    ) {}

    /** @param \Closure(string): bool $claims */
    public function claim(string $paneId, \Closure $claims): void
    {
        $this->core[$paneId] = $claims;
    }

    /**
     * Turn one of core's claims off or back on — the setting that lets a user
     * edit their markdown without the preview. It routes files opened from now
     * on; a tab already open keeps what it has.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the setting's value, passed straight through from its row.
    public function enable(string $paneId, bool $enabled): void
    {
        if ($enabled) {
            unset($this->disabled[$paneId]);

            return;
        }
        $this->disabled[$paneId] = true;
    }

    public function previewFor(string $path): ?string
    {
        $plugin = $this->plugins->previewFor($path);
        if (null !== $plugin) {
            return $plugin;
        }
        foreach ($this->core as $paneId => $claims) {
            if (!\array_key_exists($paneId, $this->disabled) && $claims($path)) {
                return $paneId;
            }
        }

        return null;
    }
}
