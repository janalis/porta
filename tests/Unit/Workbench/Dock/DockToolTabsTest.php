<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Search\SearchOptions;
use Porta\Search\SearchTabState;
use Porta\Tests\Support\Probe;
use Porta\Ui\ScopeKind;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Workbench\Dock\DockToolHandlers;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\DockToolTabs;
use Porta\Workbench\Dock\ResultsPanelMode;

final class DockToolTabsTest extends TestCase
{
    public function testOpenOrReuseAppendsPerTitleAndReusesPerIdentity(): void
    {
        $tabs = new DockToolTabs();
        $this->assertNull($tabs->active(), 'no tabs yet');
        $this->assertSame(0, \count(Probe::get($tabs, 'tabs')));

        $foo = $tabs->openOrReuse(ResultsPanelMode::Search, 'foo', ResultsPanelFields::searchResults());
        $bar = $tabs->openOrReuse(ResultsPanelMode::Search, 'bar', ResultsPanelFields::searchResults());
        $this->assertSame(2, \count(Probe::get($tabs, 'tabs')), 'a different title appends');
        $this->assertSame($bar, $tabs->active(), 'the newest tab is active');
        $this->assertSame([1, 2], array_map(static fn (DockToolTab $tab): int => $tab->id, $tabs->all()), 'ids are monotonic');

        $again = $tabs->openOrReuse(ResultsPanelMode::Search, 'foo', ResultsPanelFields::resultsOnly());
        $this->assertSame($foo, $again, 'the same (mode, title) reuses its tab');
        $this->assertSame($foo, $tabs->active(), 'and re-activates it');
        $this->assertFalse($foo->fields->grouped, 'the fields refresh on reuse');

        $problems = $tabs->openOrReuse(ResultsPanelMode::Problems, 'foo', ResultsPanelFields::resultsOnly());
        $this->assertNotSame($foo, $problems, 'the same title under another mode is a different tool');
    }

    public function testLookupsAndActivation(): void
    {
        $tabs = new DockToolTabs();
        $foo = $tabs->openOrReuse(ResultsPanelMode::Search, 'foo', ResultsPanelFields::searchResults());
        $todo = $tabs->openOrReuse(ResultsPanelMode::Notifications, 'Notifications', ResultsPanelFields::resultsOnly());

        // find() is private (its public face is openOrReuse's identity rule):
        // reusing the same (mode, title) returns the same tab, a new title appends.
        $this->assertSame($foo, $tabs->openOrReuse(ResultsPanelMode::Search, 'foo', ResultsPanelFields::searchResults()));
        $this->assertNotSame($foo, $tabs->openOrReuse(ResultsPanelMode::Search, 'missing', ResultsPanelFields::searchResults()));
        $this->assertSame($todo, $tabs->findByMode(ResultsPanelMode::Notifications));
        $this->assertNull($tabs->findByMode(ResultsPanelMode::Problems));
        $this->assertSame($foo, $tabs->findById(1));
        $this->assertNull($tabs->findById(99));
        $this->assertTrue($tabs->contains($foo));

        $tabs->makeActive($foo);
        $this->assertSame($foo, $tabs->active());
        $tabs->makeActive(new DockToolTab(50, ResultsPanelMode::Search, 'stray', ResultsPanelFields::resultsOnly()));
        $this->assertSame($foo, $tabs->active(), 'a stray tab cannot take the pointer');
    }

    public function testCloseMovesTheActivePointerToTheNearestNeighbour(): void
    {
        $tabs = new DockToolTabs();
        $a = $tabs->openOrReuse(ResultsPanelMode::Search, 'a', ResultsPanelFields::searchResults());
        $b = $tabs->openOrReuse(ResultsPanelMode::Search, 'b', ResultsPanelFields::searchResults());
        $c = $tabs->openOrReuse(ResultsPanelMode::Search, 'c', ResultsPanelFields::searchResults());

        $tabs->makeActive($b);
        $this->assertSame($b, $tabs->close($c), 'closing behind the active tab keeps it');
        $this->assertSame($a, $tabs->close($b), 'closing the active tab falls to its neighbour');
        $this->assertFalse($tabs->contains($b));

        $this->assertSame($a, $tabs->close($c), 'closing an unknown tab is a no-op');
        $this->assertNull($tabs->close($a), 'the last close empties the strip');
        $this->assertSame(0, \count(Probe::get($tabs, 'tabs')));
    }

    /**
     * Closing the currently-active tab with several survivors: the active pointer
     * must stay at the SAME index (so it lands on the tab that slides into the
     * closed slot), not shift to the previous one — a distinction the 2-survivor
     * case in testCloseMovesTheActivePointerToTheNearestNeighbour can't see, since
     * both would clamp to the same lone survivor there.
     */
    public function testCloseOnTheActiveTabLandsOnItsSuccessorNotItsPredecessor(): void
    {
        $tabs = new DockToolTabs();
        $a = $tabs->openOrReuse(ResultsPanelMode::Search, 'a', ResultsPanelFields::searchResults());
        $b = $tabs->openOrReuse(ResultsPanelMode::Search, 'b', ResultsPanelFields::searchResults());
        $c = $tabs->openOrReuse(ResultsPanelMode::Search, 'c', ResultsPanelFields::searchResults());
        $tabs->openOrReuse(ResultsPanelMode::Search, 'd', ResultsPanelFields::searchResults());

        $tabs->makeActive($b);

        $this->assertSame($c, $tabs->close($b), 'the tab sliding into the closed slot becomes active, not $a');
    }

