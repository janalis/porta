<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Lsp\LspMapper;

final class LspMapperTest extends TestCase
{
    public function testMapInlayHintsGroupsByLineAndSkipsUnusableEntries(): void
    {
        $doc = new Document("<?php\n\$x = 1;\n\$y = 2;\n");

        $hints = LspMapper::mapInlayHints($doc, [
            ['position' => ['line' => 1, 'character' => 2], 'label' => ': int'],
            ['position' => ['line' => 1, 'character' => 4], 'label' => [['value' => 'a'], 'b']], // label parts concatenate
            ['position' => ['character' => 0], 'label' => 'no line'], // missing line — skipped
            ['position' => ['line' => 2, 'character' => 0], 'label' => '  '], // blank after trim — skipped
            ['position' => ['line' => 2], 'label' => 'no character'], // missing character — skipped
            ['position' => ['line' => 2, 'character' => 2], 'label' => ': int'],
        ]);

        $this->assertSame(
            [
                1 => [
                    ['col' => 2, 'text' => ': int'],
                    ['col' => 4, 'text' => 'ab'],
                ],
                2 => [
                    ['col' => 2, 'text' => ': int'],
                ],
            ],
            $hints,
            'grouped per line, in order, with unusable/blank entries dropped without aborting the rest',
        );
    }

    public function testMapInlayHintsWithNoUsableHintsReturnsEmpty(): void
    {
        $doc = new Document("<?php\n");
        $this->assertSame([], LspMapper::mapInlayHints($doc, [['position' => ['line' => 0, 'character' => 0], 'label' => '']]));
    }

    public function testHoverToTextCastsNonStringChunkValuesAndTrimsBothLayers(): void
    {
        // A chunk whose 'value' is not already a string (e.g. an LSP server
        // that sends a number) must still be cast, not passed through raw.
        $this->assertSame('42', LspMapper::hoverToText(['contents' => ['value' => 42]]));

        // A whitespace-only joined result must be treated as empty (the
        // empty-check itself must run against the TRIMMED text).
        $this->assertNull(LspMapper::hoverToText(['contents' => ['   ']]));
        // ...and whitespace left after the code-fence strip runs is trimmed too.
        $this->assertSame('code', LspMapper::hoverToText(['contents' => "```php\n```\n  code  "]));
    }

    public function testAllLocationsNormalizesEveryShape(): void
    {
        $this->assertSame([], LspMapper::allLocations(null));
        $this->assertSame([], LspMapper::allLocations([]));
        $this->assertSame([], LspMapper::allLocations('nope'));

        // A bare (non-list) Location.
        $bare = ['uri' => 'file:///a.php', 'range' => ['start' => ['line' => 1]]];
        $this->assertSame([$bare], LspMapper::allLocations($bare));

        // A malformed candidate must be skipped, not abort the rest of the list.
        $second = ['uri' => 'file:///b.php', 'range' => ['start' => ['line' => 2]]];
        $this->assertSame(
            [$second],
            LspMapper::allLocations(['not-a-shape', $second]),
            'a malformed leading candidate is skipped, the valid one after it still lands',
        );

        // LocationLink: targetSelectionRange wins over targetRange when both are present.
        $link = [
            'targetUri' => 'file:///c.php',
            'targetSelectionRange' => ['start' => ['line' => 3]],
            'targetRange' => ['start' => ['line' => 99]],
        ];
        $this->assertSame(
            ['uri' => 'file:///c.php', 'range' => ['start' => ['line' => 3]]],
            LspMapper::allLocations([$link])[0],
        );

        // targetRange is the fallback when targetSelectionRange is absent.
        $linkNoSelection = ['targetUri' => 'file:///d.php', 'targetRange' => ['start' => ['line' => 4]]];
        $this->assertSame(
            ['uri' => 'file:///d.php', 'range' => ['start' => ['line' => 4]]],
            LspMapper::allLocations([$linkNoSelection])[0],
        );

        // Neither range key present: defaults to an empty range, not dropped.
        $linkNoRangeAtAll = ['targetUri' => 'file:///e.php'];
        $this->assertSame(
            ['uri' => 'file:///e.php', 'range' => []],
            LspMapper::allLocations([$linkNoRangeAtAll])[0],
        );

        // Multiple valid results must all survive (not truncated to one).
        $multi = LspMapper::allLocations([$bare, $second]);
        $this->assertSame([$bare, $second], $multi);

        // A candidate with only ONE of uri/range must be rejected, not admitted
        // via the other half alone (guards against a precedence/operator flip).
        $this->assertSame([], LspMapper::allLocations([['range' => ['start' => ['line' => 1]]]]), 'range without a uri is not a Location');
        $this->assertSame([], LspMapper::allLocations([['uri' => 'file:///f.php']]), 'uri without a range is not a Location');

        // An empty (but present) range is falsy — the check must be a strict
        // "!== null", not a truthy check that a mutated `&&`/`??` precedence
        // flip could produce (`&&` never yields null, so a trailing `?? X`
        // after it is otherwise unreachable and only [] vs null exposes it).
        $this->assertSame(
            [['uri' => 'file:///g.php', 'range' => []]],
            LspMapper::allLocations([['uri' => 'file:///g.php', 'range' => []]]),
            'an empty range is still a present range, not absent',
        );
    }

