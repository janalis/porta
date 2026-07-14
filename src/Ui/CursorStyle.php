<?php

declare(strict_types=1);

namespace Porta\Ui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

/**
 * The shared hardware-cursor shape holder, following the {@see SwitchablePalette}
 * pattern: every caret-emitting widget holds this and asks {@see marker()} for
 * the zero-width APC marker, so the `cursorShape` setting swaps the shape live
 * in one place. Screen readers follow the hardware cursor; the shape (bar,
 * block, underline) is a visibility preference.
 */
final class CursorStyle
{
    private CursorShape $shape;

    public function __construct(CursorShape $shape = CursorShape::Bar)
    {
        $this->shape = $shape;
    }

    public function switchTo(CursorShape $shape): void
    {
        $this->shape = $shape;
    }

    public function shape(): CursorShape
    {
        return $this->shape;
    }

    /** The APC cursor marker for the current shape (ScreenWriter turns it into the hardware cursor). */
    public function marker(): string
    {
        return AnsiUtils::cursorMarker($this->shape);
    }

    /** Map the `cursorShape` setting value to a shape (unknown values fall back to the bar). */
    public static function shapeFromSetting(string $value): CursorShape
    {
        return match ($value) {
            'block' => CursorShape::Block,
            'underline' => CursorShape::Underline,
            default => CursorShape::Bar,
        };
    }
}
