<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Which lines carry PhpStorm's method separator — the thin rule that closes off
 * one member before the next begins.
 *
 * The rule goes UNDER a member's last line, which is what a graphical editor
 * draws: a border along the bottom edge of the closing brace. Underlining that
 * row puts the rule in the same place, so it costs no screen row and no width.
 *
 * Doing it that way, rather than above the member that follows, is also the
 * only version that survives docblocks and attributes: the line above a method
 * is usually its own `*​/` or `#[Override]`, and a rule through those reads as
 * a mistake.
 *
 * Reads the same scopes the breadcrumb trail and the sticky band do, so it
 * works in every language those do — the offline PHP scan, or the language
 * server's symbols.
 */
final class MemberSeparators
{
    /**
     * @param list<array{label: string, startLine: int, endLine: int, kind: string}> $scopes
     *
     * @return array<int, true> 0-based lines to rule, keyed for a per-row lookup
     */
    public static function linesFor(array $scopes): array
    {
        $lines = [];
        foreach ($scopes as $scope) {
            // Functions and methods only. A rule under every class would just
            // underline the file's last brace, and under a namespace, nothing.
            if ('function' !== $scope['kind']) {
                continue;
            }

            // The scanner counts lines from one and the document from zero.
            $lines[$scope['endLine'] - 1] = true;
        }

        return $lines;
    }
}
