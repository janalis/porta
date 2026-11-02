<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

use Porta\Ui\Graphics\KittyPlaceholders;

/**
 * The pictures behind a frame's kitty placeholder cells, for {@see ScreenSvg}.
 *
 * A picture never travels in the frame: {@see \Porta\Ui\Graphics\TerminalGraphics}
 * transmits it (`a=t`) and places it (`a=p,U=1`) between frames, and the frame
 * only carries placeholder cells — U+10EEEE, a row and a column diacritic, in
 * a foreground colour that IS the image id. So the pictures are read from the
 * terminal's output up to the frame, and each placeholder cell says where its
 * piece of which one goes.
 */
final class KittyImages
{
    /** @var array<string, int>|null diacritic => the row or column number it stands for */
    private static ?array $numbers = null;

    /**
     * @param array<int, string>          $pngs  image id => its PNG
     * @param array<int, array{int, int}> $sizes image id => the placement's columns and rows
     */
    private function __construct(
        private readonly array $pngs,
        private readonly array $sizes,
    ) {}

    /** Every image $output transmitted and placed; the latest of each wins. */
    public static function fromOutput(string $output): self
    {
        $commands = [];
        \preg_match_all('/\x1b_G([^;\x1b]*)(?:;([^\x1b]*))?\x1b\\\\/', $output, $commands, \PREG_SET_ORDER);
        $pngs = [];
        $sizes = [];
        $id = null;
        $payload = '';
        foreach ($commands as $command) {
            $keys = self::keys($command[1]);
            $action = $keys['a'] ?? null;
            if ('p' === $action) {
                $sizes[(int) ($keys['i'] ?? 0)] = [(int) ($keys['c'] ?? 0), (int) ($keys['r'] ?? 0)];
                continue;
            }
            if ('t' === $action) {
                $id = (int) ($keys['i'] ?? 0);
                $payload = '';
            }
            if (null === $id || !\array_key_exists('m', $keys)) {
                continue;
            }
            $payload .= $command[2] ?? '';
            if ('0' === $keys['m']) {
                $pngs[$id] = (string) \base64_decode($payload, strict: true);
                $id = null;
            }
        }

        return new self($pngs, $sizes);
    }

    /**
     * The image and the cell of its placement a placeholder grapheme draws, or
     * null when $grapheme is not one.
     *
     * @return array{int, int, int}|null image id, placement row, placement column
     */
    public static function cell(string $grapheme, ?string $foreground): ?array
    {
        $cell = null;
        if (null !== $foreground && \str_starts_with($grapheme, KittyPlaceholders::BASE)) {
            $numbers = self::numbers();
            $marks = \mb_str_split(\substr($grapheme, \strlen(KittyPlaceholders::BASE)));
            $row = $numbers[$marks[0] ?? ''] ?? null;
            $column = $numbers[$marks[1] ?? ''] ?? null;
            if (null !== $row && null !== $column && 2 === \count($marks)) {
                $cell = [(int) \hexdec(\ltrim($foreground, characters: '#')), $row, $column];
            }
        }

        return $cell;
    }

    /** The PNG of image $id, if it was sent. */
    public function png(int $id): ?string
    {
        return $this->pngs[$id] ?? null;
    }

    /**
     * The columns and rows image $id is stretched over, if it was placed.
     *
     * @return array{int, int}|null
     */
    public function size(int $id): ?array
    {
        return $this->sizes[$id] ?? null;
    }

    /**
     * The diacritic table, read back through the public encoder rather than
     * copied: placement row $n's cell is its stand-in expanded.
     *
     * @return array<string, int>
     */
    private static function numbers(): array
    {
        if (null === self::$numbers) {
            self::$numbers = [];
            for ($n = 0; $n < KittyPlaceholders::MAX_CELLS; ++$n) {
                $cell = KittyPlaceholders::expand(\preg_replace('/\x1b\[[0-9;]*m/', replacement: '', subject: KittyPlaceholders::row(0, $n, firstColumn: 0, count: 1)) ?? '');
                self::$numbers[\mb_substr($cell, start: 1, length: 1)] = $n;
            }
        }

        return self::$numbers;
    }

    /** @return array<string, string> a command's `key=value` pairs */
    private static function keys(string $keys): array
    {
        $pairs = [];
        foreach (\explode(',', $keys) as $pair) {
            $sides = \explode('=', $pair, limit: 2);
            $pairs[$sides[0]] = $sides[1] ?? '';
        }

        return $pairs;
    }
}
