<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The drag-reorder arithmetic shared by every reorderable strip — terminal
 * sessions, dock tool tabs, open buffers. Each hand-rolled the same clamp +
 * `array_splice` + active-index fix-up; resolving it once here keeps a reorder
 * behaving identically wherever a tab (or session, or buffer) is dragged.
 */
final class IndexMove
{
    /**
     * The clamped destination for moving the element at $from within a
     * $count-length list, or null when the move is a no-op — $from out of range,
     * or the element is already at (the clamped) $to.
     */
    public static function target(int $from, int $to, int $count): ?int
    {
        if ($from < 0 || $from >= $count) {
            return null;
        }
        $to = IntMath::clamp($to, 0, $count - 1);

        return $from === $to ? null : $to;
    }

    /**
     * Splice the element at $from out and back in at $to (a clamped, real target
     * from {@see target()}), returning the reordered, re-indexed list.
     *
     * @template T
     * @param list<T> $list
     * @return list<T>
     */
    public static function apply(array $list, int $from, int $to): array
    {
        $moved = $list[$from];
        \array_splice($list, $from, length: 1);
        \array_splice($list, $to, length: 0, replacement: [$moved]);

        return $list;
    }

    /**
     * Where an active pointer at $active lands after the element at $from moves
     * to $to, so the active tab/session stays the same object across a reorder.
     */
    public static function reindexActive(int $active, int $from, int $to): int
    {
        return match (true) {
            $active === $from => $to,
            $from < $active && $active <= $to => $active - 1,
            $to <= $active && $active < $from => $active + 1,
            default => $active,
        };
    }
}
