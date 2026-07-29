<?php

declare(strict_types=1);

namespace Porta\Graph;

/** The graph export targets the dot binary renders for us. */
enum GraphExportFormat
{
    case Svg;
    case Png;

    public function flag(): string
    {
        return match ($this) {
            self::Svg => '-Tsvg',
            self::Png => '-Tpng',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Svg => 'svg',
            self::Png => 'png',
        };
    }
}
