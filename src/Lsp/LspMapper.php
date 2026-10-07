<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\CodeScopeKind;
use Porta\Editor\CompletionItem;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintKind;

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
        // An enum's member is a `case` — what PHP spells it — not the enum
        // itself: the two shared one word, so a popup of both read as twins.
        20 => 'case',
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
        \usort($items, static fn (array $a, array $b): int => (string) ($a['sortText'] ?? $a['label'] ?? '') <=> (string) ($b['sortText'] ?? $b['label'] ?? ''));

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
                $insert = \preg_replace('/\$\{\d+:?([^}]*)}/', replacement: '$1', subject: $insert) ?? $insert;
                $insert = \preg_replace('/\$\d+/', replacement: '', subject: $insert) ?? $insert;
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
                documentation: self::documentationText($item['documentation'] ?? null) ?? '',
                deprecated: CompletionDeprecation::of($item),
            );
        }

        return $mapped;
    }

    /**
     * `textDocument/inlineValue` → where each variable sits and what it is
     * called.
     *
     * Only `InlineValueVariableLookup` entries are read — the ones carrying a
     * `variableName`. The spec's other two kinds hand back TEXT the server has
     * already rendered (`InlineValueText`) or an EXPRESSION for the debugger to
     * evaluate (`InlineValueEvaluatableExpression`); neither is a name the
     * variables of a stopped frame can be looked up by, which is the only join
     * this client can make. phpactor sends lookups.
     *
     * The end column is where the value goes — after the variable, not before
     * it — and a lookup whose range spans lines is dropped rather than clipped,
     * because a variable is one identifier and a multi-line range is not one.
     *
     * @return list<array{line: int, endCol: int, name: string}>
     */
    public static function inlineValueLookups(mixed $result): array
    {
        $lookups = [];
        /** @var mixed $entry each entry is untrusted wire data — the guards below are the narrowing */
        foreach (\is_array($result) ? $result : [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            /** @var mixed $name */
            $name = $entry['variableName'] ?? null;
            /** @var mixed $line */
            $line = $entry['range']['start']['line'] ?? null;
            if (!\is_string($name) || '' === $name || !\is_int($line) || ($entry['range']['end']['line'] ?? $line) !== $line) {
                continue;
            }
            $lookups[] = ['line' => $line, 'endCol' => (int) ($entry['range']['end']['character'] ?? 0), 'name' => $name];
        }

        return $lookups;
    }

    /**
     * A completion item's `documentation`, flattened to text.
     *
     * The same `string | MarkupContent` shape hover's `contents` uses, so it
     * goes through the same reader rather than a second one that would drift
     * from it — including the markdown cleanup, which a terminal needs for both.
     */
    public static function documentationText(mixed $documentation): ?string
    {
        return null === $documentation ? null : self::hoverToText(['contents' => $documentation]);
    }

    /**
     * Map `textDocument/documentHighlight` onto the editor's occurrence spans.
     * DocumentHighlightKind: 1 = Text, 2 = Read, 3 = Write; anything but Write
     * paints as a read, because a server that cannot tell them apart sends
     * Text and guessing "write" there would colour reads wrongly.
     *
     * Multi-line ranges are dropped rather than clipped: an occurrence is one
     * identifier, so a range spanning lines is not one and highlighting its
     * first line would be a lie.
     *
     * @param list<array<string, mixed>> $highlights
     *
     * @return list<array{0: int, 1: int, 2: int, 3: bool}> [line, startDisplayCol, endDisplayCol, isWrite]
     */
    public static function mapDocumentHighlights(Document $doc, array $highlights): array
    {
        $spans = [];
        foreach ($highlights as $highlight) {
            if (null === ($highlight['range']['start']['line'] ?? null) || null === ($highlight['range']['end']['line'] ?? null)) {
                continue;
            }
            $line = (int) $highlight['range']['start']['line'];
            if ((int) $highlight['range']['end']['line'] !== $line) {
                continue;
            }
            $start = $doc->utf16ColToByteCol($line, (int) ($highlight['range']['start']['character'] ?? 0));
            $end = $doc->utf16ColToByteCol($line, (int) ($highlight['range']['end']['character'] ?? 0));
            if ($end <= $start) {
                continue;
            }
            $spans[] = [$line, $doc->displayColAt($line, $start), $doc->displayColAt($line, $end), 3 === ($highlight['kind'] ?? 1)];
        }

        return $spans;
    }

    /**
     * Group inlay hints by (0-based) line, converting UTF-16 columns to byte
     * columns against the given document.
     * The label keeps the margins the server asked for ({@see InlayHintLabel})
     * and the hint its kind — a kind this editor does not know is none.
     *
     * @param list<array<string, mixed>> $hints
     *
     * @return array<int, list<InlayHint>>
     */
    public static function mapInlayHints(Document $doc, array $hints): array
    {
        $byLine = [];
        foreach ($hints as $hint) {
            if (($hint['position']['line'] ?? null) === null || ($hint['position']['character'] ?? null) === null) {
                continue;
            }
            $label = InlayHintLabel::of($hint);
            if (null === $label) {
                continue;
            }
            $line = (int) $hint['position']['line'];
            $byLine[$line][] = new InlayHint(
                $doc->utf16ColToByteCol($line, (int) $hint['position']['character']),
                $label['text'],
                InlayHintKind::tryFrom((int) ($hint['kind'] ?? 0)),
                $label['left'],
                $label['right'],
            );
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
        foreach (\is_array($contents) && \array_is_list($contents) ? $contents : [$contents] as $chunk) {
            if (\is_string($chunk)) {
                $parts[] = $chunk;
                continue;
            }
            if (\is_array($chunk)) {
                $parts[] = (string) ($chunk['value'] ?? '');
            }
        }

        $text = HoverMarkdown::plain(\implode("\n", $parts));

        return '' === \trim($text) ? null : $text;
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
        $candidates = \array_is_list($result) ? $result : [$result];
        $out = [];
        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            $uri = $candidate['uri'] ?? null;
            $range = $candidate['range'] ?? null;
            if (null !== $uri && null !== $range) {
                $out[] = ['uri' => $uri, 'range' => $range];
                continue;
            }
            $targetUri = $candidate['targetUri'] ?? null;
            if (null !== $targetUri) {
                $out[] = [
                    'uri' => $targetUri,
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
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool}> $items    accumulator (by reference)
     */
    public static function flattenSymbols(array $symbols, int $depth, array &$items): void
    {
        foreach (self::outline($symbols, $depth) as $symbol) {
            $items[] = [
                'label' => \str_repeat('  ', \max(0, $symbol['depth'])).$symbol['name'],
                'hint' => self::SYMBOL_KINDS[$symbol['kind']] ?? '',
                'value' => $symbol['line'].':'.$symbol['column'],
            ];
        }
    }

    /**
     * A `textDocument/documentSymbol` answer — a DocumentSymbol tree or flat
     * SymbolInformation — as rows in document order, a parent before its
     * children, each with how deep it is nested. The position is where the
     * symbol is NAMED (`selectionRange`), which is where a caret goes to it.
     *
     * @param list<array<array-key, mixed>|scalar|null> $symbols
     *
     * @return list<array{name: string, kind: int, line: int, column: int, depth: int}>
     */
    public static function outline(array $symbols, int $depth = 0): array
    {
        $rows = [];
        foreach ($symbols as $symbol) {
            if (!\is_array($symbol) || '' === (string) ($symbol['name'] ?? '')) {
                continue;
            }
            // DocumentSymbol uses selectionRange; SymbolInformation uses location.range
            /** @var array<array-key, mixed> $range */
            $range = $symbol['selectionRange'] ?? $symbol['range'] ?? $symbol['location']['range'] ?? ['start' => ['line' => 0, 'character' => 0]];
            /** @var array<array-key, mixed> $start */
            $start = $range['start'] ?? ['line' => 0, 'character' => 0];
            $rows[] = [
                'name' => (string) $symbol['name'],
                'kind' => (int) ($symbol['kind'] ?? 0),
                'line' => (int) ($start['line'] ?? 0),
                'column' => (int) ($start['character'] ?? 0),
                'depth' => $depth,
            ];
            if (\is_array($symbol['children'] ?? null)) {
                /** @var list<array<array-key, mixed>|scalar|null> $children */
                $children = $symbol['children'];
                $rows = [...$rows, ...self::outline($children, $depth + 1)];
            }
        }

        return $rows;
    }

    /**
     * Which scope kind a `SymbolKind` counts as for the breadcrumbs trail, or
     * null for a symbol that is not a scope you can be INSIDE.
     *
     * A property, a constant or a variable is a member, not a container: a
     * caret is never "in" one in the sense the trail means, and listing them
     * would make every line of a class body its own crumb.
     */
    private const array SCOPE_KINDS = [
        2 => CodeScopeKind::Namespace, // module
        3 => CodeScopeKind::Namespace,
        4 => CodeScopeKind::Namespace, // package
        5 => CodeScopeKind::Type, // class
        10 => CodeScopeKind::Type, // enum
        11 => CodeScopeKind::Type, // interface
        23 => CodeScopeKind::Type, // struct
        6 => CodeScopeKind::Function, // method
        9 => CodeScopeKind::Function, // constructor
        12 => CodeScopeKind::Function,
    ];

    /**
     * A `textDocument/documentSymbol` tree as the breadcrumb scopes shape —
     * the same one {@see \Porta\Editor\BreadcrumbScanner::scan()} produces, so
     * `select()` and `stickyHeaders()` work over it unchanged and non-PHP files
     * get both for the first time.
     *
     * Two differences from the flattening {@see flattenSymbols()} does, and both
     * are the point: the symbol's FULL `range` is kept rather than the
     * `selectionRange` that names it, because a trail asks which scopes CONTAIN
     * the caret; and the tree walk is depth-first so a parent always precedes
     * its children, which is the outermost-first order `select()` relies on.
     *
     * @param list<array<array-key, mixed>|scalar|null> $symbols
     *
     * @return list<array{label: string, startLine: int, endLine: int, kind: CodeScopeKind}>
     */
    public static function symbolScopes(array $symbols): array
    {
        $scopes = [];
        self::collectScopes($symbols, $scopes);

        return $scopes;
    }

    /**
     * @param list<array<array-key, mixed>|scalar|null>                              $symbols
     * @param list<array{label: string, startLine: int, endLine: int, kind: CodeScopeKind}> $scopes
     */
    private static function collectScopes(array $symbols, array &$scopes): void
    {
        foreach ($symbols as $symbol) {
            if (!\is_array($symbol)) {
                continue;
            }

            // Checked, not cast: a non-string name casts to "Array" (with a
            // warning) and becomes a crumb labelled exactly that.
            $name = \is_string($symbol['name'] ?? null) ? $symbol['name'] : '';
            $kind = self::SCOPE_KINDS[$symbol['kind'] ?? 0] ?? null;
            // DocumentSymbol carries `range`; the older SymbolInformation puts
            // it under `location`. Both are in the wild, so both are read.
            $range = match (true) {
                \is_array($symbol['range'] ?? null) => $symbol['range'],
                \is_array($symbol['location']['range'] ?? null) => $symbol['location']['range'],
                default => [],
            };
            $start = \is_array($range['start'] ?? null) ? $range['start'] : [];
            $end = \is_array($range['end'] ?? null) ? $range['end'] : [];
            $from = (int) ($start['line'] ?? -1);
            $to = (int) ($end['line'] ?? -1);

            // A symbol with no usable range cannot say whether it contains the
            // caret; one that is not a container is not a crumb. Either way its
            // CHILDREN may still be both, so the walk continues.
            if ('' !== $name && null !== $kind && $from >= 0 && $to >= $from) {
                // LSP counts lines from zero; the scopes shape counts from one.
                $scopes[] = ['label' => $name, 'startLine' => $from + 1, 'endLine' => $to + 1, 'kind' => $kind];
            }

            if (\is_array($symbol['children'] ?? null)) {
                /** @var list<array<array-key, mixed>|scalar|null> $children */
                $children = $symbol['children'];
                self::collectScopes($children, $scopes);
            }
        }
    }

    /**
     * Group textDocument/publishDiagnostics params into the editor's per-line
     * diagnostic shape, sorted by line. A range that ends on its own line keeps
     * its real end column. A multi-line range is filed under its START line with
     * PHP_INT_MAX as the end column, so the rest of that first line is underlined;
     * its end line and end character are NOT kept — the shape is per line, and
     * {@see \Porta\Lsp\RefactorController::diagnosticsAt()} hands such a
     * diagnostic back in a `codeAction` context as start..start+1 on that line.
     * A server that needs the exact diagnostic back should match on `data`
     * (kept verbatim) or `code`, not on the range.
     *
     * @param array{
     *     uri?: string,
     *     diagnostics?: list<array{
     *         severity?: int,
     *         message?: string,
     *         code?: int|string,
     *         source?: string,
     *         tags?: list<int>,
     *         data?: mixed,
     *         range: array{
     *             start: array{line?: int, character?: int},
     *             end: array{line?: int, character?: int},
     *         },
     *     }>,
     * } $params
     *
     * @return array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code?: int|string, source?: string, tags?: list<int>, data?: mixed}>>
     */
    public static function diagnosticsByLine(array $params): array
    {
        $byLine = [];
        foreach ($params['diagnostics'] ?? [] as $diag) {
            $line = $diag['range']['start']['line'] ?? 0;
            $sameLine = ($diag['range']['end']['line'] ?? $line) === $line;
            $mapped = [
                'severity' => DiagnosticSeverity::fromWire($diag['severity'] ?? null),
                'message' => \trim((string) \preg_replace('/\s+/', replacement: ' ', subject: $diag['message'] ?? '')),
                'col' => $diag['range']['start']['character'] ?? 0,
                'endCol' => $sameLine ? $diag['range']['end']['character'] ?? 0 : \PHP_INT_MAX,
            ];
            // `code` and `source` ride along when the server sent them, which
            // phpactor does for every diagnostic it raises (measured:
            // `worse.unused_import` / `phpactor`). They are not decoration:
            // {@see \Porta\Lsp\RefactorController::diagnosticsAt()} puts them back
            // into the `codeAction` context because a server that matches its
            // own fix by code sees nothing to match without them — and until
            // they were carried here, that loop could never find one.
            $code = $diag['code'] ?? null;
            if (null !== $code) {
                $mapped['code'] = $code;
            }
            // Kept only as the string the spec says it is: a server sending
            // anything else would have the problem card's origin line choke.
            $source = $diag['source'] ?? null;
            if (\is_string($source)) {
                $mapped['source'] = $source;
            }
            // `tags` say what KIND of thing this is — unused, deprecated —
            // which severity cannot: phpactor reports an unused import at
            // severity 2, indistinguishable from a warning about working code
            // until the tag is drawn. Kept as ints rather than the enum: this
            // is wire data, and a server may send a tag from a spec newer than
            // this one, which the renderer should ignore rather than choke on.
            $tags = \array_values(\array_filter($diag['tags'] ?? [], \is_int(...)));
            if ([] !== $tags) {
                $mapped['tags'] = $tags;
            }
            // `data` is the server's own payload, opaque to us, that the spec
            // says it gets back verbatim in a later `codeAction` context — a
            // server that resolves its fix from it (custos keeps the rule and
            // span there) has nothing to resolve without it.
            /** @var mixed $data opaque by definition: the server's own, sent back as it came */
            $data = $diag['data'] ?? null;
            if (null !== $data) {
                $mapped['data'] = $data;
            }
            $byLine[$line][] = $mapped;
        }
        \ksort($byLine);

        return $byLine;
    }
}
