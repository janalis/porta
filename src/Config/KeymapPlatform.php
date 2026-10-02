<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * Whose keyboard conventions the default keymap follows (the "Keymap" setting):
 * macOS puts the primary shortcuts on Cmd, Windows and Linux on Ctrl, and each
 * platform's own terminal takes a few chords for itself.
 *
 * It is the platform of the KEYBOARD, not of the PHP process: Porta under WSL
 * runs on Linux and is typed at from Windows Terminal, and Porta over SSH runs
 * wherever the server is. Detection covers the first; the setting, or
 * `PORTA_KEYMAP_PLATFORM` in the environment, covers the second.
 */
enum KeymapPlatform: string
{
    case MacOs = 'macos';
    case Windows = 'windows';
    case Linux = 'linux';

    /** The setting's value that defers to {@see detect()}. */
    public const string AUTO = 'auto';

    /**
     * The platform the keys are most likely typed on.
     *
     * @param string                $os  \PHP_OS_FAMILY
     * @param array<string, string> $env the process environment
     */
    public static function detect(string $os, array $env): self
    {
        $named = self::tryFrom($env['PORTA_KEYMAP_PLATFORM'] ?? '');
        if (null !== $named) {
            return $named;
        }

        return match (true) {
            'Darwin' === $os => self::MacOs,
            // Windows Terminal marks every session it hosts, WSL every shell it starts.
            'Windows' === $os, \array_key_exists('WT_SESSION', $env), \array_key_exists('WSL_DISTRO_NAME', $env) => self::Windows,
            default => self::Linux,
        };
    }

    /** The setting's choice, or the detected platform when it is `auto` (or anything else). */
    public static function resolve(string $setting, self $detected): self
    {
        return self::tryFrom($setting) ?? $detected;
    }
}