    /**
     * Closing a tab BEFORE the active one, with survivors on both sides of the
     * active tab: the active index must shift down by exactly 1 to track its
     * tab through the reindex — not stay put, not shift by 2. Chosen so the
     * trailing min(..., count-1) clamp cannot coincidentally mask either bug.
     */
    public function testCloseBeforeTheActiveTabShiftsItsIndexByExactlyOne(): void
    {
        $tabs = new DockToolTabs();
        $a = $tabs->openOrReuse(ResultsPanelMode::Search, 'a', ResultsPanelFields::searchResults());
        $c = $tabs->openOrReuse(ResultsPanelMode::Search, 'c', ResultsPanelFields::searchResults());
        $tabs->openOrReuse(ResultsPanelMode::Search, 'd', ResultsPanelFields::searchResults());
        $tabs->openOrReuse(ResultsPanelMode::Search, 'e', ResultsPanelFields::searchResults());

        $tabs->makeActive($c);

        $this->assertSame($c, $tabs->close($a), 'the active tab is tracked, not swapped for its neighbour');
    }

    public function testMoveReordersKeepingTheActivePointerOnItsTab(): void
    {
        $tabs = new DockToolTabs();
        $tabs->openOrReuse(ResultsPanelMode::Search, 'a', ResultsPanelFields::searchResults());
        $b = $tabs->openOrReuse(ResultsPanelMode::Search, 'b', ResultsPanelFields::searchResults());
        $tabs->openOrReuse(ResultsPanelMode::Search, 'c', ResultsPanelFields::searchResults());
        $d = $tabs->openOrReuse(ResultsPanelMode::Search, 'd', ResultsPanelFields::searchResults()); // active
        $order = static fn (): array => array_map(static fn (DockToolTab $tab): string => $tab->title, $tabs->all());

        $tabs->move(9, 0);
        $this->assertSame(['a', 'b', 'c', 'd'], $order(), 'an out-of-range source is a no-op');
        $tabs->move(1, 1);
        $this->assertSame(['a', 'b', 'c', 'd'], $order(), 'a same-slot move is a no-op');

        $tabs->move(3, 0); // the active tab itself moves
        $this->assertSame(['d', 'a', 'b', 'c'], $order());
        $this->assertSame($d, $tabs->active(), 'the pointer follows the moved tab');

        $tabs->move(1, 99); // 'a' to the end (clamped); the active stays in front
        $this->assertSame(['d', 'b', 'c', 'a'], $order());
        $this->assertSame($d, $tabs->active());

        $tabs->makeActive($b);
        $tabs->move(0, 2); // a tab from before the active one passes it
        $this->assertSame(['b', 'c', 'd', 'a'], $order());
        $this->assertSame($b, $tabs->active());

        $tabs->move(3, 0); // a tab from behind lands in front
        $this->assertSame(['a', 'b', 'c', 'd'], $order());
        $this->assertSame($b, $tabs->active());
    }

    public function testASearchTabCarriesItsQueryDefinition(): void
    {
        $tabs = new DockToolTabs();
        $tab = $tabs->openOrReuse(ResultsPanelMode::Search, 'Find in Project: foo', ResultsPanelFields::searchResults());
        $tab->searchState = new SearchTabState('foo', new SearchOptions(regex: true), ['src'], '*.php', ScopeKind::Project);

        $this->assertSame('foo', $tab->searchState->query, 'the frozen definition rides the tab');
        $this->assertSame(['src'], $tab->searchState->scope);
    }

    public function testAContributedToolIsKeyedByItsIdAloneAndNoModeCanReachIt(): void
    {
        // The whole reason a contributed tab carries a null mode: findByMode()
        // returns the FIRST tab in a mode, so a shared "extension" case would
        // let a core caller bind, refresh or clobber somebody's plugin tab. A
        // null matches no case at all.
        $tabs = new DockToolTabs();
        $core = $tabs->openOrReuse(ResultsPanelMode::Problems, 'Problems', ResultsPanelFields::resultsOnly());
        $mine = $tabs->openOrReuse(null, 'TODO', ResultsPanelFields::resultsOnly(), new DockToolHandlers('todo:todo'));
        $theirs = $tabs->openOrReuse(null, 'TODO', ResultsPanelFields::resultsOnly(), new DockToolHandlers('other:todo'));

        $this->assertNotSame($mine, $theirs, 'same title, different plugins, different tabs');
        $this->assertSame($mine, $tabs->findByToolId('todo:todo'));
        $this->assertNull($tabs->findByToolId('nobody:nothing'), 'a ×\'d tool answers null — the staleness guard');
        $this->assertSame($core, $tabs->findByMode(ResultsPanelMode::Problems), 'core tabs still resolve by mode');
        foreach (ResultsPanelMode::cases() as $mode) {
            $this->assertNotSame($mine, $tabs->findByMode($mode), $mode->name.' must not reach a contributed tab');
        }

        $reused = $tabs->openOrReuse(null, 'TODO (renamed)', ResultsPanelFields::resultsOnly(), new DockToolHandlers('todo:todo'));
        $this->assertSame($mine, $reused, 'the id reuses the tab; the title is only the plugin\'s label');
        $this->assertSame('TODO', $reused->title, 'and the title it was created with stands');
    }
}
