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
final readonly class PluginComposerProject
{
    public function __construct(
        private Filesystem $fs = new Filesystem(),
    ) {}

    /**
     * Create `<scopeBase>/.porta/composer.json` if it does not exist yet, and
     * return the directory composer should run in — or null if the file
     * could not be written (permissions, disk full).
     *
     * Nothing this project requires is a Composer plugin, so there is no
     * `allow-plugins` prompt to guard against.
     */
    public function ensureScaffold(string $scopeBase): ?string
    {
        $dir = $scopeBase.'/.porta';
        $path = $this->composerJsonPath($scopeBase);
        if (!\is_file($path)) {
            $written = JsonFile::writeEditable(
                $this->fs,
                $path,
                [
                    'name' => 'porta/local-plugins',
                    'description' => "Plugins installed via Porta's Settings \u{2192} Plugins UI. Managed by Porta; composer.lock records exact installed versions.",
                    'type' => 'project',
                    'license' => 'proprietary',
                    'repositories' => new \stdClass(),
                    'require' => new \stdClass(),
                    // Porta's own: a plugin may require what Porta runs on,
                    // and symfony/tui has no stable release of the branch it does.
                    'minimum-stability' => 'dev',
                    'prefer-stable' => true,
                    'config' => [
                        'sort-packages' => true,
                        'platform' => ['php' => \PHP_VERSION],
                    ],
                ],
            );
            if (!$written) {
                return null;
            }
        }

        return $dir;
    }

    /** Where ensureScaffold() writes and addRepository() rewrites $scopeBase's plugin composer.json. */
    public function composerJsonPath(string $scopeBase): string
    {
        return $scopeBase.'/.porta/composer.json';
    }

    /** Where composer installs a package by default: `<scopeBase>/.porta/vendor/<packageName>`. */
    public function packageDir(string $scopeBase, string $packageName): string
    {
        return $scopeBase.'/.porta/vendor/'.$packageName;
    }

    /**
     * The version composer installed of each package in $scopeBase's plugin
     * project, as it was tagged (`v1.2.0`), read from composer's own record
     * of what it installed — nothing to run, nothing to ask.
     *
     * @return array<string, string> package => version
     */
    public function installedVersions(string $scopeBase): array
    {
        $installed = JsonFile::read($scopeBase.'/.porta/vendor/composer/installed.json');
        $versions = [];
        /** @var mixed $package */
        foreach (\is_array($installed['packages'] ?? null) ? $installed['packages'] : [] as $package) {
            if (!\is_array($package) || !\is_string($package['name'] ?? null) || !\is_string($package['version'] ?? null)) {
                continue;
            }
            $versions[$package['name']] = $package['version'];
        }

        return $versions;
    }

    /** @return array<string, string> the scope's declared package constraints */
    public function requirements(string $scopeBase): array
    {
        $data = JsonFile::read($this->composerJsonPath($scopeBase));
        $requirements = [];
        /** @var mixed $constraint */
        foreach (\is_array($data['require'] ?? null) ? $data['require'] : [] as $package => $constraint) {
            if (!\is_string($package) || !\is_string($constraint)) {
                continue;
            }
            $requirements[$package] = $constraint;
        }

        return $requirements;
    }

    /**
     * Whether $packageName resolves from Packagist — no path or git
     * repository of its own in $scopeBase's project, which is the one kind of
     * install a newer RELEASE can update.
     */
    public function fromPackagist(string $scopeBase, string $packageName): bool
    {
        /** @var mixed $repositories */
        $repositories = JsonFile::read($this->composerJsonPath($scopeBase))['repositories'] ?? null;

        return !\is_array($repositories) || !\array_key_exists($packageName, $repositories);
    }

    /**
     * Point $packageName at a local directory (symlinked by composer itself,
     * so an installed-by-path plugin under active development is picked up
     * live). Keyed by package name, so reinstalling the same plugin later
     * overwrites this entry rather than accumulating one per attempt.
     *
     * Returns false if the rewritten composer.json could not be written.
     */
    public function addPathRepository(string $scopeBase, string $packageName, string $absolutePath): bool
    {
        return $this->addRepository($scopeBase, $packageName, ['type' => 'path', 'url' => $absolutePath, 'options' => ['symlink' => true]]);
    }

    /**
     * Point $packageName at a VCS remote (a git URL composer cannot otherwise
     * resolve a name from). Returns false if the rewritten composer.json
     * could not be written.
     */
    public function addVcsRepository(string $scopeBase, string $packageName, string $url): bool
    {
        return $this->addRepository($scopeBase, $packageName, ['type' => 'vcs', 'url' => $url]);
    }

    /** @param array{type: string, url: string, options?: array{symlink: bool}} $repository */
    private function addRepository(string $scopeBase, string $packageName, array $repository): bool
    {
        $path = $this->composerJsonPath($scopeBase);
        $composerJson = JsonFile::read($path) ?? [];
        /** @var array<string, mixed> $repositories */
        $repositories = \is_array($composerJson['repositories'] ?? null) ? $composerJson['repositories'] : [];
        $repositories[$packageName] = $repository;
        $composerJson['repositories'] = $repositories;

        return JsonFile::writeEditable($this->fs, $path, $composerJson);
    }
}