    public function testRangeContainsMissingPartsDefaultToExactlyZero(): void
    {
        // Each probe pins ONE of the four sub-values' *missing-key* default to
        // exactly 0 — both bordering values (-1 and 1) are ruled out by a pair
        // of assertions that only agree with a true zero default.
        $rangeMissingStartLine = ['start' => ['character' => 0], 'end' => ['line' => 10, 'character' => 10]];
        $this->assertTrue(LspMapper::rangeContains($rangeMissingStartLine, 0, 0), 'missing start line defaults to 0, not 1');
        $this->assertFalse(LspMapper::rangeContains($rangeMissingStartLine, -1, 0), 'missing start line defaults to 0, not -1');

        $rangeMissingStartCol = ['start' => ['line' => 0], 'end' => ['line' => 10, 'character' => 10]];
        $this->assertTrue(LspMapper::rangeContains($rangeMissingStartCol, 0, 0), 'missing start col defaults to 0, not 1');
        $this->assertFalse(LspMapper::rangeContains($rangeMissingStartCol, 0, -1), 'missing start col defaults to 0, not -1');

        $rangeMissingEndLine = ['start' => ['line' => 0, 'character' => 0], 'end' => ['character' => 0]];
        $this->assertTrue(LspMapper::rangeContains($rangeMissingEndLine, 0, 0), 'missing end line defaults to 0, not 1');
        $this->assertFalse(LspMapper::rangeContains($rangeMissingEndLine, 1, 0), 'missing end line defaults to 0, not -1');

        $rangeMissingEndCol = ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0]];
        $this->assertTrue(LspMapper::rangeContains($rangeMissingEndCol, 0, 0), 'missing end col defaults to 0, not 1');
        $this->assertFalse(LspMapper::rangeContains($rangeMissingEndCol, 0, 1), 'missing end col defaults to 0, not -1');
    }

    public function testRangeContainsCastsNumericStringPositionsAsIntegers(): void
    {
        // @phpstan-ignore-next-line — deliberately feeding wire-shaped (untyped) data
        $range = ['start' => ['line' => '3', 'character' => '6'], 'end' => ['line' => '3', 'character' => '12']];
        $this->assertTrue(LspMapper::rangeContains($range, 3, 9), 'numeric-string range parts are cast, not compared loosely');
        $this->assertFalse(LspMapper::rangeContains($range, 3, 13));
    }

    public function testFlattenSymbolsHandlesTheFullRangeFallbackChainAndRecursion(): void
    {
        $items = [];
        LspMapper::flattenSymbols(
            [
                'not-a-shape', // malformed: skipped, must not abort the rest
                ['name' => ''], // empty name: also skipped
                [
                    // DocumentSymbol: selectionRange wins over range and location.range.
                    'name' => 'viaSelectionRange',
                    'kind' => 12,
                    'selectionRange' => ['start' => ['line' => 1, 'character' => 2]],
                    'range' => ['start' => ['line' => 99, 'character' => 99]],
                    'location' => ['range' => ['start' => ['line' => 98, 'character' => 98]]],
                    'children' => [
                        ['name' => 'child', 'kind' => 13, 'selectionRange' => ['start' => ['line' => 4, 'character' => 5]]],
                    ],
                ],
                [
                    // range wins over location.range when selectionRange is absent.
                    'name' => 'viaRange',
                    'range' => ['start' => ['line' => 6, 'character' => 7]],
                    'location' => ['range' => ['start' => ['line' => 97, 'character' => 97]]],
                ],
                [
                    // SymbolInformation: location.range is the last fallback.
                    'name' => 'viaLocationRange',
                    'location' => ['range' => ['start' => ['line' => 8, 'character' => 9]]],
                ],
                [
                    // No range info anywhere, and the chosen range has no 'start': both
                    // fallback layers (the whole-range default and the start-only
                    // default) land on exactly [0, 0].
                    'name' => 'noRangeAtAll',
                    'range' => [],
                ],
                [
                    'name' => 'unknownKind',
                    'kind' => 999, // not in SYMBOL_KINDS
                    'selectionRange' => ['start' => ['line' => 0, 'character' => 0]],
                ],
            ],
            0,
            $items,
        );

        $this->assertSame(
            [
                ['label' => 'viaSelectionRange', 'hint' => 'fn', 'value' => '1:2'],
                ['label' => '  child', 'hint' => 'var', 'value' => '4:5'],
                ['label' => 'viaRange', 'hint' => '', 'value' => '6:7'],
                ['label' => 'viaLocationRange', 'hint' => '', 'value' => '8:9'],
                ['label' => 'noRangeAtAll', 'hint' => '', 'value' => '0:0'],
                ['label' => 'unknownKind', 'hint' => '', 'value' => '0:0'],
            ],
            $items,
        );
    }

    public function testFlattenSymbolsMissingNameKeyIsSkippedLikeAnEmptyOne(): void
    {
        $items = [];
        LspMapper::flattenSymbols(
            [
                ['selectionRange' => ['start' => ['line' => 0, 'character' => 0]]], // no 'name' key at all
                ['name' => 'kept', 'selectionRange' => ['start' => ['line' => 0, 'character' => 0]]],
            ],
            0,
            $items,
        );
        $this->assertSame(['kept'], array_column($items, 'label'));
    }

    public function testFlattenSymbolsValueDefaultsLineAndCharacterIndependently(): void
    {
        // Each start is missing exactly one of line/character, so the two `?? 0`
        // defaults inside the "line:character" string are exercised separately.
        $items = [];
        LspMapper::flattenSymbols([['name' => 'a', 'selectionRange' => ['start' => ['character' => 5]]]], 0, $items);
        $this->assertSame('0:5', $items[0]['value'], 'missing line defaults to 0');

        $items = [];
        LspMapper::flattenSymbols([['name' => 'b', 'selectionRange' => ['start' => ['line' => 5]]]], 0, $items);
        $this->assertSame('5:0', $items[0]['value'], 'missing character defaults to 0');

        // A float character: casting to int must truncate it, not pass it
        // through raw into the concatenated string.
        $items = [];
        LspMapper::flattenSymbols([['name' => 'c', 'selectionRange' => ['start' => ['line' => 1, 'character' => 3.7]]]], 0, $items);
        $this->assertSame('1:3', $items[0]['value'], 'the character part is cast to int, not concatenated raw');
    }

    public function testFlattenSymbolsIndentsByDepthAndClampsNegativeDepth(): void
    {
        $items = [];
        LspMapper::flattenSymbols([['name' => 'x', 'selectionRange' => ['start' => ['line' => 0, 'character' => 0]]]], 2, $items);
        $this->assertSame('    x', $items[0]['label'], 'two levels of two-space indent');

        $items = [];
        LspMapper::flattenSymbols([['name' => 'x', 'selectionRange' => ['start' => ['line' => 0, 'character' => 0]]]], -1, $items);
        $this->assertSame('x', $items[0]['label'], 'negative depth clamps to no indent, not a negative repeat');
    }

    /**
     * @param array<string, mixed> $range
     *
     * @return array<string, mixed>
     */
    private function highlight(array $range, ?int $kind = null): array
    {
        return null === $kind ? ['range' => $range] : ['range' => $range, 'kind' => $kind];
    }

    /** @return array<string, mixed> */
    private function range(int $line, int $from, int $to, ?int $endLine = null): array
    {
        return [
            'start' => ['line' => $line, 'character' => $from],
            'end' => ['line' => $endLine ?? $line, 'character' => $to],
        ];
    }

    public function testDocumentHighlightsMapToOccurrenceSpansWithOnlyKindThreeAsAWrite(): void
    {
        $doc = new Document("<?php\n\$name = 1;\necho \$name;\n");

        $spans = LspMapper::mapDocumentHighlights($doc, [
            $this->highlight($this->range(1, 0, 5), kind: 3),
            $this->highlight($this->range(2, 5, 10), kind: 2),
            $this->highlight($this->range(2, 0, 4), kind: 1),
            $this->highlight($this->range(1, 0, 5)), // no kind at all
        ]);

        $this->assertSame(
            [
                [1, 0, 5, true],
                [2, 5, 10, false],
                [2, 0, 4, false],
                [1, 0, 5, false],
            ],
            $spans,
            'only kind 3 (Write) is a write; Text, Read and an absent kind all paint as reads',
        );
    }

    /** An occurrence is one identifier, so a range crossing lines is not one — dropped, never clipped. */
    public function testAMultiLineHighlightRangeIsDropped(): void
    {
        $doc = new Document("<?php\n\$name = 1;\necho \$name;\n");

        $spans = LspMapper::mapDocumentHighlights($doc, [$this->highlight($this->range(1, 0, 4, endLine: 2))]);

        $this->assertSame([], $spans);
    }

    /** @return \Generator<string, array{list<array<string, mixed>>}> */
    public static function unusableHighlights(): \Generator
    {
        yield 'no range at all' => [[['kind' => 2]]];
        yield 'no start line' => [[['range' => ['start' => ['character' => 0], 'end' => ['line' => 1, 'character' => 4]]]]];
        yield 'no end line' => [[['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['character' => 4]]]]];
        yield 'empty range' => [[['range' => ['start' => ['line' => 1, 'character' => 3], 'end' => ['line' => 1, 'character' => 3]]]]];
        yield 'reversed range' => [[['range' => ['start' => ['line' => 1, 'character' => 4], 'end' => ['line' => 1, 'character' => 1]]]]];
        yield 'a range crossing lines' => [[['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 2, 'character' => 4]]]]];
        yield 'an end with no character, which defaults to column 0' => [[['range' => ['start' => ['line' => 1, 'character' => 0], 'end' => ['line' => 1]]]]];
    }

    /** @param list<array<string, mixed>> $highlights */
    #[DataProvider('unusableHighlights')]
    public function testAnUnusableHighlightIsSkippedRatherThanPaintedAtColumnZero(array $highlights): void
    {
        $doc = new Document("<?php\n\$name = 1;\n");

        $this->assertSame([], LspMapper::mapDocumentHighlights($doc, $highlights));
    }

    /**
     * Skipped, and the rest of the answer still mapped. Each case above is one
     * highlight on its own, which cannot tell "skip this entry" from "abandon
     * the whole answer" — so a good highlight rides behind the bad one here and
     * has to come out the other side.
     *
     * @param list<array<string, mixed>> $highlights
     */
    #[DataProvider('unusableHighlights')]
    public function testAnUnusableHighlightDoesNotTakeTheGoodOnesAfterItWithIt(array $highlights): void
    {
        $doc = new Document("<?php\n\$name = 1;\n");

        $spans = LspMapper::mapDocumentHighlights($doc, [...$highlights, $this->highlight($this->range(1, 0, 5), kind: 3)]);

        $this->assertSame([[1, 0, 5, true]], $spans);
    }

    /**
     * A range endpoint with a line but no `character` starts at column 0 — the
     * default is load-bearing, and any other value silently shifts the
     * highlight off the identifier it belongs to.
     */
    public function testAMissingCharacterDefaultsToColumnZero(): void
    {
        $doc = new Document("<?php\n\$name = 1;\n");

        $spans = LspMapper::mapDocumentHighlights($doc, [
            ['range' => ['start' => ['line' => 1], 'end' => ['line' => 1, 'character' => 5]]],
        ]);

        $this->assertSame([[1, 0, 5, false]], $spans, 'the start is column 0, not 1 and not -1');
    }
}
