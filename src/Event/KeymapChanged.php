<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Config\KeymapPlatform;

/** The keymap follows another platform's conventions (the live map is already rebuilt). */
final readonly class KeymapChanged
{
    public function __construct(
        public KeymapPlatform $platform,
    ) {}
}
