<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The user's home directory, as Porta resolves it.
 *
 * `PORTA_HOME` overrides `HOME`, which is what lets the test suite give a
 * scenario a home of its own without touching the developer's.
 *
 * This was `PluginLoader::globalBase()` while plugins were the only thing that
 * had a global layer. Config has one now, and the two must agree on where home
 * is — `<home>/.porta/plugins` and `<home>/.porta/config.json` are siblings by
 * design, so a second resolution that could drift from this one would be a bug
 * waiting for someone to set PORTA_HOME.
 */
final class PortaHome
{
    /** The home base, or null when the environment names none usable. */
    public static function base(): ?string
    {
        $portaHome = \getenv('PORTA_HOME');
        $base = $portaHome ? $portaHome : \getenv('HOME');

        return \is_string($base) && '' !== $base ? $base : null;
    }

    /** `~` is what a person types and what the hints show; nothing else expands it. */
    public static function expand(string $path): string
    {
        $home = self::base();
        // `~` and `~/…` only: `~bob` is bob's home, which this is not.
        if (null === $home || ('~' !== $path && !\str_starts_with($path, '~/'))) {
            return $path;
        }

        return $home.\substr($path, offset: 1);
    }

    /** The inverse, for display: under home a path is shorter from `~`, and reads as a place. */
    public static function tilde(string $path): string
    {
        $home = self::base();

        return null !== $home && \str_starts_with($path, $home.'/') ? '~'.\substr($path, \strlen($home)) : $path;
    }
}
