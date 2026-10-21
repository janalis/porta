<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\EditorRow;
use Porta\Editor\EditorRowWalk;
use Porta\Editor\EditorViewport;
use Porta\Editor\FoldModel;

/**
 * The editor's content rows, walked as the row assembler draws them. Eight
 * lines: a lens above `a`, `b` folded over `c` and `d`, two block rows under
 * `e`, and `x` long enough to wrap to three rows at ten columns.
 */
final class EditorRowWalkTest extends TestCase
{
    private static function walk(): EditorRowWalk
    {
        $document = new Document("a\nb\nc\nd\ne\n".\str_repeat('x', times: 25)."\ng\nh");
        $folds = new FoldModel($document);
        $folds->setRegions([[1, 3]], $document->version);
        $document->setCursor(1, 0);
        $folds->toggle();
        $decorations = new DecorationStore(static function (): void {});
        $decorations->setCodeLenses([0 => [['title' => '3 usages', 'command' => 'x', 'arguments' => [], 'owner' => 'php']]]);
        $decorations->setLineBlocks([4 => ['first', 'second']]);

        return new EditorRowWalk($document, $folds, $decorations);
    }

    private static function viewport(int $top = 0, ?int $wrap = 10, int $segment = 0): EditorViewport
    {
        return new EditorViewport($top, 0, 7, 5, 40, 20, [], $wrap, $segment);
    }

    /**
     * @param list<?EditorRow> $rows
     *
     * @return list<?array{int, ?int}> each row's line and text segment
     */
    private static function shape(array $rows): array
    {
        return \array_map(static fn (?EditorRow $row): ?array => null === $row ? null : [$row->line, $row->segment()], $rows);
    }

    public function testEachLineTakesItsLensItsTextAndItsBlockRowsAndFoldsAreSkipped(): void
    {
        $this->assertSame(
            [
                [0, null], // the lens above a
                [0, 0],
                [1, 0], // b, folded: c and d never show
                [4, 0],
                [4, null], // e's two block rows
                [4, null],
                [5, 0], // x in three
                [5, 1],
                [5, 2],
                [6, 0],
                [7, 0],
                null, // past the last line
            ],
            self::shape(self::walk()->rows(12, self::viewport())),
        );
    }

    public function testATopLineScrolledPartwayStartsAtTheRowTheViewDoes(): void
    {
        $this->assertSame([[5, 2], [6, 0], [7, 0]], self::shape(self::walk()->rows(3, self::viewport(top: 5, segment: 2))));
        $this->assertSame([[0, 0], [1, 0]], self::shape(self::walk()->rows(2, self::viewport(segment: 1))), 'scrolled past the lens');
    }

    public function testUnwrappedALongLineIsOneRow(): void
    {
        $rows = self::walk()->rows(2, self::viewport(top: 5, wrap: null));

        $this->assertSame([[5, 0], [6, 0]], self::shape($rows));
        $this->assertSame(1, $rows[0]?->segments);
    }

    public function testNoRowsAskedIsNone(): void
    {
        $this->assertSame([], self::walk()->rows(0, self::viewport()));
        $this->assertSame([], self::walk()->rows(-1, self::viewport()));
    }
}
