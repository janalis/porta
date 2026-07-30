<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;

final class BufferManagerTest extends TestCase
{
    public function testOpenActivatesAndReusesBuffers(): void
    {
        $manager = new BufferManager();

        $a = $manager->open('/p/a.php', 'A');
        $this->assertSame($a, $manager->active());
        $this->assertTrue($manager->has('/p/a.php'));
        $this->assertSame($a, $manager->get('/p/a.php'));
        $this->assertNull($manager->get('/p/none.php'));

        $again = $manager->open('/p/a.php', 'ignored — buffer already exists');
        $this->assertSame($a, $again, 'reopening returns the same buffer');
        $this->assertSame('A', $again->document->getText());
    }

    public function testSetActive(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');

        $this->assertNull($manager->setActive('/p/missing.php'));
        $this->assertSame($b, $manager->active());

        $a = $manager->setActive('/p/a.php');
        $this->assertSame('/p/a.php', $a?->path);
        $this->assertSame($a, $manager->active());
    }

    public function testCycleWrapsBothDirections(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');
        $manager->open('/p/b.php', 'B');
        $manager->open('/p/c.php', 'C');

        $this->assertSame('/p/a.php', $manager->cycle(1)?->path, 'wraps from c to a');
        $this->assertSame('/p/c.php', $manager->cycle(-1)?->path, 'wraps back');
    }

    public function testCycleWithFewerThanTwoBuffers(): void
    {
        $manager = new BufferManager();
        $this->assertNull($manager->cycle(1));

        $manager->open('/p/a.php', 'A');
        $this->assertNull($manager->cycle(1));
    }

    public function testCloseActiveActivatesMostRecent(): void
    {
        $manager = new BufferManager();
        $a = $manager->open('/p/a.php', 'A');
        $manager->open('/p/b.php', 'B');
        $c = $manager->open('/p/c.php', 'C');
        $manager->setActive('/p/a.php'); // recency: a, c, b

        $next = $manager->close('/p/a.php');
        $this->assertSame($c, $next, 'most recently focused survivor becomes active');
        $this->assertFalse($manager->has('/p/a.php'));
    }

    public function testCloseBackgroundKeepsActive(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');

        $this->assertSame($b, $manager->close('/p/a.php'));
        $this->assertSame($b, $manager->active());
    }

    public function testCloseUnknownPathReturnsActive(): void
    {
        $manager = new BufferManager();
        $a = $manager->open('/p/a.php', 'A');

        $this->assertSame($a, $manager->close('/p/never-opened.php'));
    }

    public function testCloseLastBufferReturnsNull(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');

        $this->assertNull($manager->close('/p/a.php'));
        $this->assertNull($manager->active());
        $this->assertSame([], $manager->all());
    }

    public function testRecentOrdersByFocus(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');
        $manager->open('/p/b.php', 'B');
        $manager->setActive('/p/a.php');

        $paths = array_map(static fn ($b) => $b->path, $manager->recent());
        $this->assertSame(['/p/a.php', '/p/b.php'], $paths);
    }

    public function testReorderMovesBufferPreservingObjectsAndActive(): void
    {
        $manager = new BufferManager();
        $a = $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');
        $c = $manager->open('/p/c.php', 'C');
        $manager->setActive('/p/a.php');

        $manager->reorder(0, 2); // a → end: [b, c, a]
        $this->assertSame([$b, $c, $a], $manager->all());
        $this->assertSame($a, $manager->active(), 'reordering does not change the active buffer');
        $this->assertSame('A', $a->document->getText(), 'Buffer object identity preserved');

        $manager->reorder(2, 0); // a → front: [a, b, c]
        $this->assertSame([$a, $b, $c], $manager->all());
    }

    public function testReorderClampsAndNoOps(): void
    {
        $manager = new BufferManager();
        $a = $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');

        $manager->reorder(0, 99); // clamp to last: [b, a]
        $this->assertSame([$b, $a], $manager->all());

        $manager->reorder(1, 1); // same index: no-op
        $this->assertSame([$b, $a], $manager->all());

        $manager->reorder(5, 0); // out-of-range source: no-op
        $this->assertSame([$b, $a], $manager->all());
    }

    public function testMoveActive(): void
    {
        $manager = new BufferManager();
        $a = $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');
        $c = $manager->open('/p/c.php', 'C');
        $manager->setActive('/p/b.php'); // active = b (index 1)

        $this->assertTrue($manager->moveActive(1)); // [a, c, b]
        $this->assertSame([$a, $c, $b], $manager->all());
        $this->assertTrue($manager->moveActive(-1)); // back to [a, b, c]
        $this->assertSame([$a, $b, $c], $manager->all());

        $manager->setActive('/p/a.php');
        $this->assertFalse($manager->moveActive(-1), 'already first: cannot move left');
        $manager->setActive('/p/c.php');
        $this->assertFalse($manager->moveActive(1), 'already last: cannot move right');
        $this->assertSame([$a, $b, $c], $manager->all(), 'failed moves leave order untouched');
    }

    public function testMoveActiveWithNoActiveBuffer(): void
    {
        $manager = new BufferManager();
        $this->assertFalse($manager->moveActive(1));
    }

    public function testAPaneTabCarriesItsIdAndRefreshesItsPayload(): void
    {
        $manager = new BufferManager();
        $buffer = $manager->openPane('/p/arch.dot', 'graph', 'digraph { A -> B; }');

        $this->assertSame('graph', $buffer->paneId);
        $this->assertSame('digraph { A -> B; }', $buffer->panePayload);
        $this->assertTrue($buffer->isViewer());
        $this->assertFalse($buffer->isDirty(), 'the empty backing document never dirties');

        $again = $manager->openPane('/p/arch.dot', 'graph', 'digraph { A; }');
        $this->assertSame($buffer, $again, 'path-keyed reuse');
        $this->assertSame('digraph { A; }', $again->panePayload, 're-opening refreshes the payload');
    }

    public function testTheViewerGuardIsTheOneKindQuestionCoreAsks(): void
    {
        // Whatever the pane is: a text buffer is not a viewer, a pane buffer is,
        // and no core caller needs to know WHICH pane to stay safe.
        $manager = new BufferManager();
        $this->assertFalse($manager->open('/p/a.php', 'A')->isViewer());
        $this->assertTrue($manager->openPane('/p/doc.md', 'help', '# hi')->isViewer());
        $this->assertTrue($manager->openPane('/p/g.dot', 'graph', 'digraph {}')->isViewer());
        $this->assertTrue($manager->openPane('/p/x.q', 'third-party', ['anything'])->isViewer(), 'a payload core cannot name is still a viewer');
    }

    public function testDirtyCount(): void
    {
        $manager = new BufferManager();
        $manager->open('/p/a.php', 'A');
        $b = $manager->open('/p/b.php', 'B');
        $this->assertSame(0, $manager->dirtyCount());

        $b->document->insert('x');
        $this->assertSame(1, $manager->dirtyCount());
    }
}
