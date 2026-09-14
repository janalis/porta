<?php

declare(strict_types=1);

namespace Porta\Workbench;

/**
 * Maps a bounded time/text ring (notification log, LSP log) onto the results
 * panel's rows, newest first. The empty path marks the rows as location-less
 * (inert jump, no `path:line` accent) — which is also what makes a live
 * refresh re-select the first row ({@see \Porta\Widget\ResultsPanelWidget::refreshResults}
 * preserves located selections by identity but deliberately re-selects
 * newest for location-less rows), so the latest line stays visible on every
 * append.
 */
final class LogRows
{
    /**
     * @param list<array{time: \DateTimeImmutable, text: string}> $entries oldest first
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    public static function build(array $entries): array
    {
        return \array_map(
            static fn (array $entry): array => ['path' => '', 'line' => 0, 'text' => $entry['time']->format('H:i:s').'  '.$entry['text']],
            \array_reverse($entries),
        );
    }
}
