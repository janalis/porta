<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Support\AnsiText;
use Porta\Ui\Graphics\KittyPlaceholders;
use Porta\Ui\Graphics\PngDimensions;
use Porta\Ui\Graphics\PngRaster;
use Porta\Ui\Graphics\RasterImage;
use Porta\Ui\Graphics\TerminalGraphics;

/**
 * An image drawn as real pixels: a local PNG sent to the terminal as it is
 * (the kitty protocol takes PNG, so nothing decodes it here), sized from its
 * header — or from its `<img width>` — in the terminal's cells, and placed
 * behind placeholder text, so the page can scroll, slice and cut it like any
 * line. A remote image is never fetched, and a terminal without the protocol,
 * a missing file or another format leaves the page its text stand-in.
 *
 * One terminal image per file and size; an image the page stops showing is
 * freed at the next layout ({@see sweep()}).
 */
final class ImageLayout
{
    /** The tallest an image may stand, in rows: a page is read, not looked at. */
    private const int MAX_ROWS = 24;

    /** @var array<string, list<string>|null> fitted fallback rows */
    private array $rasters = [];

    /** @var array<string, int> file, mtime and size => the terminal image id */
    private array $ids = [];
    /** @var array<int, true> the ids the current layout drew */
    private array $used = [];

    public function __construct(
        private readonly ?TerminalGraphics $graphics = null,
        private readonly PngRaster $raster = new PngRaster(),
        private readonly ?SvgPictures $svgs = null,
    ) {}

    /**
     * The image's rows, or null when it cannot be drawn and the page keeps its
     * text stand-in.
     *
     * @return list<PageLine>|null
     */
    private function lines(ImageRef $image, int $width, LayoutState $state, ?string $target, int $group): ?array
    {
        $file = self::file($image->source, $state->baseDir, $state->root, $state->publicDir);
        $graphics = $this->graphics;
        if (null === $graphics || !$graphics->available() || null === $file) {
            return null;
        }
        // An SVG is drawn off the loop: its text stands in until the page is laid out again.
        $png = PngDimensions::MAGIC === \file_get_contents($file, length: 8) ? null : $this->svgs?->png($file);
        // The header is all a layout reads: the whole file only goes to the terminal once.
        $size = PngDimensions::read($png ?? (string) \file_get_contents($file, length: 24));

        return null === $size ? null : $this->placed($graphics, $file, $size, $image, $width, $target, $group, $png);
    }

    /**
     * The image's rows, its file sent to the terminal unless it already holds
     * this size of it.
     *
     * @param array{int, int} $size
     *
     * @return list<PageLine>
     */
    private function placed(TerminalGraphics $graphics, string $file, array $size, ImageRef $image, int $width, ?string $target, int $group, ?string $png): array
    {
        [$columns, $rows] = $this->cells($size, $image->width, $width);
        $key = $file."\0".(int) \filemtime($file)."\0".$columns.'x'.$rows;

        return $this->place(
            $graphics,
            $key,
            static fn (): string => $png ?? (string) \file_get_contents($file),
            $columns,
            $rows,
            null === $target ? [] : [[0, $columns, $target, $group]],
        );
    }

    /**
     * @param \Closure(): string $bytes
     * @param list<array{int, int, string, int}> $links
     * @return list<PageLine>
     */
    private function place(TerminalGraphics $graphics, string $key, \Closure $bytes, int $columns, int $rows, array $links = []): array
    {
        $id = $this->ids[$key] ?? null;
        if (null === $id) {
            $id = $graphics->allocateId();
            $this->ids[$key] = $id;
            $graphics->transmit($id, $bytes());
        }
        $graphics->place($id, $columns, $rows);
        $this->used[$id] = true;
        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $lines[] = new PageLine(KittyPlaceholders::row($id, $row, 0, $columns), $columns, 0, $links);
        }

