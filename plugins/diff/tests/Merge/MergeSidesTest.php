<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Merge;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Merge\MergeRow;
use Porta\DiffPlugin\Merge\MergeSides;
use Porta\Editor\ConflictSide;

final class MergeSidesTest extends TestCase
{
    /** Two conflicts, the second in git's diff3 style. */
    private const array RESULT = [
        'a',
        '<<<<<<< HEAD',
        'ours 1',
        'ours 2',
        '=======',
        'theirs',
        '>>>>>>> topic',
        'b',
        '<<<<<<< HEAD',
        'o',
        '||||||| base',
        'was',
        '=======',
        't1',
        't2',
        '>>>>>>> topic',
        'c',
    ];

    /** @return list<string> each row as kind:region:text */
    private static function rendered(ConflictSide $side): array
    {
        return \array_map(static fn (MergeRow $row): string => $row->kind->name.':'.($row->region ?? '-').':'.$row->text, MergeSides::rows(self::RESULT, $side));
    }

    public function testOursIsTheResultWithEachConflictReplacedByItsLinesRowForRow(): void
    {
        $this->assertSame(
            [
                'Context:-:a',
                'Header:0:HEAD',
                'Side:0:ours 1',
                'Side:0:ours 2',
                'Filler:0:',
                'Filler:0:',
                'Filler:0:',
                'Context:-:b',
                'Header:1:HEAD',
                'Side:1:o',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Context:-:c',
            ],
            self::rendered(ConflictSide::Ours),
            'the base is neither side\'s',
        );
    }

    public function testTheirsIsLabelledByTheCloser(): void
    {
        $this->assertSame(
            [
                'Context:-:a',
                'Header:0:topic',
                'Side:0:theirs',
                'Filler:0:',
                'Filler:0:',
                'Filler:0:',
                'Filler:0:',
                'Context:-:b',
                'Header:1:topic',
                'Side:1:t1',
                'Side:1:t2',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Filler:1:',
                'Context:-:c',
            ],
            self::rendered(ConflictSide::Theirs),
        );
    }

    public function testABareMarkerHasNoLabel(): void
    {
        $rows = MergeSides::rows(['<<<<<<<', 'o', '=======', 't', '>>>>>>>'], ConflictSide::Theirs);

        $this->assertSame('', $rows[0]->text);
    }

    public function testTheCurrentConflictIsTheOneTheLineIsInOrTheNextOrTheLast(): void
    {
        $this->assertSame(0, MergeSides::current(self::RESULT, 0), 'above the first: the first');
        $this->assertSame(0, MergeSides::current(self::RESULT, 6), 'on its closer');
        $this->assertSame(1, MergeSides::current(self::RESULT, 7), 'between: the next');
        $this->assertSame(1, MergeSides::current(self::RESULT, 16), 'below the last: the last');
        $this->assertNull(MergeSides::current(['clean'], 0));
    }

    public function testAcceptingASideReplacesThatConflictAlone(): void
    {
        $this->assertSame(
            ['a', 'ours 1', 'ours 2', 'b', '<<<<<<< HEAD', 'o', '||||||| base', 'was', '=======', 't1', 't2', '>>>>>>> topic', 'c'],
            MergeSides::accept(self::RESULT, 0, ConflictSide::Ours),
        );
        $this->assertSame(
            ['a', '<<<<<<< HEAD', 'ours 1', 'ours 2', '=======', 'theirs', '>>>>>>> topic', 'b', 'o', 't1', 't2', 'c'],
            MergeSides::accept(self::RESULT, 1, ConflictSide::Both),
        );
        $this->assertNull(MergeSides::accept(self::RESULT, 2, ConflictSide::Theirs), 'no third conflict');
    }

    public function testAConflictedFileHasAnOpenerAtTheStartOfALine(): void
    {
        $this->assertTrue(MergeSides::conflicted("a\n<<<<<<< HEAD\nb"));
        $this->assertTrue(MergeSides::conflicted("<<<<<<<\n"));
        $this->assertFalse(MergeSides::conflicted("a\n <<<<<<< not at the start"));
        $this->assertFalse(MergeSides::conflicted("<<<<<<<<< eight\n"), 'not a marker');
        $this->assertFalse(MergeSides::conflicted("clean\n"));
    }
}
