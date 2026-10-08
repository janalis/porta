<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Ui\Graphics\KittyPlaceholders;
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
    private const string PNG = "\x89PNG\r\n\x1a\n";

    /** @var array<string, int> file, mtime and size => the terminal image id */
    private array $ids = [];
    /** @var array<int, true> the ids the current layout drew */
    private array $used = [];

    public function __construct(
        private readonly ?TerminalGraphics $graphics = null,
    ) {}

    /**
     * The image's rows, or null when it cannot be drawn and the page keeps its
     * text stand-in.
     *
     * @return list<PageLine>|null
     */
    private function lines(ImageRef $image, int $width, LayoutState $state, ?string $target, int $group): ?array
    {
        $file = self::file($image->source, $state->baseDir, $state->root);
        $graphics = $this->graphics;
        if (null === $graphics || !$graphics->available() || null === $file) {
            return null;
        }
        // The header is all a layout reads: the whole file only goes to the terminal once.
        $size = self::size((string) \file_get_contents($file, length: 24));
        if (null === $size) {
            return null;
        }
        [$columns, $rows] = $this->cells($size, $image->width, $width);
        $key = $file."\0".(int) \filemtime($file)."\0".$columns.'x'.$rows;
        $id = $this->ids[$key] ?? null;
        if (null === $id) {
            $id = $graphics->allocateId();
            $this->ids[$key] = $id;
            $graphics->transmit($id, (string) \file_get_contents($file));
        }
        $graphics->place($id, $columns, $rows);
        $this->used[$id] = true;
        $links = null === $target ? [] : [[0, $columns, $target, $group]];
        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $lines[] = new PageLine(KittyPlaceholders::row($id, $row, 0, $columns), $columns, 0, $links);
        }

        return $lines;
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
     * The local PNG $source names, or null for a remote, missing or other
     * file — or one outside the page's tree: a README's `../../..` or
     * absolute path never reads the reader's own files.
     */
    private static function file(string $source, ?string $baseDir, ?string $root): ?string
    {
        $path = \rawurldecode(\explode('?', \explode('#', $source)[0])[0]);
        if ('' === $path || 1 === \preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || null === $baseDir) {
            return null;
        }
        $file = \realpath(\str_starts_with($path, '/') ? ($root ?? $baseDir).$path : $baseDir.'/'.$path);
        $fence = \realpath($root ?? $baseDir);
        if (false === $file || false === $fence || !\str_starts_with($file, \rtrim($fence, characters: '/').'/') || !\is_file($file)) {
            return null;
        }

        return self::PNG === \file_get_contents($file, length: 8) ? $file : null;
    }

    /** @return array{int, int}|null a PNG's width and height, read from the IHDR chunk its first 24 bytes hold */
    private static function size(string $head): ?array
    {
        if (\strlen($head) < 24 || 'IHDR' !== \substr($head, offset: 12, length: 4)) {
            return null;
        }
        $dimensions = \unpack('Nwidth/Nheight', \substr($head, offset: 16, length: 8));
        if (false === $dimensions || $dimensions['width'] < 1 || $dimensions['height'] < 1) {
            return null;
        }

        return [(int) $dimensions['width'], (int) $dimensions['height']];
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
