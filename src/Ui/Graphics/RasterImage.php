<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

use Porta\Support\AsyncProcess;

/**
 * Any picture the terminal is shown, as the PNG the kitty protocol and the
 * half-block fallback both read: a PNG as it is; a JPEG, GIF or WebP through
 * GD, narrowed to {@see MAX_WIDTH}; an SVG through `rsvg-convert`, at
 * {@see SVG_WIDTH}, when it is installed.
 *
 * Only librsvg draws an SVG here, never ImageMagick: these documents come off
 * the network, from anyone, and ImageMagick's SVG reader follows what a
 * document references. Fed on stdin, librsvg has no base to resolve a
 * reference against, so it loads nothing but the document.
 */
final readonly class RasterImage
{
    /** The widest a converted picture is kept: wider than any terminal shows one. */
    public const int MAX_WIDTH = 1024;

    /** How wide an SVG is drawn. */
    public const int SVG_WIDTH = 512;

    /** How long `rsvg-convert` may take over one document. */
    private const float SVG_TIMEOUT = 15.0;

    /** The most pixels GD is asked to decode. */
    private const int MAX_PIXELS = 16_000_000;

    /** @var \Closure(string, \Closure(?string): void): void */
    private \Closure $svg;

    private bool $gd;

    /**
     * @param (\Closure(string, \Closure(?string): void): void)|null $svg an SVG document to its PNG, or null; inject to test
     */
    public function __construct(?\Closure $svg = null, ?bool $gd = null)
    {
        $this->svg = $svg ?? self::rsvg(...);
        $this->gd = $gd ?? \function_exists('imagecreatefromstring');
    }

    /** What $bytes are: `png`, `jpeg`, `gif`, `webp`, `svg`, or null for anything else. */
    public static function kind(string $bytes): ?string
    {
        return match (true) {
            \str_starts_with($bytes, PngDimensions::MAGIC) => 'png',
            \str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            \str_starts_with($bytes, 'GIF87a'), \str_starts_with($bytes, 'GIF89a') => 'gif',
            \str_starts_with($bytes, 'RIFF') && 'WEBP' === \substr($bytes, offset: 8, length: 4) => 'webp',
            1 === \preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*(?:<!DOCTYPE[^>]*>\s*)?(?:<!--.*?-->\s*)*<svg\b/is', \substr($bytes, offset: 0, length: 4096)) => 'svg',
            default => null,
        };
    }

    /**
     * $bytes as a PNG, or null when they are no picture this machine can draw.
     *
     * @param \Closure(?string): void $onPng exactly once, possibly before this returns
     */
    public function png(string $bytes, \Closure $onPng): void
    {
        $kind = self::kind($bytes);
        match ($kind) {
            'png' => $onPng($bytes),
            'svg' => ($this->svg)($bytes, static function (?string $png) use ($onPng): void {
                $onPng(null !== $png && 'png' === self::kind($png) ? $png : null);
            }),
            null => $onPng(null),
            default => $onPng($this->decoded($bytes)),
        };
    }

    /** A JPEG, GIF or WebP redrawn as a PNG, no wider than {@see MAX_WIDTH}; null when GD cannot read it. */
    private function decoded(string $bytes): ?string
    {
        $size = $this->gd ? \getimagesizefromstring($bytes) : false;
        // A small file can claim a huge canvas: one past the bound is never decoded.
        $image = false === $size || ($size[0] * $size[1]) > self::MAX_PIXELS ? null : self::read($bytes);
        if ($image instanceof \GdImage && \imagesx($image) > self::MAX_WIDTH) {
            $image = \imagescale($image, self::MAX_WIDTH);
        }

        return $image instanceof \GdImage ? self::encoded($image) : null;
    }

    private static function read(string $bytes): ?\GdImage
    {
        \set_error_handler(static fn (): bool => true);
        try {
            $image = \imagecreatefromstring($bytes);
        } finally {
            \restore_error_handler();
        }

        return $image instanceof \GdImage ? $image : null;
    }

    private static function encoded(\GdImage $image): string
    {
        \imagesavealpha($image, enable: true);
        \ob_start();
        \imagepng($image);

        return (string) \ob_get_clean();
    }

    /**
     * The SVG drawn by `rsvg-convert`, off the loop, or null when it is not
     * installed, fails, or runs too long.
     *
     * @param \Closure(?string): void $onPng
     */
    private static function rsvg(string $svg, \Closure $onPng): void
    {
        // The array form finds the program on the PATH itself, and says false when it is not there.
        $process = new AsyncProcess(['rsvg-convert', '-f', 'png', '-w', (string) self::SVG_WIDTH], \sys_get_temp_dir(), stdin: $svg, timeout: self::SVG_TIMEOUT);
        $started = $process->start(static function (int $code, string $out) use ($onPng): void {
            $onPng(0 === $code && '' !== $out ? $out : null);
        });
        if (!$started) {
            $onPng(null);
        }
    }
}
