<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginManifest;

/**
 * Which tools each plugin runs, and how each one stands — for every plugin
 * installed, enabled or not.
 *
 * Two sources, one answer: what a plugin's MANIFEST declares
 * (`extra.porta.tools`), read from the copy that loads, and what an enabled
 * third-party plugin registered in code ({@see PluginManager::registeredTools()}).
 * The Settings page, the Welcome cards, the palette's tool commands and the
 * update check all ask here, so a tool cannot be "missing" in one of them and
 * "installed" in another.
 */
final class ToolCatalog
{
    /** @var array<string, array{0: int, 1: PluginManifest}> plugin dir => [composer.json mtime, its manifest] */
    private array $manifests = [];

    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginManager $plugins,
        private readonly ToolLocator $locator,
        private readonly ToolVersions $versions,
    ) {}

    /**
     * $plugin's manifest, from the copy that loads, or null when it is not
     * installed.
     *
     * Read on demand and kept per directory while its composer.json is
     * unchanged: this is pulled per settings render, and a manifest is read
     * from disk.
     */
    public function manifest(string $plugin): ?PluginManifest
    {
        // The copy that loads: an untrusted project's own is never run, so
        // nothing it declares is probed, installed or updated either.
        $trusted = $this->locator->trusted();
        $copy = \array_find($this->installer->installed(), static fn (array $installed): bool => $plugin === $installed['name'] && ($trusted || InstallScope::Project !== $installed['scope']));
        if (null === $copy) {
            return null;
        }
        $dir = $copy['dir'];
        $file = $dir.'/composer.json';
        $stamp = \is_file($file) ? (int) \filemtime($file) : 0;
        if ($stamp !== ($this->manifests[$dir][0] ?? null)) {
            $this->manifests[$dir] = [$stamp, PluginManifest::beside($dir.'/porta.php')];
        }

        return $this->manifests[$dir][1];
    }

    /**
     * The tools $plugin's manifest declares.
     *
     * @return list<ToolRequirement>
     */
    public function declared(string $plugin): array
    {
        return $this->manifest($plugin)->tools ?? [];
    }

    /**
     * Every tool of $plugin as it stands now, manifest-declared first.
     *
     * Cheap enough for a render: a few stat calls per tool, and the version
     * from {@see ToolVersions}' cache, which never spawns.
     *
     * @return list<ToolStatus>
     */
    public function statuses(string $plugin): array
    {
        return [...\array_map($this->status(...), $this->declared($plugin)), ...\array_map(self::registered(...), $this->plugins->registeredInstallers($plugin))];
    }

    /**
     * A tool registered in code, as it stands. It reports only whether it is
     * there, never where, so the one source that can update it is the
     * plugin's own installer: a plugin that downloads its tool is also what
     * replaces it.
     *
     * @param array{plugin: string, tool: string, available: \Closure(): bool, via: list<array{0: string, 1: string}>, describe: (\Closure(): string)|null} $installer
     */
    private static function registered(array $installer): ToolStatus
    {
        $sources = [];
        foreach ($installer['via'] as [$manager, $package]) {
            // registerInstaller() refused every manager this does not know.
            $sources[] = new ToolSource(ToolManager::from($manager), $package);
        }
        $tool = new ToolRequirement($installer['plugin'], $installer['tool'], $installer['tool'], sources: $sources);
        $available = $installer['available']();
        $describe = $installer['describe'];

        return new ToolStatus(
            $tool,
            $available,
            // Not asked while the tool is absent: a describer written for
            // "what have I got?" has nothing to answer, and this is pulled on
            // every settings render.
            version: $available && null !== $describe ? $describe() : '',
            manager: $available ? $tool->source(ToolManager::Plugin) : null,
        );
    }

    /** $tool as it stands now. */
    public function status(ToolRequirement $tool): ToolStatus
    {
        $path = $this->locator->locate($tool);

        return null === $path
            ? new ToolStatus($tool, available: false)
            : new ToolStatus($tool, true, $path, $this->versions->known($path), $this->locator->sourceOf($tool, $path));
    }

    /** Whether some installed tool of $plugin has not been asked its version yet. */
    public function unprobed(string $plugin): bool
    {
        return [] !== $this->pending([$plugin]);
    }

    /**
     * Ask every installed tool of $plugins its version, then call $then once.
     * Off any render path: a process per binary the first time, nothing after.
     *
     * @param list<string>            $plugins
     * @param (\Closure(): void)|null $then
     */
    public function probe(array $plugins, ?\Closure $then = null): void
    {
        $pending = $this->pending($plugins);
        $left = \count($pending);
        if (0 === $left) {
            $then?->__invoke();

            return;
        }
        foreach ($pending as [$path, $args]) {
            $this->versions->probe($path, $args, static function () use (&$left, $then): void {
                if (0 === --$left) {
                    $then?->__invoke();
                }
            });
        }
    }

    /**
     * The installed tools of $plugins whose version is not known yet.
     *
     * @param list<string> $plugins
     *
     * @return list<array{0: string, 1: list<string>}> [path, version args]
     */
    private function pending(array $plugins): array
    {
        $pending = [];
        foreach ($plugins as $plugin) {
            foreach ($this->declared($plugin) as $tool) {
                $path = $this->locator->locate($tool);
                if (null !== $path && !$this->versions->probed($path)) {
                    $pending[] = [$path, $tool->versionArgs];
                }
            }
        }

        return $pending;
    }
}
