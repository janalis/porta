<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Config\PortaHome;
use Porta\Config\WorkspaceTrust;
use Porta\Plugin\Api\BinarySearch;
use Porta\Plugin\Runtime\PluginSettings;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Where a declared tool's binary is on this machine, and who put it there.
 *
 * ONE resolution for every asker — the plugin's launcher, its menu entries,
 * the Settings page and the update check. They used to differ (symfony-lsp's
 * launcher found its own install in ~/.porta/bin while its menu, asking the
 * PATH, did not, so "Install Server" stayed lit after a successful install),
 * and a tool that is "there" to one of them and "missing" to another is worse
 * than either answer.
 *
 * The order:
 *
 *   1. the plugin's setting, when the user named a binary by hand — a bare
 *      name through the PATH, a path only when it is executable, so a
 *      teammate's absolute path riding in on a committed `.porta.json` falls
 *      through to what works here instead of shadowing it;
 *   2. `<project>/vendor/bin`, for a tool composer installs, and only in a
 *      trusted project — a project's vendor dir is the project's code;
 *   3. the PATH;
 *   4. `~/.porta/bin`, where {@see GithubInstaller} lands what it downloads.
 *      Nothing puts that directory on the PATH.
 *
 * Deliberately not memoised: the setting changes at runtime, and a cached
 * answer would ignore it until restart (chroma's used to). It costs a few stat
 * calls on a path that runs per resolution, never per key.
 */
final class ToolLocator
{
    /** @var \Closure(string): ?string */
    private readonly \Closure $find;

    /** The trust answer, asked once: it decides every menu predicate, and must not flip mid-session. */
    private ?bool $trusted = null;

    /**
     * @param \Closure(string, string): (bool|int|string|null) $setting (plugin, key) => the raw configured value
     * @param \Closure(): bool $trust whether the project may run code of its own
     * @param string|null $binDir where downloaded tools live, or null when there is no home
     * @param (\Closure(string): ?string)|null $find a binary name => its path on the PATH; inject to test
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly \Closure $setting,
        private readonly \Closure $trust,
        public readonly ?string $binDir,
        ?\Closure $find = null,
    ) {
        $this->find = $find ?? static fn (string $name): ?string => new ExecutableFinder()->find($name);
    }

    /** The production wiring: the plugin settings as configured, the session's trust, the user's home. */
    public static function forProject(string $rootDir, PluginSettings $settings): self
    {
        return new self(
            $rootDir,
            $settings->raw(...),
            static fn (): bool => WorkspaceTrust::fromEnvironment()->isTrusted($rootDir),
            self::binDirUnder(PortaHome::base()),
        );
    }

    /** `<home>/.porta/bin`, or null when there is no home to install into. */
    public static function binDirUnder(?string $home): ?string
    {
        return null === $home || '' === $home ? null : $home.'/.porta/bin';
    }

    /** The binary for $tool, or null when it is nowhere this looks. */
    public function locate(ToolRequirement $tool): ?string
    {
        $configured = null === $tool->setting ? '' : \trim((string) ($this->setting)($tool->plugin, $tool->setting));

        return ('' === $configured ? null : $this->named($configured)) ?? $this->vendored($tool) ?? ($this->find)($tool->binary) ?? $this->downloaded($tool->binary);
    }

    /**
     * The declared source that manages $path — the one an update must go
     * through — or null when Porta did not and could not have put it there:
     * a binary named by hand, a system package, a build from source. Such a
     * tool is shown but never "updated" by a command that would fight
     * whatever does manage it.
     *
     * Read off the RESOLVED path: brew and npm link their binaries into a
     * shared bin directory, and only the link's target says which one it is.
     */
    public function sourceOf(ToolRequirement $tool, string $path): ?ToolSource
    {
        $real = \realpath($path);
        $real = false === $real ? $path : $real;
        $manager = match (true) {
            \str_starts_with($path, $this->rootDir.'/vendor/') => ToolManager::Composer,
            null !== $this->binDir && \str_starts_with($path, $this->binDir.'/') => ToolManager::Github,
            \str_contains($real, '/Caskroom/') => ToolManager::BrewCask,
            \str_contains($real, '/Cellar/') => ToolManager::Brew,
            \str_contains($real, '/.cargo/bin/') => ToolManager::Cargo,
            \str_contains($real, '/node_modules/') => ToolManager::Npm,
            default => null,
        };

        // A plugin's own installer is the one source with no location to
        // recognise; it lands wherever the plugin decided, so it manages what
        // it declared or nothing does.
        return null === $manager ? $tool->source(ToolManager::Plugin) : $tool->source($manager);
    }

    /** A hand-named binary: through the PATH when bare, as given when it runs. */
    private function named(string $configured): ?string
    {
        if (!\str_contains($configured, needle: '/')) {
            return ($this->find)($configured);
        }

        return \is_file($configured) && \is_executable($configured) ? $configured : null;
    }

    private function vendored(ToolRequirement $tool): ?string
    {
        $local = $this->rootDir.'/vendor/bin/'.$tool->binary;

        return BinarySearch::VendorBinThenPath === $tool->search() && $this->trusted() && \is_file($local) && \is_executable($local) ? $local : null;
    }

    private function trusted(): bool
    {
        return $this->trusted ??= ($this->trust)();
    }

    private function downloaded(string $binary): ?string
    {
        $path = null === $this->binDir ? null : $this->binDir.'/'.$binary;

        return null !== $path && \is_file($path) && \is_executable($path) ? $path : null;
    }
}
