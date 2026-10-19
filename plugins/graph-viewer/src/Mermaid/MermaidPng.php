<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\DotColor;
use Porta\ImageViewer\ImageDecoder;

/** Native raster backend; yields between marks and caps allocation before creating GD images. */
final readonly class MermaidPng
{
    private bool $available;

    public function __construct(
        private int $maxPixels = ImageDecoder::MAX_PIXELS,
        ?bool $gdAvailable = null,
        private string $font = MermaidFont::FILE,
        private MermaidFallbackFonts $fallbacks = new MermaidFallbackFonts(),
    ) {
        $this->available = $gdAvailable ?? \function_exists('imagettftext');
    }

    /** @return \Generator<int, null, mixed, string> */
    public function render(MermaidDrawing $drawing, int $dpi = 96): \Generator
    {
        if (!$this->available) {
            throw new \InvalidArgumentException('Native Mermaid PNG requires the PHP GD extension with FreeType support');
        }
        if ($dpi < 1 || $this->maxPixels < 1 || $drawing->width < 1 || $drawing->height < 1 || !\is_finite($drawing->width) || !\is_finite($drawing->height)) {
            throw new \InvalidArgumentException('Invalid Mermaid raster dimensions');
        }
        $scale = \min(10 * ($dpi / 96), \sqrt($this->maxPixels / ($drawing->width * $drawing->height)), \min(16_000, $this->maxPixels) / \max($drawing->width, $drawing->height));
        $width = \max(1, (int) \floor($drawing->width * $scale));
        $height = \max(1, (int) \floor($drawing->height * $scale));
        $fonts = [];
        try {
            $canvas = \imagecreatetruecolor($width, $height);
            \imagefill($canvas, x: 0, y: 0, color: self::color($drawing->background ?? '#ffffff'));
            foreach ($drawing->marks as $mark) {
                $face = $mark->font->value;
                $fonts[$face] ??= new MermaidFont(MermaidFont::file($mark->font, $this->font));
                $path = $fonts[$face]->path();
                $this->mark($canvas, $mark, $scale, $path);
                yield null;
            }
            \ob_start();
            \imagepng($canvas, quality: 1);

            return (string) \ob_get_clean();
        } finally {
            foreach ($fonts as $font) {
                $font->close();
            }
        }
    }

    private function mark(\GdImage $canvas, MermaidMark $mark, float $scale, string $font): void
    {
        match ($mark->kind) {
            'image' => $this->image($canvas, $mark, $scale),
            'text' => $this->text($canvas, $mark, $scale, self::color($mark->stroke, $mark->opacity), $font),
            default => self::outline($canvas, $mark, $scale),
        };
    }

    private static function outline(\GdImage $canvas, MermaidMark $mark, float $scale): void
    {
        $color = self::color($mark->stroke, $mark->opacity);
        $points = \array_map(static fn (array $point): array => [(int) \round($point[0] * $scale), (int) \round($point[1] * $scale)], $mark->points);
        if ('polygon' === $mark->kind && \count($points) >= 3) {
            if ('none' !== $mark->fill && null === $mark->gradient) {
                // GD fills with scanlines: a previous thick stroke would blend each row repeatedly.
                \imagesetthickness($canvas, thickness: 1);
                \imagefilledpolygon($canvas, \array_merge(...$points), self::color($mark->fill, $mark->fillOpacity * $mark->opacity));
            }
            if (null !== $mark->gradient) {
                \imagesetthickness($canvas, thickness: 1);
                self::gradient($canvas, [...$points, $points[0]], $mark->gradient, $mark->opacity);
            }
            $points[] = $points[0];
        }
        if ($mark->strokeWidth <= 0.0 || 'none' === $mark->stroke) {
            return;
        }
        \imagesetthickness($canvas, \max(1, (int) \round($mark->strokeWidth * $scale)));
        if ($mark->dashed) {
            \imagesetstyle($canvas, self::dashes($mark->dashes ?? [0.6, 0.4], $scale, $color));
            $color = \IMG_COLOR_STYLED;
        }
        $count = \count($points);
        for ($index = 1; $index < $count; ++$index) {
            \imageline($canvas, $points[$index - 1][0], $points[$index - 1][1], $points[$index][0], $points[$index][1], $color);
        }
    }

    /**
     * A GD line style: each dash in the stroke's color, each gap transparent, a pixel at least.
     *
     * @param list<float> $lengths
     * @return non-empty-list<int>
     */
    private static function dashes(array $lengths, float $scale, int $color): array
    {
        $style = [];
        foreach ($lengths as $index => $length) {
            \array_push($style, ...\array_fill(0, \max(1, (int) \round($length * $scale)), 0 === ($index % 2) ? $color : \IMG_COLOR_TRANSPARENT));
        }

        return [] === $style ? [$color] : $style;
    }

    /** An embedded picture, decoded within the raster's own pixel limit and stretched to its box. */
    private function image(\GdImage $canvas, MermaidMark $mark, float $scale): void
    {
        $bytes = (string) \base64_decode(\explode(',', $mark->text, limit: 2)[1] ?? '', strict: true);
        $picture = new ImageDecoder($this->maxPixels)->decodeBytes($bytes);
        if (null === $picture) {
            return;
        }
        [[$left, $top], [$right, $bottom]] = $mark->points;
        $x = (int) \round($left * $scale);
        $y = (int) \round($top * $scale);
        \imagecopyresampled(
            $canvas,
            $picture->gd,
            $x,
            $y,
            src_x: 0,
            src_y: 0,
            dst_width: \max(1, (int) \round($right * $scale) - $x),
            dst_height: \max(1, (int) \round($bottom * $scale) - $y),
            src_width: $picture->width,
            src_height: $picture->height,
        );
    }

    private function text(\GdImage $canvas, MermaidMark $mark, float $scale, int $color, string $font): void
    {
        if ('' === $mark->text) {
            return;
        }
        $size = \max(0.1, 1.2 * $scale * $mark->fontScale);
        $segments = [];
        $width = 0;
        foreach ($this->segments($mark->text, $font) as [$text, $path]) {
            /** @var false|array{int, int, int, int, int, int, int, int} $box GD returns four integer coordinate pairs */
            $box = \imagettfbbox($size, angle: 0.0, font_filename: $path, string: $text);
            $advance = false === $box ? 0 : $box[2] - $box[0];
            $segments[] = [$text, $path, $advance];
            $width += $advance;
        }
        // GD turns text counterclockwise from its baseline start; the mark turns clockwise about its center.
        $radians = \deg2rad($mark->angle);
        [$cos, $sin] = [\cos($radians), \sin($radians)];
        $x = ($mark->points[0][0] * $scale) - (($cos * $width) / 2);
        $y = ($mark->points[0][1] * $scale) - (($sin * $width) / 2);
        foreach ($segments as [$text, $path, $advance]) {
            \imagettftext($canvas, $size, angle: -$mark->angle, x: (int) \round($x), y: (int) \round($y), color: $color, font_filename: $path, text: $text);
            $x += $cos * $advance;
            $y += $sin * $advance;
        }
    }

    /**
     * The text in runs of one font: the bundled face where it has the glyph, else the first
     * host fallback that does. GD reads only the Basic Multilingual Plane from UTF-8, so every
     * other character travels as a numeric entity, and so does `&` itself.
     *
     * @return list<array{string, string}>
     */
    private function segments(string $text, string $font): array
    {
        $bundled = $this->fallbacks->coverage($font);
        /** @var list<array{string, string}> $segments */
        $segments = [];
        foreach (\mb_str_split($text) as $character) {
            $codepoint = (int) \mb_ord($character);
            // GD cannot draw beyond the Basic Multilingual Plane, so emoji and the like draw as a box.
            $codepoint = $codepoint > 0xFFFF ? 0x25A1 : $codepoint;
            $path = $bundled->covers($codepoint) ? $font : $this->fallbacks->font($codepoint) ?? $font;
            $encoded = \ctype_print($character) && '&' !== $character ? $character : '&#'.$codepoint.';';
            $previous = \array_pop($segments);
            if (null !== $previous && $previous[1] === $path) {
                $segments[] = [$previous[0].$encoded, $path];
                continue;
            }
            if (null !== $previous) {
                $segments[] = $previous;
            }
            $segments[] = [$encoded, $path];
        }

        return $segments;
    }

    /**
     * A left-to-right gradient fill, one pixel column at a time between the polygon's highest
     * and lowest crossing of that column; ribbons are a single span per column.
     *
     * @param list<array{int, int}> $points
     * @param array{string, string} $gradient
     */
    private static function gradient(\GdImage $canvas, array $points, array $gradient, float $opacity): void
    {
        $xs = \array_column($points, 0);
        [$left, $right] = [\min($xs), \max($xs)];
        $count = \count($points);
        for ($x = $left; $x <= $right; ++$x) {
            $ys = [];
            for ($index = 1; $index < $count; ++$index) {
                [[$ax, $ay], [$bx, $by]] = [$points[$index - 1], $points[$index]];
                if ($ax !== $bx && $x >= \min($ax, $bx) && $x <= \max($ax, $bx)) {
                    $ys[] = (int) \round($ay + ((($by - $ay) * ($x - $ax)) / ($bx - $ax)));
                }
            }
            if ([] === $ys) {
                continue;
            }
            $share = ($x - $left) / \max(1.0e-9, $right - $left);
            $color = self::color(MermaidColor::mixed($gradient[0], $gradient[1], $share), $opacity);
            \imageline($canvas, $x, \min($ys), $x, \max($ys), $color);
        }
    }

    private static function color(string $value, float $opacity = 1.0): int
    {
        $rgb = (int) \hexdec(\substr(DotColor::normalize($value) ?? '#333333', offset: 1));
        $embedded = 1 === \preg_match('/^#[0-9a-f]{8}$/i', $value) ? \hexdec(\substr($value, offset: -2)) / 255 : 1.0;
        $alpha = (int) \round(127 * (1 - ($opacity * $embedded)));

        return ($alpha << 24) | $rgb;
    }
}
