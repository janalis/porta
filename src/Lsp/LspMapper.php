<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\CompletionItem;
use Porta\Editor\Document;

/**
 * Pure translation between raw Language Server Protocol payloads and the shapes
 * the editor/UI consume. Stateless statics — no transport, no widgets — so the
 * mapping logic is unit-testable in isolation and shared by the LSP feature
 * controllers.
 */
final class LspMapper
{
    public const array COMPLETION_KINDS = [
        1 => 'text',
        2 => 'method',
        3 => 'fn',
        4 => 'ctor',
        5 => 'field',
        6 => 'var',
        7 => 'class',
        8 => 'iface',
        9 => 'module',
        10 => 'prop',
        11 => 'unit',
        12 => 'value',
        13 => 'enum',
        14 => 'keyword',
        15 => 'snippet',
        16 => 'color',
        17 => 'file',
        18 => 'ref',
        19 => 'folder',
        20 => 'enum',
        21 => 'const',
        22 => 'struct',
        23 => 'event',
        24 => 'op',
        25 => 'type',
    ];

    public const array SYMBOL_KINDS = [
        1 => 'file',
        2 => 'module',
        3 => 'namespace',
        4 => 'package',
        5 => 'class',
        6 => 'method',
        7 => 'property',
        8 => 'field',
        9 => 'ctor',
        10 => 'enum',
        11 => 'iface',
        12 => 'fn',
        13 => 'var',
        14 => 'const',
        15 => 'string',
        16 => 'number',
        17 => 'bool',
        18 => 'array',
        19 => 'object',
        20 => 'key',
        21 => 'null',
        22 => 'enummember',
        23 => 'struct',
        24 => 'event',
        25 => 'op',
        26 => 'typeparam',
    ];

    /**
     * Map raw LSP CompletionItem[] to the editor's popup items, sorted by
     * sortText/label and capped at 80 entries.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<CompletionItem>
     */
    public static function mapCompletionItems(array $items): array
    {
        usort($items, static fn (array $a, array $b) => (string) ($a['sortText'] ?? $a['label'] ?? '') <=> (string) ($b['sortText'] ?? $b['label'] ?? ''));

        $mapped = [];
        foreach (\array_slice($items, offset: 0, length: 80) as $item) {
            $label = (string) ($item['label'] ?? '');
            if ('' === $label) {
                continue;
            }

            $raw = (string) ($item['textEdit']['newText'] ?? $item['insertText'] ?? $label);
            $isSnippet = 2 === ($item['insertTextFormat'] ?? 1);

            // Plain insert text (used when not expanding as a snippet): strip
            // placeholders ${1:foo} → foo, $0 → ''.
            $insert = $raw;
            if ($isSnippet) {
                $insert = preg_replace('/\$\{\d+:?([^}]*)}/', replacement: '$1', subject: $insert) ?? $insert;
                $insert = preg_replace('/\$\d+/', replacement: '', subject: $insert) ?? $insert;
            }

            // additionalTextEdits carry auto-import / use-statement insertions that
            // must be applied alongside the completion; when absent, the server may
            // still deliver them via completionItem/resolve on acceptance.
            /** @var list<array<array-key, mixed>> $additional */
            $additional = \is_array($item['additionalTextEdits'] ?? null) ? $item['additionalTextEdits'] : [];

            $mapped[] = new CompletionItem(
                label: $label,
                insertText: $insert,
                kind: self::COMPLETION_KINDS[$item['kind'] ?? 0] ?? '',
                detail: (string) ($item['detail'] ?? ''),
                additionalTextEdits: $additional,
                snippet: $isSnippet ? $raw : null,
                raw: $item,
            );
        }

        return $mapped;
    }

    /**
     * Group inlay hints by (0-based) line, converting UTF-16 columns to byte
     * columns against the given document.
     *
     * @param list<array<string, mixed>> $hints
     *
     * @return array<int, list<array{col: int, text: string}>>
     */
    public static function mapInlayHints(Document $doc, array $hints): array
    {
        $byLine = [];
        foreach ($hints as $hint) {
            if (($hint['position']['line'] ?? null) === null || ($hint['position']['character'] ?? null) === null) {
                continue;
            }
            /** @var scalar|array<array-key, mixed> $label */
            $label = $hint['label'] ?? '';
            if (\is_array($label)) {
                // label is an InlayHintLabelPart[]; concatenate the parts' text.
                $label = implode('', array_map(static fn ($part) => \is_array($part) ? (string) ($part['value'] ?? '') : (string) $part, $label));
            }
            $label = trim((string) $label);
            if ('' === $label) {
                continue;
            }
            $line = (int) $hint['position']['line'];
            $byLine[$line][] = [
                'col' => $doc->utf16ColToByteCol($line, (int) $hint['position']['character']),
                'text' => $label,
            ];
        }

        return $byLine;
    }

    /**
     * Flatten a Hover result's contents (string | MarkedString | MarkupContent,
     * optionally an array of them) to plain text, or null when empty.
     */
    public static function hoverToText(mixed $result): ?string
    {
        /** @var scalar|array{value?: mixed}|list<scalar|array{value?: mixed}>|null $contents */
        $contents = \is_array($result) ? $result['contents'] ?? null : null;
        if (null === $contents) {
            return null;
        }

        $parts = [];
        /** @var mixed $chunk each chunk is untrusted wire data — the guards below are the narrowing */
        foreach (\is_array($contents) && array_is_list($contents) ? $contents : [$contents] as $chunk) {
            if (\is_string($chunk)) {
                $parts[] = $chunk;
                continue;
            }
            if (\is_array($chunk)) {
                $parts[] = (string) ($chunk['value'] ?? '');
            }
        }

        $text = trim(implode("\n", $parts));
        if ('' === $text) {
            return null;
        }
        // Light markdown cleanup: drop code fences, collapse blank runs
        $text = preg_replace('/^```\w*$/m', replacement: '', subject: $text) ?? $text;

        return preg_replace("/\n{3,}/", replacement: "\n\n", subject: trim($text)) ?? $text;
    }

