<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\TextEditOverlap;
use Porta\Lsp\WorkspaceEditor;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Overlapping `TextEdit`s are refused now, where they used to be applied.
 *
 * This was a recorded, deliberately-open defect: LSP says overlapping ranges
 * make a WorkspaceEdit invalid, and Porta sorted them bottom-up and applied
 * both — the second clamping into whatever the first left behind, producing a
 * file that is neither what the server asked for nor what it started as, and
 * reporting success. It runs on the format-on-save path, so a formatter with
 * that bug corrupted a file on every save.
 *
 * The first test is the one that matters: it is the corruption, written down.
 */
final class TextEditOverlapTest extends TestCase
{
    /**
     * A bare editor. `applyTextEdits()` touches nothing but the document it is
     * handed — the buffer, pool and event plumbing belong to `editOne()`, one
     * level up — so the collaborators only have to exist.
     */
    private function editor(): WorkspaceEditor
    {
        $editor = new CodeEditorWidget();
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile('/tmp/proj/a.php'), new Translator('en'));

        return new WorkspaceEditor(new BufferManager(), $editor, $pool, new EventDispatcher(), new RefactoringUndo(new BufferManager(), new EventDispatcher()));
    }

    /**
     * @param array{int, int} $start
     * @param array{int, int} $end
     *
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}
     */
    private static function edit(array $start, array $end, string $text): array
    {
        return [
            'range' => [
                'start' => ['line' => $start[0], 'character' => $start[1]],
                'end' => ['line' => $end[0], 'character' => $end[1]],
            ],
            'newText' => $text,
        ];
    }

    public function testOverlappingRangesAreNamedRatherThanApplied(): void
    {
        // `alpha beta` with one edit over `alpha bet` and another over `a bet`.
        $overlapping = [
            self::edit([0, 0], [0, 9], 'X'),
            self::edit([0, 4], [0, 9], 'Y'),
        ];

        $this->assertSame('1:0-1:9 overlaps 1:4-1:9', TextEditOverlap::describe($overlapping));

        $document = new Document("alpha beta\n");
        $applied = $this->editor()->applyTextEdits($document, $overlapping);

        $this->assertFalse($applied);
        // Measured against the code as it stood: applying these bottom-up
        // turned `alpha beta` into `X` — the second edit landed first, then the
        // first replaced what it produced — and reported success. Untouched is
        // the only honest alternative.
        $this->assertSame("alpha beta\n", $document->getText(), 'untouched, rather than mangled to `X`');
    }

    /**
     * A shared START position is legal and common: several inserts at one point
     * is how a server adds two `use` lines. Forbidding it would refuse correct
     * edits, which is the failure mode a naive overlap test has.
     */
    public function testSeveralInsertsAtOnePositionAreNotAnOverlap(): void
    {
        $inserts = [
            self::edit([1, 0], [1, 0], "use A;\n"),
            self::edit([1, 0], [1, 0], "use B;\n"),
        ];

        $this->assertNull(TextEditOverlap::describe($inserts));

        $document = new Document("<?php\n\nclass X {}\n");
        $this->assertTrue($this->editor()->applyTextEdits($document, $inserts));
        $this->assertStringContainsString('use A;', $document->getText());
        $this->assertStringContainsString('use B;', $document->getText());
    }

    /**
     * Adjacent is not overlapping. Servers rewrite two neighbouring tokens all
     * the time, and an off-by-one here would refuse most real formatting.
     */
    public function testAnEditStartingWhereAnotherEndsIsAdjacent(): void
    {
        $this->assertNull(TextEditOverlap::describe([
            self::edit([0, 0], [0, 5], 'aaaaa'),
            self::edit([0, 5], [0, 10], 'bbbbb'),
        ]));
    }

    /**
     * The scan keeps going past a good pair.
     *
     * Three edits where the FIRST two are adjacent and the second two overlap:
     * a walk that stopped at the first pair it liked would call this
     * applicable and apply it.
     */
    public function testAnOverlapAfterAWellBehavedPairIsStillFound(): void
    {
        $this->assertNotNull(TextEditOverlap::describe([
            self::edit([0, 0], [0, 2], 'a'),
            self::edit([0, 3], [0, 9], 'b'),
            self::edit([0, 5], [0, 7], 'c'),
        ]));
    }

    /**
     * The message names LINE:CHARACTER, one-based lines and zero-based
     * characters — the convention every other position in this UI uses.
     *
     * The coordinates in this fixture are all DIFFERENT from each other on
     * purpose. Any test whose lines and columns happen to coincide reads the
     * same whether the message prints the line, the column, or one of them
     * twice, which is three separate ways to be wrong.
     */
    public function testTheMessageNamesLineThenCharacterAndNotTheOtherWayRound(): void
    {
        $this->assertSame('3:4-5:6 overlaps 4:7-4:9', TextEditOverlap::describe([
            self::edit([2, 4], [4, 6], 'a'),
            self::edit([3, 7], [3, 9], 'b'),
        ]));
    }

    /** Order of arrival does not matter: the check sorts before it compares. */
    public function testTheOverlapIsFoundWhicheverOrderTheEditsArriveIn(): void
    {
        $late = self::edit([0, 4], [0, 9], 'Y');
        $early = self::edit([0, 0], [0, 9], 'X');

        $this->assertNotNull(TextEditOverlap::describe([$early, $late]));
        $this->assertNotNull(TextEditOverlap::describe([$late, $early]), 'and reversed');
    }

    /** Across lines, where a single-line comparison would miss it. */
    public function testAMultiLineRangeSwallowingAnotherIsAnOverlap(): void
    {
        $this->assertSame('1:0-3:0 overlaps 2:2-2:6', TextEditOverlap::describe([
            self::edit([0, 0], [2, 0], 'X'),
            self::edit([1, 2], [1, 6], 'Y'),
        ]));
    }

    public function testNothingAndOneEditAreAlwaysApplicable(): void
    {
        $this->assertNull(TextEditOverlap::describe([]));
        $this->assertNull(TextEditOverlap::describe([self::edit([0, 0], [0, 3], 'x')]));
    }

    /**
     * A missing `range` reads as 0:0-0:0, which is the shape every other reader
     * in this file assumes — so two of them are two empty ranges at one point,
     * not an overlap, and the malformed edit is applied as a no-op insert
     * rather than refusing the whole file.
     */
    public function testAMalformedEditDoesNotReadAsAnOverlap(): void
    {
        /** @var list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}> $edits */
        $edits = [['range' => ['start' => [], 'end' => []]], ['range' => ['start' => [], 'end' => []]]];

        $this->assertNull(TextEditOverlap::describe($edits));
    }
}
