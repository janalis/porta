<?php

declare(strict_types=1);

namespace Porta\Markdown;

/**
 * The source a document was parsed from, with its line starts indexed, so a
 * byte offset converts to the (line, byte column) pair the editor speaks.
 */
final readonly class SourceText
{
    /** @var list<int> */
    private array $lineStarts;

    public function __construct(
        public string $text,
    ) {
        $starts = [0];
        $offset = 0;
        while (false !== ($newline = \strpos($text, needle: "\n", offset: $offset))) {
            $offset = $newline + 1;
            $starts[] = $offset;
        }
        $this->lineStarts = $starts;
    }

    /** The 0-based line holding $offset. */
    public function lineOf(int $offset): int
    {
        $low = 0;
        $high = \count($this->lineStarts) - 1;
        while ($low < $high) {
            $mid = ($low + $high + 1) >> 1;
            $fits = $this->lineStarts[$mid] <= $offset;
            $low = $fits ? $mid : $low;
            $high = $fits ? $high : $mid - 1;
        }

        return $low;
    }

    /** The byte column of $offset on its line. */
    public function columnOf(int $offset): int
    {
        return $offset - $this->lineStarts[$this->lineOf($offset)];
    }

    /** The byte offset of a (line, byte column) pair, clamped into the text. */
    public function offsetOf(int $line, int $byteCol): int
    {
        $line = \max(0, \min($line, \count($this->lineStarts) - 1));

        return \min(\strlen($this->text), $this->lineStarts[$line] + \max(0, $byteCol));
    }

    public function slice(SourceRange $range): string
    {
        return \substr($this->text, $range->start, $range->length());
    }
}
