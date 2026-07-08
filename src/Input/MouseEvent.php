<?php

declare(strict_types=1);

namespace Porta\Input;

/**
 * A parsed SGR (1006) mouse event.
 *
 * Terminals in SGR mouse mode send `ESC [ < b ; x ; y M` (press/motion) or
 * `ESC [ < b ; x ; y m` (release), where x/y are 1-based screen columns and
 * rows. This value object exposes 0-based coordinates plus decoded button
 * semantics.
 */
final class MouseEvent
{
    public const int LEFT = 0;
    public const int MIDDLE = 1;
    public const int RIGHT = 2;

    private function __construct(
        public readonly int $button,
        public readonly int $col, // 0-based screen column
        public readonly int $row, // 0-based screen row
        public readonly bool $pressed, // true = press/motion (M), false = release (m)
        public readonly bool $motion,
        public readonly bool $wheel,
        public readonly int $wheelDelta, // -1 wheel up, +1 wheel down, 0 otherwise
        public readonly int $wheelHDelta, // -1 wheel left, +1 wheel right, 0 otherwise
        public readonly bool $shift, // Shift held (SGR modifier bit 4)
        public readonly bool $alt, // Alt/Option held (SGR modifier bit 8)
        public readonly bool $ctrl, // Ctrl held (SGR modifier bit 16)
    ) {}

    /**
     * Parse a single SGR mouse sequence; null when $data is not one.
     */
    public static function parse(string $data): ?self
    {
        $m = [];
        if (!preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $data, $m)) {
            return null;
        }

        $code = (int) $m[1];
        $col = max(0, (int) $m[2] - 1);
        $row = max(0, (int) $m[3] - 1);

        $motion = (bool) ($code & 32);
        $wheel = (bool) ($code & 64);
        // 64/65 = wheel up/down, 66/67 = wheel left/right (bit 2 is the axis);
        // shift+vertical is the mouse convention for horizontal scrolling.
        $horizontal = $wheel && (0 !== ($code & 2) || 0 !== ($code & 4));

        return new self(
            button: $wheel ? -1 : $code & 3,
            col: $col,
            row: $row,
            pressed: 'M' === $m[4],
            motion: $motion,
            wheel: $wheel,
            wheelDelta: $wheel && !$horizontal ? (0 === ($code & 1) ? -1 : 1) : 0,
            wheelHDelta: $horizontal ? (0 === ($code & 1) ? -1 : 1) : 0,
            shift: (bool) ($code & 4),
            alt: (bool) ($code & 8),
            ctrl: (bool) ($code & 16),
        );
    }

    /** A copy at a different screen column (visual→logical remap under RTL). */
    public function withColumn(int $col): self
    {
        return new self(
            $this->button,
            $col,
            $this->row,
            $this->pressed,
            $this->motion,
            $this->wheel,
            $this->wheelDelta,
            $this->wheelHDelta,
            $this->shift,
            $this->alt,
            $this->ctrl,
        );
    }

    public function isLeftPress(): bool
    {
        return self::LEFT === $this->button && $this->pressed && !$this->motion && !$this->wheel;
    }

    /** A right-button press (opens a context menu). */
    public function isRightPress(): bool
    {
        return self::RIGHT === $this->button && $this->pressed && !$this->motion && !$this->wheel;
    }

    /** A left-button drag: motion reported while the left button is held (mouse mode 1002/1003). */
    public function isLeftDrag(): bool
    {
        return self::LEFT === $this->button && $this->pressed && $this->motion && !$this->wheel;
    }

    /**
     * A bare hover-move: motion with no button held (mouse mode 1003 reports these
     * with button bits 3, i.e. "no button" — distinct from a left drag's button 0).
     */
    public function isMove(): bool
    {
        return 3 === $this->button && $this->motion && !$this->wheel;
    }

    /** The left button being released (an `m`-terminated report). */
    public function isLeftRelease(): bool
    {
        return self::LEFT === $this->button && !$this->pressed && !$this->wheel;
    }
}
