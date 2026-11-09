<?php

declare(strict_types=1);

namespace Porta\Install;

/** How the running Porta got onto this machine ({@see Installation::detect()}), which decides how it is updated. */
enum InstallMethod
{
    /** A porta.phar the user put somewhere: `--self-update` swaps it for the latest release's. */
    case Phar;

    /** The phar Homebrew keeps in its Cellar: brew owns the file, so brew upgrades it. */
    case Homebrew;

    /** `composer global require janalis/porta`: Composer updates it. */
    case ComposerGlobal;

    /** A dependency of some project: its lock file is that project's, so the command is only printed. */
    case ComposerProject;

    /** A git checkout, someone working on Porta: the command is only printed. */
    case Checkout;

    /** Whether `--self-update` runs the update itself, rather than saying what would. */
    public function updatesItself(): bool
    {
        return match ($this) {
            self::Phar, self::Homebrew, self::ComposerGlobal => true,
            self::ComposerProject, self::Checkout => false,
        };
    }
}
