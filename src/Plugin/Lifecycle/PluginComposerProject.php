<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The Composer project Porta drives to install/remove plugin packages: one
 * per scope base, at `<scopeBase>/.porta/composer.json`. Pure JSON/path
 * bookkeeping — no subprocess, no single-flight concern — which is why it is
 * not part of {@see PluginInstaller}, the class that owns every subprocess
 * this feature runs.
 *
 * Packages land at Composer's own default location,
 * `<scopeBase>/.porta/vendor/<vendor>/<project>`; {@see PluginInstaller}
 * symlinks the plugin's `.porta/plugins/<name>` entry to that path afterward.
 * There is deliberately no custom install-path plugin in play (see the class
 * doc on `PluginInstaller` for why) — this file is the entire mechanism.
 */
final class PluginComposerProject
{
    public function __construct(
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Create `<scopeBase>/.porta/composer.json` if it does not exist yet, and
     * return the directory composer should run in.
     *
     * Nothing this project requires is a Composer plugin, so there is no
     * `allow-plugins` prompt to guard against.
     */
    public function ensureScaffold(string $scopeBase): string
    {
        $dir = $scopeBase.'/.porta';
        $path = $dir.'/composer.json';
        if (!is_file($path)) {
            JsonFile::write(
                $this->fs,
                $path,
                [
                    'name' => 'porta/local-plugins',
                    'description' => "Plugins installed via Porta's Settings \u{2192} Plugins UI. Managed by Porta; composer.lock records exact installed versions.",
                    'type' => 'project',
                    'license' => 'proprietary',
                    'repositories' => new \stdClass(),
                    'require' => new \stdClass(),
                    'minimum-stability' => 'stable',
                    'prefer-stable' => true,
                    'config' => [
                        'sort-packages' => true,
                        'platform' => ['php' => \PHP_VERSION],
                    ],
                ],
                trailingNewline: true,
            );
        }

        return $dir;
    }

    /** Where composer installs a package by default: `<scopeBase>/.porta/vendor/<packageName>`. */
    public function packageDir(string $scopeBase, string $packageName): string
    {
        return $scopeBase.'/.porta/vendor/'.$packageName;
    }

    /**
     * Point $packageName at a local directory (symlinked by composer itself,
     * so an installed-by-path plugin under active development is picked up
     * live). Keyed by package name, so reinstalling the same plugin later
     * overwrites this entry rather than accumulating one per attempt.
     */
    public function addPathRepository(string $scopeBase, string $packageName, string $absolutePath): void
    {
        $this->addRepository($scopeBase, $packageName, ['type' => 'path', 'url' => $absolutePath, 'options' => ['symlink' => true]]);
    }

    /** Point $packageName at a VCS remote (a git URL composer cannot otherwise resolve a name from). */
    public function addVcsRepository(string $scopeBase, string $packageName, string $url): void
    {
        $this->addRepository($scopeBase, $packageName, ['type' => 'vcs', 'url' => $url]);
    }

    /** @param array{type: string, url: string, options?: array{symlink: bool}} $repository */
    private function addRepository(string $scopeBase, string $packageName, array $repository): void
    {
        $path = $scopeBase.'/.porta/composer.json';
        $composerJson = JsonFile::read($path) ?? [];
        /** @var array<string, mixed> $repositories */
        $repositories = \is_array($composerJson['repositories'] ?? null) ? $composerJson['repositories'] : [];
        $repositories[$packageName] = $repository;
        $composerJson['repositories'] = $repositories;
        JsonFile::write($this->fs, $path, $composerJson, trailingNewline: true);
    }
}
