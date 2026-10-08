<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

/** An image a fragment stands for: where it is, and the width its `<img>` asked for (CSS pixels, or a percentage). */
final readonly class ImageRef
{
    public function __construct(
        public string $source,
        public string $width = '',
    ) {}
}