    /**
     * Normalize a definition-style result (Location | Location[] | LocationLink[])
     * to a list of {uri, range}.
     *
     * @return list<array{uri: string, range: array}>
     */
    public static function allLocations(mixed $result): array
    {
        if (!\is_array($result) || [] === $result) {
            return [];
        }
        /** @var list<array{uri?: string, range?: array<array-key, mixed>, targetUri?: string, targetSelectionRange?: array<array-key, mixed>, targetRange?: array<array-key, mixed>}|scalar|null> $candidates */
        $candidates = array_is_list($result) ? $result : [$result];
        $out = [];
        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            if (($candidate['uri'] ?? null) !== null && ($candidate['range'] ?? null) !== null) {
                $out[] = ['uri' => $candidate['uri'], 'range' => $candidate['range']];
                continue;
            }
            if (($candidate['targetUri'] ?? null) !== null) {
                $out[] = [
                    'uri' => $candidate['targetUri'],
                    'range' => $candidate['targetSelectionRange'] ?? $candidate['targetRange'] ?? [],
                ];
            }
        }

        return $out;
    }

    /**
     * Whether the UTF-16 position (line, col) lies within an LSP range,
     * end-inclusive: a caret sitting immediately after the identifier's last
     * character still counts as "on" it (PhpStorm's reading). Missing range
     * parts default to 0.
     *
     * @param array<array-key, mixed> $range an LSP Range ({start, end} of {line, character})
     */
    public static function rangeContains(array $range, int $line, int $utf16Col): bool
    {
        $start = \is_array($range['start'] ?? null) ? $range['start'] : [];
        $end = \is_array($range['end'] ?? null) ? $range['end'] : [];
        $startLine = (int) ($start['line'] ?? 0);
        $startCol = (int) ($start['character'] ?? 0);
        $endLine = (int) ($end['line'] ?? 0);
        $endCol = (int) ($end['character'] ?? 0);

        return ($line > $startLine || $line === $startLine && $utf16Col >= $startCol) && ($line < $endLine || $line === $endLine && $utf16Col <= $endCol);
    }

    /**
     * The first normalized location of a definition result — exactly
     * {@see allLocations()}[0]. One normalization, not two hand-maintained
     * copies: the old duplicate returned null when a list's LEADING element
     * was malformed even if a later one was valid, a divergence that was
     * accidental and only ever hurt (peek flashed not-found on a partially
     * broken answer).
     *
     * @return array{uri: string, range: array}|null
     */
    public static function firstLocation(mixed $result): ?array
    {
        return self::allLocations($result)[0] ?? null;
    }

    /**
     * Recursively flatten a DocumentSymbol[]/SymbolInformation[] tree into
     * indented file-structure rows.
     *
     * @param list<array<array-key, mixed>|scalar|null>              $symbols
     * @param list<array{label: string, hint: string, value: string}> $items    accumulator (by reference)
     */
    public static function flattenSymbols(array $symbols, int $depth, array &$items): void
    {
        foreach ($symbols as $symbol) {
            if (!\is_array($symbol) || '' === (string) ($symbol['name'] ?? '')) {
                continue;
            }
            // DocumentSymbol uses selectionRange; SymbolInformation uses location.range
            /** @var array<array-key, mixed> $range */
            $range = $symbol['selectionRange'] ?? $symbol['range'] ?? $symbol['location']['range'] ?? ['start' => ['line' => 0, 'character' => 0]];
            /** @var array<array-key, mixed> $start */
            $start = $range['start'] ?? ['line' => 0, 'character' => 0];

            $items[] = [
                'label' => str_repeat('  ', max(0, $depth)).(string) $symbol['name'],
                'hint' => self::SYMBOL_KINDS[$symbol['kind'] ?? 0] ?? '',
                'value' => (int) ($start['line'] ?? 0).':'.(int) ($start['character'] ?? 0),
            ];

            if (\is_array($symbol['children'] ?? null)) {
                /** @var list<array<array-key, mixed>|scalar|null> $children */
                $children = $symbol['children'];
                self::flattenSymbols($children, $depth + 1, $items);
            }
        }
    }

    /**
     * Group textDocument/publishDiagnostics params into the editor's per-line
     * diagnostic shape, sorted by line. Multi-line ranges keep PHP_INT_MAX as
     * the end column so the whole first line is underlined.
     *
     * @param array{
     *     uri?: string,
     *     diagnostics?: list<array{
     *         severity?: int,
     *         message?: string,
     *         range: array{
     *             start: array{line?: int, character?: int},
     *             end: array{line?: int, character?: int},
     *         },
     *     }>,
     * } $params
     *
     * @return array<int, list<array{severity: int, message: string, col: int, endCol: int}>>
     */
    public static function diagnosticsByLine(array $params): array
    {
        $byLine = [];
        foreach ($params['diagnostics'] ?? [] as $diag) {
            $line = $diag['range']['start']['line'] ?? 0;
            $sameLine = ($diag['range']['end']['line'] ?? $line) === $line;
            $byLine[$line][] = [
                'severity' => (int) ($diag['severity'] ?? 1),
                'message' => trim((string) preg_replace('/\s+/', replacement: ' ', subject: $diag['message'] ?? '')),
                'col' => $diag['range']['start']['character'] ?? 0,
                'endCol' => $sameLine ? $diag['range']['end']['character'] ?? 0 : \PHP_INT_MAX,
            ];
        }
        ksort($byLine);

        return $byLine;
    }
}
