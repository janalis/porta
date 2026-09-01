<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\Document;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\LineSpans;

final class LineSpansTest extends TestCase
{
    public function testLayeringOrderIsSelectionSearchOccurrenceBracketDiagnosticCaret(): void
    {
        $doc = new Document("hello world\n");
        $styles = new Styles(DarkTheme::definition());
        $result = LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(
                multiActive: false,
                selection: ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 5],
                carets: [new Caret(0, 3, 0, 3)],
                occurrences: [[0, 0, 5, false]],
                brackets: [[0, 4]],
            ),
            [['start' => 6, 'end' => 11, 'current' => true]],
            [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 5]],
            $styles,
        );

        $opens = array_column($result['spans'], 2);
        $this->assertSame($styles->of(Role::SelectionBg)->getAnsiRestore(), $opens[0], 'selection first');
        $this->assertSame($styles->of(Role::SearchCurrentBg)->getAnsiRestore()."\x1b[1m", $opens[1], 'current search match next, bold for a non-color cue');
        $this->assertSame($styles->of(Role::OccurrenceBg)->getAnsiRestore(), $opens[2]);
        $this->assertSame(LineDecorator::BRACKET_SGR, $opens[3]);
        $this->assertSame("\x1b[7m", $opens[\count($opens) - 1], 'caret cell layers last');
        $this->assertSame(4, $result['padTo'], 'caret at col 3 needs 4 cells');
    }

    public function testPrimarySelectionAndEquivalentCaretRecordProduceIdenticalSpans(): void
    {
        $doc = new Document("alpha beta\ngamma delta\nepsilon\n");
        $styles = new Styles(DarkTheme::definition());

        foreach ([0, 1, 2] as $line) {
            $viaSelection = LineSpans::forLine(
                $doc,
                $line,
                new EditorDecorations(
                    multiActive: false,
                    selection: ['startLine' => 0, 'startCol' => 2, 'endLine' => 2, 'endCol' => 4],
                    carets: [],
                    occurrences: [],
                    brackets: null,
                ),
                [],
                [],
                $styles,
            );
            // The same range expressed as a (reversed) caret record, minus the
            // caret cell the multi-caret path adds on the caret's own line.
            $viaCaret = LineSpans::forLine(
                $doc,
                $line,
                new EditorDecorations(
                    multiActive: false,
                    selection: null,
                    carets: [new Caret(0, 2, 2, 4)],
                    occurrences: [],
                    brackets: null,
                ),
                [],
                [],
                $styles,
            );
            $caretSelectionSpans = array_values(array_filter($viaCaret['spans'], static fn (array $s) => "\x1b[7m" !== $s[2]));
            $this->assertSame($viaSelection['spans'], $caretSelectionSpans, "line {$line}: one range → one span shape");
        }
    }

    public function testCollapsedCaretDrawsOnlyItsCell(): void
    {
        $doc = new Document("ab\n");
        $result = LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(multiActive: false, selection: null, carets: [new Caret(0, 2, 0, 2)], occurrences: [], brackets: null),
            [],
            [],
            new Styles(DarkTheme::definition()),
        );

        $this->assertCount(1, $result['spans'], 'no selection span for a collapsed caret');
        $this->assertSame([2, 3, "\x1b[7m", "\x1b[27m", null], $result['spans'][0]);
        $this->assertSame(3, $result['padTo']);
    }

    public function testBracketSpanCoversExactlyOneCell(): void
    {
        $doc = new Document("a(b)\n");
        $result = LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(multiActive: false, selection: null, carets: [], occurrences: [], brackets: [[0, 3]]),
            [],
            [],
            new Styles(DarkTheme::definition()),
        );

        $bracketSpan = array_values(array_filter($result['spans'], static fn (array $s) => LineDecorator::BRACKET_SGR === $s[2]))[0];
        $this->assertSame(3, $bracketSpan[0]);
        $this->assertSame(4, $bracketSpan[1], 'a bracket cell is exactly one column wide');
    }

    public function testDiagnosticWithoutAnExplicitEndColDefaultsToExactlyOneColumnPastCol(): void
    {
        $doc = new Document("abcdef\n");
        $result = LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(multiActive: false, selection: null, carets: [], occurrences: [], brackets: null),
            [],
            [['severity' => 1, 'message' => 'x', 'col' => 2]], // no endCol
            new Styles(DarkTheme::definition()),
        );

        $diagSpan = $result['spans'][0];
        $this->assertSame(2, $diagSpan[0]);
        $this->assertSame(3, $diagSpan[1], 'defaults to col+1, one column wide, no more and no less');
    }

    public function testDiagnosticEndDisplayColumnIsAtLeastOnePastStartEvenWhenTheMappedEndColIsSmaller(): void
    {
        // endCol maps to the SAME display column as col (a zero-width mapping),
        // so the max(startDisp+1, ...) floor must still produce a 1-wide span.
        $doc = new Document("abcdef\n");
        $result = LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(multiActive: false, selection: null, carets: [], occurrences: [], brackets: null),
            [],
            [['severity' => 1, 'message' => 'x', 'col' => 2, 'endCol' => 2]],
            new Styles(DarkTheme::definition()),
        );

        $diagSpan = $result['spans'][0];
        $this->assertSame(2, $diagSpan[0]);
        $this->assertSame(3, $diagSpan[1], 'the floor of startDisp+1 must hold, not startDisp+0 or startDisp+2');
    }

    public function testMiddleLineOfAMultiLineSelectionStartsAtColumnZero(): void
    {
        $doc = new Document("alpha beta\ngamma delta\nepsilon\n");
        $result = LineSpans::forLine(
            $doc,
            1, // the middle line: neither the selection's start nor end line
            new EditorDecorations(multiActive: false, selection: ['startLine' => 0, 'startCol' => 2, 'endLine' => 2, 'endCol' => 4], carets: [], occurrences: [], brackets: null),
            [],
            [],
            new Styles(DarkTheme::definition()),
        );

        $this->assertSame(0, $result['spans'][0][0], 'a fully-selected middle line starts at column 0, not -1');
    }

    public function testOffLineDecorationsProduceNoSpans(): void
    {
        $doc = new Document("one\ntwo\n");
        $result = LineSpans::forLine(
            $doc,
            1,
            new EditorDecorations(
                multiActive: false,
                selection: ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 2],
                carets: [new Caret(0, 1, 0, 1)],
                occurrences: [[0, 0, 3, false]],
                brackets: [[0, 1]],
            ),
            [],
            [],
            new Styles(DarkTheme::definition()),
        );

        $this->assertSame([], $result['spans']);
        $this->assertSame(0, $result['padTo']);
    }

    public function testClipDropsSpansOutsideTheWindowAndClampsStraddlers(): void
    {
        $mk = static fn (int $s, int $e): array => [$s, $e, 'O', 'C', null];

        $clipped = LineSpans::clip(
            [
                $mk(s: 0, e: 10), // ends exactly at winStart → dropped
                $mk(s: 30, e: 40), // starts exactly at winEnd → dropped
                $mk(s: 5, e: 15), // straddles the left edge → clamped to [10, 15)
                $mk(s: 25, e: 99), // straddles the right edge → clamped to [25, 30)
                $mk(s: 12, e: 18), // fully inside → untouched
                $mk(0, \PHP_INT_MAX), // "to end of line" diagnostic → clamped to the window
            ],
            10,
            30,
        );

        $this->assertSame(
            [
                $mk(s: 10, e: 15),
                $mk(s: 25, e: 30),
                $mk(s: 12, e: 18),
                $mk(s: 10, e: 30),
            ],
            $clipped,
        );
    }

    /**
     * A write access paints with its own role. Both spans go through the SAME
     * loop, so a case with only a write would pass even if the flag were
     * ignored and both used one role — this asserts the two differ.
     */
    public function testAWriteOccurrencePaintsDistinctlyFromAReadOne(): void
    {
        $doc = new Document("aaa bbb\n");
        $styles = Styles::dark();

        /** @param list<array{0: int, 1: int, 2: int, 3: bool}> $occurrences */
        $spansFor = static fn (array $occurrences): array => LineSpans::forLine(
            $doc,
            0,
            new EditorDecorations(multiActive: false, selection: null, carets: [], occurrences: $occurrences, brackets: null),
            [],
            [],
            $styles,
        );
        $read = $spansFor([[0, 0, 3, false]]);
        $write = $spansFor([[0, 0, 3, true]]);

        $this->assertSame($styles->of(Role::OccurrenceBg)->getAnsiRestore(), $read['spans'][0][2]);
        $this->assertSame($styles->of(Role::OccurrenceWriteBg)->getAnsiRestore(), $write['spans'][0][2]);
        $this->assertNotSame($read['spans'][0][2], $write['spans'][0][2], 'a write must not paint identically to a read');
    }
}
