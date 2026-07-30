<?php

declare(strict_types=1);

namespace Acme\Tools;

/**
 * A class shipped BY a plugin, in the plugin's own namespace — the thing that
 * was impossible before plugin.json: composer maps only `Porta\ => src/`, and a
 * third-party plugin cannot edit composer.json.
 */
final class Greeting
{
    public static function text(): string
    {
        return 'hello from acme-tools';
    }
}
