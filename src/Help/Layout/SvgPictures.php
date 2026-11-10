<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Ui\Graphics\RasterImage;

/**
 * The SVG files a page shows, drawn as PNGs off the loop — once per version
 * of each file — by {@see RasterImage}, so with the converter a plugin lends
 * when one does. A layout asks, gets nothing the first time, and is told to
 * lay the page out again once the picture is there.
 */
final class SvgPictures
{
    /** The largest SVG file drawn: a page is not where a document gets to fill memory. */
    public const int MAX_BYTES = 2_097_152;

    /** How wide one is drawn: no page shows a picture wider. */
    public const int WIDTH = RasterImage::MAX_WIDTH;

    /** @var array<string, string|null> file, mtime and size => its PNG, or null while it is drawn or when it cannot be */
    private array $drawn = [];

    /** Whether {@see png()} is asking: an answer then is no news to anyone. */
    private bool $asking = false;

    /** @param \Closure(): void $onDrawn a picture is ready: lay the page out again */
    public function __construct(
        private readonly \Closure $onDrawn,
        private readonly RasterImage $raster = new RasterImage(),
    ) {}

    /** The PNG of the SVG $file, or null while it is drawn or when it cannot be. */
    public function png(string $file): ?string
    {
        $size = (int) \filesize($file);
        $key = $file."\0".(int) \filemtime($file)."\0".$size;
        if (\array_key_exists($key, $this->drawn)) {
            return $this->drawn[$key];
        }
        $this->drawn[$key] = null;
        $this->asking = true;
        try {
            $this->raster->png(
                $size > self::MAX_BYTES ? '' : (string) \file_get_contents($file),
                function (?string $png) use ($key): void {
                    $this->drawn[$key] = $png;
                    if (null !== $png && !$this->asking) {
                        ($this->onDrawn)();
                    }
                },
                self::WIDTH,
            );
        } finally {
            $this->asking = false;
        }

        return $this->drawn[$key];
    }
}
