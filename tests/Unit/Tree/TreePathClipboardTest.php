<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Tree;

use PHPUnit\Framework\TestCase;
use Porta\Tree\TreePathClipboard;

/**
 * The tree's own clipboard: files waiting to be pasted somewhere else.
 *
 * Separate from the system text clipboard on purpose — "Copy Path" puts a
 * string there for another program, this holds files for this tree to move —
 * and the distinction is the whole reason the class exists.
 */
final class TreePathClipboardTest extends TestCase
{
    public function testACutRemembersItsPathsAndThatItIsAMove(): void
    {
        $clipboard = new TreePathClipboard();
        $this->assertTrue($clipboard->isEmpty(), 'nothing to paste yet');

        $clipboard->cut(['/p/a.php', '/p/b.php']);

        $this->assertFalse($clipboard->isEmpty());
        $this->assertSame(['/p/a.php', '/p/b.php'], $clipboard->paths());
        $this->assertFalse($clipboard->isCopy(), 'a cut moves');
    }

    public function testACopyIsRememberedAsACopy(): void
    {
        $clipboard = new TreePathClipboard();

        $clipboard->copy(['/p/a.php']);

        $this->assertTrue($clipboard->isCopy());
    }

    /** The second of the two wins outright rather than merging. */
    public function testCopyingAfterACutReplacesIt(): void
    {
        $clipboard = new TreePathClipboard();
        $clipboard->cut(['/p/a.php']);

        $clipboard->copy(['/p/b.php']);

        $this->assertSame(['/p/b.php'], $clipboard->paths());
        $this->assertTrue($clipboard->isCopy(), 'and it is a copy now, not a move');
    }

    public function testClearingEmptiesItAndForgetsTheMode(): void
    {
        $clipboard = new TreePathClipboard();
        $clipboard->copy(['/p/a.php']);

        $clipboard->clear();

        $this->assertTrue($clipboard->isEmpty());
        $this->assertSame([], $clipboard->paths());
        $this->assertFalse($clipboard->isCopy());
    }
}
