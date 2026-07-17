<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Search\SearchOptions;
use Porta\Search\SearchTabState;
use Porta\Ui\DockToolTab;
use Porta\Ui\DockToolTabs;
use Porta\Ui\ResultsPanelMode;
use Porta\Ui\ScopeKind;
use Porta\Widget\ResultsPanelFields;

final class DockToolTabsTest extends TestCase
{
    public function testOpenOrReuseAppendsPerTitleAndReusesPerIdentity(): void
    {
        $tabs = new DockToolTabs();
        $this->assertNull($tabs->active(), 'no tabs yet');
        $this->assertSame(0, $tabs->count());

        $foo = $tabs->openOrReuse(ResultsPanelMode::Search, 'foo', ResultsPanelFields::searchResults());
        $bar = $tabs->openOrReuse(ResultsPanelMode::Search, 'bar', ResultsPanelFields::searchResults());
        $this->assertSame(2, $tabs->count(), 'a different title appends');
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
        $todo = $tabs->openOrReuse(ResultsPanelMode::Todo, 'TODO', ResultsPanelFields::resultsOnly());

        $this->assertSame($foo, $tabs->find(ResultsPanelMode::Search, 'foo'));
        $this->assertNull($tabs->find(ResultsPanelMode::Search, 'missing'));
        $this->assertSame($todo, $tabs->findByMode(ResultsPanelMode::Todo));
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
        $this->assertSame(0, $tabs->count());
    }

    public function testASearchTabCarriesItsQueryDefinition(): void
    {
        $tabs = new DockToolTabs();
        $tab = $tabs->openOrReuse(ResultsPanelMode::Search, 'Find in Project: foo', ResultsPanelFields::searchResults());
        $tab->searchState = new SearchTabState('foo', new SearchOptions(regex: true), ['src'], '*.php', ScopeKind::Project);

        $this->assertSame('foo', $tab->searchState->query, 'the frozen definition rides the tab');
        $this->assertSame(['src'], $tab->searchState->scope);
    }
}
