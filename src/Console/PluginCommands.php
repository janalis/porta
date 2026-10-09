<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Plugin\Api\PluginCommand;
use Porta\Plugin\Runtime\PluginAutoloader;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginManifest;
use Porta\Support\Files;

/**
 * The console commands the BUNDLED plugins ship — `porta review …` is the
 * review plugin's — found by name in their manifests' `extra.porta.commands`.
 *
 * Bundled only, and whatever a project enables: a command is run by typing its
 * name, not by opening a project, so a plugin a project turned off still
 * answers — and a project's own `.porta/plugins/` never gets to run code from
 * a shell prompt. Core names no command: it reads which ones exist.
 */
final readonly class PluginCommands
{
    public function __construct(
        private string $pluginsDir,
    ) {}

    public static function bundled(): self
    {
        return new self(PluginLoader::embeddedDir());
    }

    /**
     * The command `porta $name` runs, its plugin's classes (and its
     * requirements') loadable; null when no bundled plugin ships one — or
     * names a class that is not a {@see PluginCommand}.
     */
    public function find(string $name): ?PluginCommand
    {
        $manifests = [];
        // An empty name is no command: nothing is even read.
        foreach ('' === $name ? [] : Files::childFiles($this->pluginsDir, 'plugin.php') as $file) {
            $manifest = PluginManifest::beside($file);
            $manifests[$manifest->name] = $manifest;
        }
        $command = null;
        foreach ($manifests as $manifest) {
            $class = $manifest->commands[$name] ?? null;
            if (null === $class) {
                continue;
            }
            $this->autoload($manifest, $manifests);
            // Only a PluginCommand: a manifest may point `porta` at code
            // written to be run that way, and at nothing else.
            if (\class_exists($class) && \is_subclass_of($class, PluginCommand::class)) {
                // @mago-expect analysis:unsafe-instantiation -- the contract (docs/plugins.md): a plugin command is built with no arguments, every constructor parameter defaulted.
                $command = new $class();
            }
            break;
        }

        return $command;
    }

    /** @param array<string, PluginManifest> $manifests */
    private function autoload(PluginManifest $manifest, array $manifests): void
    {
        PluginAutoloader::register($manifest);
        foreach ($manifest->requires as $required) {
            $requirement = $manifests[$required] ?? null;
            if (null !== $requirement) {
                $this->autoload($requirement, $manifests);
            }
        }
    }
}
