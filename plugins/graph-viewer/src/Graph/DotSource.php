<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * Where a DOT text writes a node — what click-to-source needs from a graph:
 * Graphviz names the node that was clicked, and the reader wants the line
 * that declared it.
 *
 * The first place the node's id appears as an id: bare (`A -> B`) or quoted
 * (`"hello world" -> B`), whichever comes first. What is not a node id is
 * masked out before looking, keeping every offset where it was: comments,
 * attribute values (`label="A"` names no node), and the graph's own name
 * (`digraph A {`).
 */
final class DotSource
{
    /** Comments, `= "value"` attribute strings, and the id right after `graph`/`digraph`/`subgraph`. */
    private const string NOT_NODE_IDS = '~/\*.*?\*/|//[^\n]*|^[ \t]*#[^\n]*|=\s*"(?:[^"\\\\]|\\\\.)*"|\b(?:di|sub)?graph\s+(?:"(?:[^"\\\\]|\\\\.)*"|[\w\x80-\xff]+)~msi';

    /** The byte offset of $name's first use as a node id in $dot, or null when it is never written. */
    public static function declarationOf(string $dot, string $name): ?int
    {
        if ('' === $name) {
            return null;
        }
        $found = \array_column(self::uses(self::masked($dot), $name), 0);

        return [] === $found ? null : \min($found);
    }

    /**
     * Which of $names the text writes nearest byte column $col of line $line
     * — the node the caret is on, or beside — or null when the line names
     * none of them. The other half of {@see declarationOf()}: the caret moved,
     * and the graph shows the node it is on.
     *
     * @param list<string> $names
     */
    public static function nodeAt(string $dot, array $names, int $line, int $col): ?string
    {
        $text = \explode("\n", self::masked($dot))[$line] ?? '';
        $best = null;
        $bestDistance = \PHP_INT_MAX;
        foreach ($names as $name) {
            foreach ('' === $name ? [] : self::uses($text, $name) as [$start, $end]) {
                $distance = $col < $start ? $start - $col : \max(0, $col - $end + 1);
                if ($distance < $bestDistance) {
                    [$best, $bestDistance] = [$name, $distance];
                }
            }
        }

        return $best;
    }

    /** $dot with everything that is not a node id blanked, every offset kept. */
    private static function masked(string $dot): string
    {
        return (string) \preg_replace_callback(self::NOT_NODE_IDS, static fn (array $m): string => (string) \preg_replace('/[^\n]/', replacement: ' ', subject: $m[0]), $dot);
    }

    /**
     * Every place $text writes $name as an id, bare or quoted, as [start, end) byte spans.
     *
     * @return list<array{int, int}>
     */
    private static function uses(string $text, string $name): array
    {
        $m = [];
        \preg_match_all(
            '~"'.\preg_quote(\addcslashes($name, characters: '"\\'), delimiter: '~').'"|(?<![\w\x80-\xff"])'.\preg_quote($name, delimiter: '~').'(?![\w\x80-\xff"])~',
            $text,
            $m,
            \PREG_OFFSET_CAPTURE,
        );

        $spans = [];
        foreach ($m[0] ?? [] as $use) {
            $spans[] = [(int) $use[1], (int) $use[1] + \strlen($use[0])];
        }

        return $spans;
    }
}