        return $lines;
    }

    /** @return list<PageLine>|null completed diagram PNG, fitted into this block */
    public function png(string $bytes, int $width): ?array
    {
        $size = PngDimensions::read($bytes);
        if (null === $size) {
            return null;
        }
        [$columns, $rows] = $this->cells($size, '', \max(1, $width));
        $key = \hash('sha256', $bytes).'@'.$columns.'x'.$rows;
        $graphics = $this->graphics;

        return null !== $graphics && $graphics->available()
            ? $this->place($graphics, $key, static fn (): string => $bytes, $columns, $rows)
            : $this->fallback($key, $bytes, $columns, $rows);
    }

    /**
     * The PNG inside a box of $columns × $rows cells: as large as it fits
     * with its shape kept, and centred — an icon, a banner, a screenshot of a
     * strip. Null when it cannot be drawn.
     *
     * @return list<string>|null exactly $rows lines, each $columns wide
     */
    public function boxed(string $png, int $columns, int $rows): ?array
    {
        $size = PngDimensions::read($png);
        if (null === $size || $columns < 1 || $rows < 1) {
            return null;
        }
        [$fitColumns, $fitRows] = $this->fitted($size, $columns, $rows);
        $key = \hash('xxh128', $png).'@'.$fitColumns.'x'.$fitRows;
        $graphics = $this->graphics;
        $lines = null !== $graphics && $graphics->available()
            ? $this->place($graphics, $key, static fn (): string => $png, $fitColumns, $fitRows)
            : $this->fallback($key, $png, $fitColumns, $fitRows);

        return null === $lines ? null : self::centred($lines, $fitColumns, $columns, $rows);
    }

    /**
     * $lines, $width wide, in the middle of a box of $columns × $rows blank cells.
     *
     * @param list<PageLine> $lines
     *
     * @return list<string>
     */
    private static function centred(array $lines, int $width, int $columns, int $rows): array
    {
        $left = \intdiv(\max(0, $columns - $width), num2: 2);
        $top = \intdiv(\max(0, $rows - \count($lines)), num2: 2);
        $boxed = \array_fill(start_index: 0, count: \max(0, $rows), value: AnsiText::blank($columns));
        foreach ($lines as $index => $line) {
            $boxed[$top + $index] = AnsiText::blank($left).$line->text.AnsiText::blank($columns - $width - $left);
        }

        return $boxed;
    }

    /**
     * The cells a PNG fills inside $columns × $rows, its shape kept; null
     * when it is no PNG.
     *
     * @return array{int, int}|null columns and rows
     */
    public function fits(string $png, int $columns, int $rows): ?array
    {
        $size = PngDimensions::read($png);

        return null === $size ? null : $this->fitted($size, \max(1, $columns), \max(1, $rows));
    }

    /**
     * The most cells an image of $size fills inside $columns × $rows, its shape kept.
     *
     * @param array{int, int} $size
     *
     * @return array{int, int}
     */
    private function fitted(array $size, int $columns, int $rows): array
    {
        [$cellWidth, $cellHeight] = $this->graphics?->cellPixels() ?? [8, 16];
        [$pixelWidth, $pixelHeight] = $size;
        $columns = \min($columns, KittyPlaceholders::MAX_CELLS);
        $tall = (int) \round(($columns * $cellWidth * $pixelHeight) / ($pixelWidth * $cellHeight));
        if ($tall <= $rows) {
            return [$columns, \max(1, $tall)];
        }
        $wide = (int) \round(($rows * $cellHeight * $pixelWidth) / ($pixelHeight * $cellWidth));

        return [\max(1, \min($columns, $wide)), $rows];
    }

    /** @return list<PageLine>|null */
    private function fallback(string $key, string $bytes, int $columns, int $rows): ?array
    {
        if (!\array_key_exists($key, $this->rasters)) {
            // Keep the memo bounded across live edits.
            if (\count($this->rasters) >= 64) {
                $this->rasters = [];
            }
            $this->rasters[$key] = $this->raster->rows($bytes, $columns, $rows);
        }
        $raster = $this->rasters[$key];

        return null === $raster ? null : \array_map(static fn (string $row): PageLine => new PageLine($row, $columns), $raster);
    }

    /** Release graphics when the pane hides, without laying out another page. */
    public function clear(): void
    {
        $this->used = [];
        $this->sweep();
        $this->rasters = [];
    }

    /**
     * The image a run of fragments is, when it is nothing else: an image alone
     * in its paragraph (whitespace aside) is drawn as one, an image inside
     * prose keeps its text stand-in.
     *
     * @param list<Fragment> $fragments
     */
    private static function alone(array $fragments): ?Fragment
    {
        $images = \array_values(\array_filter($fragments, static fn (Fragment $fragment): bool => null !== $fragment->image));
        $rest = \array_filter($fragments, static fn (Fragment $fragment): bool => null === $fragment->image && ($fragment->isBreak || '' !== \trim($fragment->text)));

        return 1 === \count($images) && [] === $rest ? $images[0] : null;
    }

    /**
     * The fragments drawn as an image when they are one, else null.
     *
     * @param list<Fragment> $fragments
     *
     * @return list<PageLine>|null
     */
    public function of(array $fragments, int $width, LayoutState $state): ?array
    {
        $fragment = self::alone($fragments);
        if (null === $fragment || null === $fragment->image) {
            return null;
        }

        return $this->lines($fragment->image, $width, $state, $fragment->target, $fragment->group);
    }

    /** Free the terminal images the last layout no longer drew; call once a page is laid out. */
    public function sweep(): void
    {
        foreach ($this->ids as $key => $id) {
            if (\array_key_exists($id, $this->used)) {
                continue;
            }
            $this->graphics?->delete($id);
            unset($this->ids[$key]);
        }
        $this->used = [];
    }

    /**
     * The local PNG or SVG $source names, or null for a remote, missing or
     * other file — or one outside the page's tree: a README's `../../..` or
     * absolute path never reads the reader's own files. A path from `/` not
     * in the tree is looked for in the docs' `public/` too, where the docs
     * site serves it from.
     */
    private static function file(string $source, ?string $baseDir, ?string $root, ?string $publicDir): ?string
    {
        $path = \rawurldecode(\explode('?', \explode('#', $source)[0])[0]);
        if (null === $baseDir || '' === $path || 1 === \preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
            return null;
        }
        $tree = $root ?? $baseDir;

        return \str_starts_with($path, '/')
            ? self::inside($tree.$path, $tree) ?? (null === $publicDir ? null : self::inside($publicDir.$path, $publicDir))
            : self::inside($baseDir.'/'.$path, $tree);
    }

    /** $path when it is a PNG or SVG file inside $fence, else null. */
    private static function inside(string $path, string $fence): ?string
    {
        $file = \realpath($path);
        $fence = \realpath($fence);

        return false !== $file
        && false !== $fence
        && \str_starts_with($file, \rtrim($fence, characters: '/').'/')
        && \is_file($file)
        && \in_array(RasterImage::kind((string) \file_get_contents($file, length: 4096)), ['png', 'svg'], strict: true)
            ? $file
            : null;
    }

    /**
     * How many cells the image takes: its CSS width (asked, or its own pixels)
     * in device pixels over a cell's width, never wider than the page, and as
     * many rows as keep its shape.
     *
     * @param array{int, int} $size
     *
     * @return array{int, int}
     */
    private function cells(array $size, string $asked, int $pageWidth): array
    {
        [$cellWidth, $cellHeight] = $this->graphics?->cellPixels() ?? [8, 16];
        $ratio = $this->graphics?->pixelRatio() ?? 1.0;
        [$pixelWidth, $pixelHeight] = $size;
        $number = (int) \rtrim($asked, characters: '%px');
        $css = match (true) {
            1 === \preg_match('/^\d+%$/', $asked) => (($number * $pageWidth * $cellWidth) / 100) / $ratio,
            1 === \preg_match('/^\d+(?:px)?$/', $asked) => $number,
            default => $pixelWidth / $ratio,
        };
        $columns = \max(1, \min($pageWidth, KittyPlaceholders::MAX_CELLS, (int) \round(($css * $ratio) / $cellWidth)));
        $rows = (int) \round((($columns * $cellWidth * $pixelHeight) / $pixelWidth) / $cellHeight);
        if ($rows > self::MAX_ROWS) {
            $columns = \max(1, (int) \round(($columns * self::MAX_ROWS) / $rows));
            $rows = self::MAX_ROWS;
        }

        return [$columns, \max(1, $rows)];
    }
}
