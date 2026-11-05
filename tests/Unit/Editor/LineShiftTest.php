<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\LineShift;

final class LineShiftTest extends TestCase
{
    public function testALineAddedAboveMovesEverythingBelowIt(): void
    {
        $shift = LineShift::between(['<?php', 'a();', 'b();'], ['<?php', 'use Foo;', 'a();', 'b();']);

        $this->assertSame(0, $shift->map(0));
        $this->assertSame(2, $shift->map(1));
        $this->assertSame(3, $shift->map(2));
    }

    /** Enter at the start of a line pushes that line down: its statement is below the new blank one. */
    public function testANewLineAtTheStartOfALineMovesThatLine(): void
    {
        $shift = LineShift::between(['a();', 'b();'], ['a();', '', 'b();']);

        $this->assertSame(2, $shift->map(1));
    }

    public function testLinesTheEditRemovedAreHeldToItsFirstLine(): void
    {
        $shift = LineShift::between(['a();', 'x', 'y', 'b();'], ['a();', 'b();']);

        $this->assertSame(1, $shift->map(1), 'gone: held to where the edit was');
        $this->assertSame(1, $shift->map(2));
        $this->assertSame(1, $shift->map(3), 'below it: moved up');
    }
}
