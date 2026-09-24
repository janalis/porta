<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/**
 * Where a list's selection may rest: never on a disabled row — one there to
 * be read, as a menu's greyed item is. A move that wraps (the arrows, the
 * wheel) walks past such rows round the ends; one that stops at the ends (a
 * page, a frame cue) settles on the nearest live row short of them.
 */
final class LiveRows
{
    /**
     * $at, or the nearest row from it in $direction (wrapping) that is not
     * disabled — the selection never rests on a row that is there to be read,
     * as a menu's never rests on a greyed item. $at itself when every row is.
     *
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool}> $items
     */
    public static function from(array $items, int $at, int $direction): int
    {
        $count = \count($items);
        $step = $direction < 0 ? -1 : 1;
        for ($i = 0; $i < $count; ++$i) {
            $candidate = ((($at + ($i * $step)) % $count) + $count) % $count;
            if (true !== ($items[$candidate]['disabled'] ?? null)) {
                return $candidate;
            }
        }

        return $at;
    }

    /**
     * For a move that stops at the ends (a page, a frame cue): $at, else the
     * nearest live row in $direction short of the end, else the nearest back
     * the other way — paging to the top lands on the first live row, not
     * round the corner on the last.
     *
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool}> $items
     */
    public static function within(array $items, int $at, int $direction): int
    {
        $step = $direction < 0 ? -1 : 1;
        foreach ([$step, -$step] as $way) {
            for ($i = $at; $i >= 0 && $i < \count($items); $i += $way) {
                if (true !== ($items[$i]['disabled'] ?? null)) {
                    return $i;
                }
            }
        }

        return $at;
    }
}
