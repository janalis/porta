<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Flattens the results panel's `{path,line,text}` list into the display rows the
 * {@see ResultsPanelWidget} renders and navigates. In flat mode every result is
 * one match row (unchanged behaviour for the non-search panels). In grouped mode
 * (find-in-project) results are grouped by file, emitting a collapsible file
 * header — `path (count)` — followed by its match rows when expanded.
 *
 * A pure builder so grouping/collapse logic is unit-tested without the widget.
 */
final class ResultsView
{
    /**
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results
     * @param array<string, bool>                                                                                $collapsed path => collapsed
     *
     * @return list<DisplayRow>
     */
    public static function build(array $results, array $collapsed, bool $grouped): array
    {
        if (!$grouped) {
            $rows = [];
            foreach (array_keys($results) as $index) {
                $rows[] = DisplayRow::match($index);
            }

            return $rows;
        }

        // Group indices by path, preserving grep's first-seen file order.
        $byPath = [];
        foreach ($results as $index => $result) {
            $byPath[$result['path']] ??= [];
            $byPath[$result['path']][] = $index;
        }

        $rows = [];
        foreach ($byPath as $path => $indices) {
            $isCollapsed = $collapsed[$path] ?? false;
            $rows[] = DisplayRow::fileHeader($path, \count($indices), $isCollapsed);
            if (!$isCollapsed) {
                foreach ($indices as $index) {
                    $rows[] = DisplayRow::match($index);
                }
            }
        }

        return $rows;
    }
}
