<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Ui\Theme\Theme;

/** The IDE color scheme was switched live (holders already swapped, to this resolved theme). */
final readonly class ThemeChanged implements PluginObservable
{
    public function __construct(
        public Theme $theme,
    ) {}
}
