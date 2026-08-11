<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\SearchScopeStore;
use Porta\Tests\Support\TempProject;

final class SearchScopeStoreTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $this->assertSame([], new SearchScopeStore(new TempProject([])->dir)->all());
    }

    public function testRecentScopesAreMostRecentFirstAndDeduplicated(): void
    {
        $store = new SearchScopeStore(new TempProject([])->dir);
        $store->addRecent('src', ['src']);
        $store->addRecent('tests', ['tests']);
        $store->addRecent('src', ['src']); // moves src to the front

        $this->assertSame(['src', 'tests'], array_column($store->all(), 'label'));
    }

    public function testUnpinnedScopesEvictPastTheCap(): void
    {
        $store = new SearchScopeStore(new TempProject([])->dir);
        for ($i = 0; $i < 12; ++$i) {
            $store->addRecent("dir{$i}", ["dir{$i}"]);
        }

        $this->assertCount(10, $store->all(), 'recent scopes cap at 10');
        $this->assertSame('dir11', $store->all()[0]['label'], 'newest first');
    }

    public function testPinnedScopesAreKeptFirstAndNeverEvicted(): void
    {
        $project = new TempProject([]);
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('lib', ['lib']);
        for ($i = 0; $i < 12; ++$i) {
            $store->addRecent("dir{$i}", ["dir{$i}"]);
        }

        $labels = array_column($store->all(), 'label');
        $this->assertContains('lib', $labels, 'the pinned scope survives eviction');
        $this->assertSame('lib', $labels[0], 'pinned scopes come first');
        $this->assertCount(11, $labels, '10 recent + 1 pinned');

        // A pin sticks even when the same label is later recorded as recent.
        $store->addRecent('lib', ['lib']);
        $lib = array_values(array_filter($store->all(), static fn (array $s): bool => 'lib' === $s['label']))[0];
        $this->assertTrue($lib['pinned']);
    }

    public function testPersistsAcrossInstancesAndToleratesBadFiles(): void
    {
        $project = new TempProject([]);
        new SearchScopeStore($project->dir)->addPinned('api', ['api', 'api-tests']);

        $reloaded = new SearchScopeStore($project->dir)->all();
        $this->assertSame('api', $reloaded[0]['label']);
        $this->assertSame(['api', 'api-tests'], $reloaded[0]['paths']);
        $this->assertTrue($reloaded[0]['pinned']);

        // Malformed / partial entries are dropped.
        $bad = new TempProject([]);
        $bad->write('.porta/search-scopes.json', 'not json');
        $this->assertSame([], new SearchScopeStore($bad->dir)->all());

        $mixed = new TempProject([]);
        $mixed->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [
                    ['label' => 'ok', 'paths' => ['src', 7, ''], 'pinned' => true],
                    ['label' => 'no-paths', 'paths' => []],
                    ['paths' => ['x']], // missing label
                    'garbage',
                ],
            ]),
        );
        $kept = new SearchScopeStore($mixed->dir)->all();
        $this->assertSame([['label' => 'ok', 'paths' => ['src'], 'pinned' => true]], $kept);
    }

    public function testAddToleratesAnUnwritableLocation(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), prefix: 'porta-scopes-');

        try {
            $store = new SearchScopeStore($file);
            $store->addRecent('src', ['src']); // dumpFile throws → caught, in-memory list still updated
            $this->assertSame(['src'], array_column($store->all(), 'label'));
        } finally {
            is_file($file) && unlink($file);
        }
    }

    public function testAnUnencodableLabelStaysLiveAndPersistenceDegradesSilently(): void
    {
        $project = new TempProject();
        $store = new SearchScopeStore($project->dir);

        $store->addRecent("scope\xB1", ['/p/src']);

        $this->assertCount(1, $store->all(), 'the live list keeps the scope');
        $this->assertFalse(is_file($project->path('.porta/search-scopes.json')), 'the file write degraded silently');
    }
}
