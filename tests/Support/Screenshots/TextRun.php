<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

use Symfony\Component\Tui\Ansi\AnsiUtils;

/** Cells of one ink, side by side on a {@see ScreenSvg} row: one `<text>` in the making. */
final class TextRun
{
    public function __construct(
        public readonly int $column,
        public readonly Style $style,
        public string $text,
    ) {}

    /** Whether a cell of $style at $column carries this run on. */
    public function continuesWith(Style $style, int $column): bool
    {
        return $this->style->sameInk($style) && ($this->column + AnsiUtils::visibleWidth($this->text)) === $column;
    }
}
