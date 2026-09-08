<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\MultiSelection;

/**
 * The set a multi-select list keeps beside its cursor: what each gesture does
 * to it, and what a rebuild does.
 */
final class MultiSelectionTest extends TestCase
{
    public function testAPlainSelectionReplacesEverythingAndAnchorsThere(): void
    {
        $selection = new MultiSelection();
        $selection->toggle('/p/a.php');
        $selection->toggle('/p/b.php');

        $selection->only('/p/c.php');

        $this->assertSame(1, $selection->count());
        $this->assertTrue($selection->has('/p/c.php'));
        $this->assertFalse($selection->has('/p/a.php'), 'a plain click is not an addition');
        $this->assertSame('/p/c.php', $selection->anchor());
    }

    public function testToggleAddsThenRemovesAndAnchorsEitherWay(): void
    {
        $selection = new MultiSelection();
        $selection->only('/p/a.php');

        $selection->toggle('/p/b.php');
        $this->assertSame(2, $selection->count());
        $this->assertSame('/p/b.php', $selection->anchor());

        $selection->toggle('/p/b.php');
        $this->assertFalse($selection->has('/p/b.php'), 'the second toggle removes it');
        $this->assertTrue($selection->has('/p/a.php'), 'and leaves the rest alone');
        // The anchor is where the next range starts, which is the row last
        // acted on — not the row last added.
        $this->assertSame('/p/b.php', $selection->anchor());
    }

    public function testARangeReplacesRatherThanGrows(): void
    {
        $selection = new MultiSelection();
        $selection->only('/p/a.php');
        $selection->replaceRange(['/p/a.php', '/p/b.php', '/p/c.php']);

        $selection->replaceRange(['/p/a.php', '/p/b.php']);

        $this->assertSame(2, $selection->count(), 'shift-clicking back up shrinks the range');
        $this->assertFalse($selection->has('/p/c.php'));
        $this->assertSame('/p/a.php', $selection->anchor(), 'a range does not move the anchor it grew from');
    }

    public function testARebuildDropsPathsThatLeftTheList(): void
    {
        // The reason this is keyed by path: a tree rebuild moves every index
        // below a change, so an index-keyed set would quietly point at other
        // files. A file that is gone leaves the selection with it.
        $selection = new MultiSelection();
        $selection->toggle('/p/a.php');
        $selection->toggle('/p/gone.php');

        $selection->keepOnly(['/p/a.php', '/p/b.php']);

        $this->assertSame(1, $selection->count());
        $this->assertTrue($selection->has('/p/a.php'));
        $this->assertFalse($selection->has('/p/gone.php'));
        $this->assertNull($selection->anchor(), 'the anchor went with it, so the next range starts fresh');
    }

    public function testARebuildKeepsAnAnchorThatSurvived(): void
    {
        $selection = new MultiSelection();
        $selection->toggle('/p/a.php');

        $selection->keepOnly(['/p/a.php', '/p/b.php']);

        $this->assertSame('/p/a.php', $selection->anchor());
    }

    public function testClearingEmptiesBothTheSetAndTheAnchor(): void
    {
        $selection = new MultiSelection();
        $selection->only('/p/a.php');

        $selection->clear();

        $this->assertSame(0, $selection->count());
        $this->assertNull($selection->anchor());
    }
}
