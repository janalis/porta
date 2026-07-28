<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Ui\Theme\Styles;

/**
 * The palette field plus its direct-construction default — the
 * `$styles ?? Styles::dark()` constructor line that was spelled in thirty
 * classes. The nullable parameter stays at each constructor (tests
 * direct-construct without a container); only the field and the coalesce
 * live here.
 */
trait StylesDefault
{
    // Not readonly: mago rejects readonly writes outside a constructor, and
    // this is initialized from the using classes' constructors via initStyles.
    private Styles $styles;

    /** Adopt the injected palette, defaulting to the dark scheme. */
    private function initStyles(?Styles $styles): void
    {
        $this->styles = $styles ?? Styles::dark();
    }
}
