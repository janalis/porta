<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\BreakpointStore;

final class BreakpointStoreTest extends TestCase
{
    public function testToggleSetsAndClearsPerFile(): void
    {
        $store = new BreakpointStore();

        $this->assertTrue($store->toggle('/p/a.php', 9));
        $this->assertTrue($store->toggle('/p/a.php', 3));
        $this->assertTrue($store->toggle('/p/b.php', 3));
        $this->assertSame([3, 9], $store->linesFor('/p/a.php'), 'ascending order');
        $this->assertSame([3], $store->linesFor('/p/b.php'), 'files are isolated');

        $this->assertFalse($store->toggle('/p/a.php', 9), 'second toggle clears');
        $this->assertSame([3], $store->linesFor('/p/a.php'));
        $this->assertSame([], $store->linesFor('/p/unknown.php'));
    }

    public function testConditionsCreateEditAndClearWithTheBreakpoint(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/a.php', 3);
        $this->assertSame(['condition' => null, 'hitCondition' => null, 'logMessage' => null], $store->conditionAt('/p/a.php', 3));
        $this->assertNull($store->conditionAt('/p/a.php', 9), 'no breakpoint, no condition record');

        $store->setDetails('/p/a.php', 3, '$x > 1', null, null);
        $store->setDetails('/p/a.php', 7, null, '5', null); // creates the breakpoint on a bare line
        $this->assertSame([3, 7], $store->linesFor('/p/a.php'));
        $this->assertSame([3, 7], $store->conditionalLinesFor('/p/a.php'));
        $this->assertSame(
            [
                ['line' => 3, 'condition' => '$x > 1', 'hitCondition' => null, 'logMessage' => null],
                ['line' => 7, 'condition' => null, 'hitCondition' => '5', 'logMessage' => null],
            ],
            $store->breakpointsFor('/p/a.php'),
        );

        $store->setDetails('/p/a.php', 3, null, null, null); // cleared: still a breakpoint, no longer conditional
        $this->assertSame([3, 7], $store->linesFor('/p/a.php'));
        $this->assertSame([7], $store->conditionalLinesFor('/p/a.php'));

        $store->toggle('/p/a.php', 7); // off — the condition dies with it
        $store->toggle('/p/a.php', 7); // back on: unconditional again
        $this->assertSame([], $store->conditionalLinesFor('/p/a.php'));
    }

    public function testLogMessageMakesALogpointDistinctFromAConditional(): void
    {
        $store = new BreakpointStore();
        // Inserted out of ascending order, on purpose: both accessors sort
        // their result, and inserting in order would let a missing sort()
        // pass unnoticed.
        $store->setDetails('/p/a.php', 8, '$x > 1', null, null);
        $store->setDetails('/p/a.php', 2, '$y > 1', null, null);
        $store->setDetails('/p/a.php', 4, null, null, 'x={$x}');
        $store->setDetails('/p/a.php', 1, null, null, 'y={$y}');

        $this->assertSame([1, 4], $store->logpointLinesFor('/p/a.php'), 'ascending, not insertion order');
        $this->assertSame([2, 8], $store->conditionalLinesFor('/p/a.php'), 'ascending, not insertion order');
        $this->assertSame('x={$x}', $store->conditionAt('/p/a.php', 4)['logMessage'] ?? null);
    }

    public function testAllDropsEmptiedFilesAndClearEmptiesEverything(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/a.php', 1);
        $store->toggle('/p/c.php', 5);
        $store->toggle('/p/b.php', 2);
        $store->toggle('/p/b.php', 2); // toggles off → the file disappears

        $this->assertSame(
            [
                '/p/a.php' => [['line' => 1, 'condition' => null, 'hitCondition' => null, 'logMessage' => null]],
                '/p/c.php' => [['line' => 5, 'condition' => null, 'hitCondition' => null, 'logMessage' => null]],
            ],
            $store->all(),
        );

        $store->clear();
        $this->assertSame([], $store->all());
    }

    public function testRenameCarriesBreakpointsAndTheirConditionsToTheNewPath(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/Old.php', 3);
        $store->setDetails('/p/Old.php', 3, '$x > 1', null, null);
        $store->toggle('/p/Other.php', 1);

        $store->rename('/p/Old.php', '/p/New.php');

        $this->assertSame([3], $store->linesFor('/p/New.php'));
        $this->assertSame('$x > 1', $store->conditionAt('/p/New.php', 3)['condition'], 'the condition came with it');
        $this->assertSame([], $store->linesFor('/p/Old.php'));
        $this->assertSame([1], $store->linesFor('/p/Other.php'), 'its neighbour is untouched');
    }

    public function testRenamingAFileWithNoBreakpointsAddsNoEmptyEntry(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/Other.php', 1);

        $store->rename('/p/Unmarked.php', '/p/Renamed.php');

        $this->assertSame(['/p/Other.php'], \array_keys($store->all()));
    }

    public function testForgetDropsADeletedFilesBreakpointsAndItsDirectorys(): void
    {
        // The unrelated file is set FIRST so the walk has to pass over it: a
        // fixture with every match at the front cannot tell "skip this one"
        // from "stop here".
        $store = new BreakpointStore();
        $store->toggle('/p/olden/b.php', 1);
        $store->toggle('/p/old/a.php', 1);
        $store->setDetails('/p/old/a.php', 3, 'i > 2', null, null);

        $store->forget('/p/old');

        $this->assertSame([], $store->linesFor('/p/old/a.php'), 'the conditional went with the plain one');
        $this->assertSame([1], $store->linesFor('/p/olden/b.php'), 'a shared prefix is not the same directory');
    }

    public function testRenamingADirectoryMovesTheBreakpointsInsideIt(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/olden/b.php', 1);
        $store->setDetails('/p/old/a.php', 2, 'i > 2', null, null);

        $store->rename('/p/old', '/p/new');

        $this->assertSame(['condition' => 'i > 2', 'hitCondition' => null, 'logMessage' => null], $store->conditionAt('/p/new/a.php', 2), 'the condition came with it');
        $this->assertSame([], $store->linesFor('/p/old/a.php'));
        $this->assertSame([1], $store->linesFor('/p/olden/b.php'));
    }
}
