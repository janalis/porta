<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

/** PNG dimensions without allocating a decoded bitmap. */
final class PngDimensions
{
    public const string MAGIC = "\x89PNG\r\n\x1a\n";

    /** @return array{int, int}|null */
    public static function read(string $head): ?array
    {
        $header = \strlen($head) >= 24 && \str_starts_with($head, self::MAGIC) && 'IHDR' === \substr($head, offset: 12, length: 4);
        $dimensions = $header ? \unpack('Nwidth/Nheight', \substr($head, offset: 16, length: 8)) : false;

        return false === $dimensions || $dimensions['width'] < 1 || $dimensions['height'] < 1 ? null : [(int) $dimensions['width'], (int) $dimensions['height']];
    }
}
