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
        $store->setDetails('/p/a.php', 4, null, null, 'x={$x}');
        $store->setDetails('/p/a.php', 8, '$x > 1', null, null);

        $this->assertSame([4], $store->logpointLinesFor('/p/a.php'), 'only the log-message line is a logpoint');
        $this->assertSame([8], $store->conditionalLinesFor('/p/a.php'), 'the log message alone is not "conditional"');
        $this->assertSame('x={$x}', $store->conditionAt('/p/a.php', 4)['logMessage'] ?? null);
    }

    public function testAllDropsEmptiedFilesAndClearEmptiesEverything(): void
    {
        $store = new BreakpointStore();
        $store->toggle('/p/a.php', 1);
        $store->toggle('/p/b.php', 2);
        $store->toggle('/p/b.php', 2); // toggles off → the file disappears

        $this->assertSame(['/p/a.php' => [['line' => 1, 'condition' => null, 'hitCondition' => null, 'logMessage' => null]]], $store->all());

        $store->clear();
        $this->assertSame([], $store->all());
    }
}
