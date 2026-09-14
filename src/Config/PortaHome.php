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
}
