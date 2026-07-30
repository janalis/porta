<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\ImageViewer\SvgConverter;

/**
 * Whether a real SVG converter is reachable on the current PATH — the skip guard
 * for the one real-binary smoke test (everything else drives the committed
 * fake-rsvg stub), the {@see GraphvizBinary} shape.
 */
trait SvgConverterBinary
{
    /** Skip the running test when no SVG converter is installed. */
    private function skipWithoutSvgConverter(): void
    {
        if (null === SvgConverter::discover()) {
            $this->markTestSkipped('no SVG converter (librsvg / ImageMagick) available');
        }
    }
}
