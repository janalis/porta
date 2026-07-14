<?php

declare(strict_types=1);

namespace Porta\Event;

/** The IDE color scheme was switched live (holders already swapped). */
final readonly class ThemeChanged
{
    public function __construct(
        public string $theme,
    ) {}
}
