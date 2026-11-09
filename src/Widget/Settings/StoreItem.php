<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreRow;

/**
 * One thing on the store's shelf the keyboard can stop on, and where it was
 * drawn: a plugin's tile, a shelf's "See all", a category, Update all, the
 * install field or the scope select.
 *
 * Positions are in the shelf's body lines, before any scroll, so a click, a
 * hover and an arrow key all read the one map the last frame drew.
 */
final readonly class StoreItem
{
    /**
     * @param string                    $key    stable across frames: what the selection remembers
     * @param array{int, int, int, int}|null $button the item's button: body row, first column, width, height
     * @param int                       $reveal the first body row to bring into view with it — its shelf's heading
     */
    public function __construct(
        public string $key,
        public StoreItemKind $kind,
        public int $row,
        public int $col,
        public int $height,
        public int $width,
        public ?StoreRow $store = null,
        public ?string $plugin = null,
        public ?StoreCategory $category = null,
        public ?array $button = null,
        public int $reveal = -1,
    ) {}

    public function contains(int $row, int $col): bool
    {
        return $row >= $this->row && $row < ($this->row + $this->height) && $col >= $this->col && $col < ($this->col + $this->width);
    }

    public function onButton(int $row, int $col): bool
    {
        return self::within($this->button, $row, $col);
    }

    /**
     * Whether ($row, $col) is inside $zone.
     *
     * @param array{int, int, int, int}|null $zone row, column, width, height
     */
    public static function within(?array $zone, int $row, int $col): bool
    {
        return null !== $zone && $row >= $zone[0] && $row < ($zone[0] + $zone[3]) && $col >= $zone[1] && $col < ($zone[1] + $zone[2]);
    }

    /** The same item drawn $rows down and $cols across, brought into view from row $reveal when it was from its own heading. */
    public function moved(int $rows, int $cols, int $reveal): self
    {
        return new self(
            $this->key,
            $this->kind,
            $this->row + $rows,
            $this->col + $cols,
            $this->height,
            $this->width,
            $this->store,
            $this->plugin,
            $this->category,
            null === $this->button ? null : [$this->button[0] + $rows, $this->button[1] + $cols, $this->button[2], $this->button[3]],
            match (true) {
                // Its own reveal from the inner grid's top, or none on the first row: the moved-to heading.
                0 === $this->row, 0 === $this->reveal => $reveal,
                $this->reveal > 0 => $this->reveal + $rows,
                default => -1,
            },
        );
    }

    /** The first row to keep in view with it. */
    public function top(): int
    {
        return $this->reveal >= 0 ? \min($this->reveal, $this->row) : $this->row;
    }
}
