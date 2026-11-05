<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The order LSP text edits are applied in: bottom-up, so an edit never moves
 * the positions of those still to come — and, among edits that START at the
 * same position, the LATER in the array first. Each lands in front of the one
 * applied before it, so they read in array order, as the spec says they do:
 * applied in array order instead, two imports added on one line came out
 * swapped, and an insert beside a replace at the same spot mangled the text
 * (`foo(ya, x)` for `foo($a, y)`).
 */
final class TextEditOrder
{
    /**
     * @template T of array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}
     *
     * @param list<T> $edits
     *
     * @return list<T>
     */
    public static function bottomUp(array $edits): array
    {
        $order = \array_keys($edits);
        $at = static fn (int $index): array => [$edits[$index]['range']['start']['line'] ?? 0, $edits[$index]['range']['start']['character'] ?? 0, $index];
        \usort($order, static fn (int $a, int $b): int => $at($b) <=> $at($a));

        return \array_map(static fn (int $index): array => $edits[$index], $order);
    }
}
