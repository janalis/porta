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
        $masked = (string) \preg_replace_callback(self::NOT_NODE_IDS, static fn (array $m): string => (string) \preg_replace('/[^\n]/', replacement: ' ', subject: $m[0]), $dot);
        $found = [];
        $m = [];
        $quoted = \strpos($masked, '"'.\addcslashes($name, characters: '"\\').'"');
        if (false !== $quoted) {
            $found[] = $quoted;
        }
        if (1 === \preg_match('~(?<![\w\x80-\xff"])'.\preg_quote($name, delimiter: '~').'(?![\w\x80-\xff"])~', $masked, $m, \PREG_OFFSET_CAPTURE)) {
            $found[] = (int) $m[0][1];
        }

        return [] === $found ? null : \min($found);
    }
}
