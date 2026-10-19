<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

/** Bounded, fitted PNG decoding for Markdown's non-pixel image display. */
final readonly class PngRaster
{
    private bool $available;

    public function __construct(?bool $available = null)
    {
        $this->available = $available ?? \function_exists('imagecreatefromstring');
    }

    /** @return list<string>|null */
    public function rows(string $bytes, int $columns, int $rows): ?array
    {
        $size = PngDimensions::read($bytes);
        if (!$this->available || null === $size || ($size[0] * $size[1]) > 16_000_000 || \strlen($bytes) > (20 * 1024 * 1024)) {
            return null;
        }
        \set_error_handler(static fn (): bool => true);
        try {
            $image = \imagecreatefromstring($bytes);
        } finally {
            \restore_error_handler();
        }

        return $image instanceof \GdImage ? $this->scaledRows($image, $size, $columns, $rows) : null;
    }

    /** @param array{int, int} $size
     * @return list<string>
     */
    private function scaledRows(\GdImage $image, array $size, int $columns, int $rows): array
    {
        $columns = \max(1, $columns);
        $rows = \max(1, $rows);
        $scaled = \imagecreatetruecolor($columns, height: $rows * 2);
        \imagealphablending($scaled, enable: false);
        \imagesavealpha($scaled, enable: true);
        \imagecopyresampled($scaled, $image, dst_x: 0, dst_y: 0, src_x: 0, src_y: 0, dst_width: $columns, dst_height: $rows * 2, src_width: $size[0], src_height: $size[1]);
        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $pixels = [];
            for ($column = 0; $column < $columns; ++$column) {
                $pixels[] = [self::pixel($scaled, $column, $row * 2), self::pixel($scaled, $column, ($row * 2) + 1)];
            }
            $lines[] = HalfBlockRow::encode($pixels);
        }

        return $lines;
    }

    /** @return array{int, int, int} */
    private static function pixel(\GdImage $image, int $x, int $y): array
    {
        $pixel = (int) \imagecolorat($image, $x, $y);
        $opacity = 1 - ((($pixel >> 24) & 0x7F) / 127);
        $checker = 0 === ((($x >> 3) + ($y >> 3)) & 1) ? 90 : 140;
        $rgb = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];

        return \array_map(static fn (int $channel): int => (int) \round(($channel * $opacity) + ($checker * (1 - $opacity))), $rgb);
    }
}
