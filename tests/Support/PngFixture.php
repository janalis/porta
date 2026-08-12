<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

/**
 * Real PNG bytes, built with GD (a composer-required extension), for the tests
 * that drive the graph viewer's bitmap path without a graphviz anywhere near
 * them. Committing a binary fixture would hide the one thing these tests care
 * about — the pixel dimensions, which is how a test tells one dpi's render from
 * another's.
 */
final class PngFixture
{
    /**
     * A $width × $height PNG filled with $rgb.
     *
     * @param array{int, int, int} $rgb
     */
    public static function bytes(int $width, int $height, array $rgb = [200, 40, 40]): string
    {
        $gd = imagecreatetruecolor($width, height: $height);
        [$r, $g, $b] = $rgb;
        imagefilledrectangle($gd, x1: 0, y1: 0, x2: $width - 1, y2: $height - 1, color: (int) imagecolorallocate($gd, red: $r, green: $g, blue: $b));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
