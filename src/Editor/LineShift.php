<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Where an edit changed a text's lines, measured by comparing the lines
 * before and after it: the lines both share at the start and at the end were
 * untouched, so what is between them is the edit — exactly, wherever the caret
 * is. An auto-import adds its `use` line at the top while the caret stays far
 * below, and a guess from the caret would put the edit there.
 */
final readonly class LineShift
{
    private function __construct(
        /** The first line the edit touched. */
        public int $from,
        /** Where the untouched tail began in the OLD text. */
        public int $oldEnd,
        /** Lines added (negative: removed). */
        public int $delta,
    ) {}

    /**
     * @param array<array-key, string> $before a Document's lines, numbered from 0
     * @param array<array-key, string> $after
     */
    public static function between(array $before, array $after): self
    {
        $old = \count($before);
        $new = \count($after);
        $shared = \min($old, $new);
        $head = 0;
        while ($head < $shared && $before[$head] === $after[$head]) {
            ++$head;
        }
        $tail = 0;
        while ($tail < ($shared - $head) && $before[$old - 1 - $tail] === $after[$new - 1 - $tail]) {
            ++$tail;
        }

        return new self($head, $old - $tail, $new - $old);
    }

    /** Where OLD line $line is now: moved past the edit, or held to its first line when the edit took it. */
    public function map(int $line): int
    {
        return match (true) {
            $line < $this->from => $line,
            $line >= $this->oldEnd => $line + $this->delta,
            default => \min($line, \max($this->from, $this->oldEnd + $this->delta - 1)),
        };
    }
}
