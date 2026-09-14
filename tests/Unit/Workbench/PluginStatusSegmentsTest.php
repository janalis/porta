<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\PluginStatusSegments;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusSegmentSource;
use Porta\Workbench\StatusTone;

/** The plugins' single slot in the status bar's prefix. */
final class PluginStatusSegmentsTest extends TestCase
{
    /** @param list<string|StatusCell> $segments */
    private function slot(array $segments): PluginStatusSegments
    {
        return new PluginStatusSegments(new class($segments) implements StatusSegmentSource {
            /** @param list<string|StatusCell> $segments */
            public function __construct(
                private readonly array $segments,
            ) {}

            public function statusSegments(): array
            {
                return $this->segments;
            }
        });
    }

    public function testSeveralPluginsShareTheOneSlot(): void
    {
        // One tagged contributor for all of them, because the tag is a compiled
        // container list a plugin can never be in.
        $this->assertSame('▶ build · 3 ahead', $this->slot(['▶ build', '3 ahead'])->statusCells()[0]->text);
        $this->assertSame('▶ build', $this->slot(['▶ build'])->statusCells()[0]->text);
    }

    public function testNothingToSayIsNoCellSoTheBarReservesNoSpace(): void
    {
        // Not one empty cell: the provider skips a text-less cell entirely, and
        // an empty string would still cost the two-column separator after it.
        $this->assertSame([], $this->slot([])->statusCells());
    }

    public function testAPluginThatReturnsACellKeepsItsToneAndItsMenu(): void
    {
        // The reason this slot yields a LIST. Folding a cell into the shared
        // subtle one loses both, and both are what the git branch indicator is:
        // accent-toned, and right-clickable for the branch menu.
        $menu = static fn (): array => [];
        $cells = $this->slot([new StatusCell('⎇ main', StatusTone::Accent, $menu)])->statusCells();

        $this->assertCount(1, $cells);
        $this->assertSame('⎇ main', $cells[0]->text);
        $this->assertSame(StatusTone::Accent, $cells[0]->tone);
        $this->assertSame($menu, $cells[0]->menu);
    }

    public function testTheSharedTextCellPaintsBeforeEveryPluginOwnedOne(): void
    {
        // Order is contractual: the joined plain segments hold the position
        // they had when every plugin could only contribute text, so adding a
        // cell-returning plugin does not shuffle what was already there.
        $cells = $this->slot(['▶ build', new StatusCell('⎇ main', StatusTone::Accent), '3 ahead'])->statusCells();

        $this->assertSame(['▶ build · 3 ahead', '⎇ main'], \array_map(static fn (StatusCell $c): string => $c->text, $cells));
    }

    public function testEveryPluginSegmentPaintsInAnExistingRole(): void
    {
        // A plugin cannot choose a colour, and no theme gains an entry for
        // this — which is what keeps ThemeLockTest pinned.
        $this->assertSame(StatusTone::Subtle, $this->slot(['x'])->statusCells()[0]->tone, 'a plugin never picks its own prominence');
        $this->assertNull($this->slot(['x'])->statusCells()[0]->menu, 'nor makes its segment clickable');
    }
}
