<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * Where a tool can come from, and the argv that installs or upgrades it there.
 *
 * Two of these run no package manager. {@see Github} downloads a release
 * asset itself ({@see GithubInstaller}). {@see Plugin} hands the job to one of
 * the declaring plugin's own commands — the escape hatch for a third-party
 * plugin whose tool has no source Porta knows.
 */
enum ToolManager: string
{
    case Composer = 'composer';
    case Brew = 'brew';
    case BrewCask = 'brew-cask';
    case Cargo = 'cargo';
    case Npm = 'npm';
    case Github = 'github';
    case Plugin = 'self';

    /** The program that does the work, looked up on the PATH; null when Porta does it itself. */
    public function executable(): ?string
    {
        return match ($this) {
            self::Composer => 'composer',
            self::Brew, self::BrewCask => 'brew',
            self::Cargo => 'cargo',
            self::Npm => 'npm',
            self::Github, self::Plugin => null,
        };
    }

    /**
     * The words after the executable that install $package.
     *
     * composer installs into the PROJECT (`require --dev`, landing in
     * `vendor/bin`), which is why its source is skipped while the project is
     * untrusted: Porta does not run a vendor binary it has not been allowed to.
     *
     * @return list<string>
     */
    public function installArgs(string $package): array
    {
        return match ($this) {
            self::Composer => ['require', '--dev', $package],
            self::Brew => ['install', $package],
            self::BrewCask => ['install', '--cask', $package],
            self::Cargo => ['install', $package, '--locked'],
            self::Npm => ['install', '--global', $package],
            self::Github, self::Plugin => [],
        };
    }

    /**
     * The words that bring an installed $package up to date, through the same
     * manager that installed it — never "install again and hope": `composer
     * require` re-resolves to the constraint it already wrote, and `cargo
     * install` without `--force` refuses a crate that is already there.
     *
     * @return list<string>
     */
    public function updateArgs(string $package): array
    {
        return match ($this) {
            self::Composer => ['update', $package, '--with-dependencies'],
            self::Brew => ['upgrade', $package],
            self::BrewCask => ['upgrade', '--cask', $package],
            self::Cargo => ['install', $package, '--locked', '--force'],
            self::Npm => ['install', '--global', $package.'@latest'],
            self::Github, self::Plugin => [],
        };
    }
}
