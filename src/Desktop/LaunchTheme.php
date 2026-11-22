<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Config\ConfigFiles;
use Porta\Settings\SettingsProvenance;

/**
 * The theme a launch draws its first frame in: the one chosen in a config
 * file, or else, in a window, the system's light or dark look, as the window
 * already shows it. Without this the picker and the IDE came up dark in a
 * light window and switched a moment later, once the window could say.
 *
 * The rule {@see DesktopRequests::followAppearance()} keeps once the IDE is up.
 */
final readonly class LaunchTheme
{
    /** @param array<array-key, mixed> $settings the effective settings, defaults included */
    public static function for(string $rootDir, array $settings, DesktopSession $session): string
    {
        return !self::chosen($rootDir) && null !== $session->appearance ? $session->appearance : (string) ($settings['theme'] ?? 'dark');
    }

    /** Whether a config file, the project's or the global one, chose a theme: the window's look then stands for nothing. */
    public static function chosen(string $rootDir): bool
    {
        return null !== new SettingsProvenance(ConfigFiles::for($rootDir))->originOf(['settings', 'theme']);
    }
}
