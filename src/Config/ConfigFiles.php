<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Where each {@see ConfigScope}'s file lives, and what to call it on screen.
 *
 * One place that knows the two paths, so the loader that reads them and the
 * writer that writes them cannot drift. Not a container service: the loader
 * runs before the container exists.
 */
final readonly class ConfigFiles
{
    public function __construct(
        private string $rootDir,
        private ?string $home = null,
    ) {}

    /** The project root these paths are relative to. */
    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /** Resolves the home base from the environment, as everything else does. */
    public static function for(string $rootDir): self
    {
        return new self($rootDir, PortaHome::base());
    }

    /**
     * The scope's file path, or null for a global scope with no usable home —
     * an environment with neither PORTA_HOME nor HOME, where there is nowhere
     * to put a user-level file and pretending otherwise would write to '/'.
     */
    public function path(ConfigScope $scope): ?string
    {
        if (ConfigScope::Project === $scope) {
            return $this->rootDir.'/.porta.json';
        }

        return null === $this->home ? null : $this->home.'/.porta/config.json';
    }

    /**
     * What to call the file in a message, e.g. ".porta.json" or
     * "~/.porta/config.json". The home segment is abbreviated because the full
     * path is long, and in a settings row the interesting part is which of the
     * two files it is.
     */
    public function label(ConfigScope $scope): string
    {
        return ConfigScope::Project === $scope ? '.porta.json' : '~/.porta/config.json';
    }
}
