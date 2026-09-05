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
        $tempProject = new TempProject();
        $this->assertSame([], new SearchScopeStore($tempProject->dir)->all());
    }

    public function testRecentScopesAreMostRecentFirstAndDeduplicated(): void
    {
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addRecent('src', ['src']);
        $store->addRecent('tests', ['tests']);
        $store->addRecent('src', ['src']); // moves src to the front

        $this->assertSame(['src', 'tests'], array_column($store->all(), 'label'));
    }

    public function testUnpinnedScopesEvictPastTheCap(): void
    {
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
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

    public function testPinFindsAnExistingEntryEvenWhenNotFirst(): void
    {
        // 'target' is NOT the first scope — a loop that wrongly stops at the
        // first non-match (instead of continuing past it) would never find
        // it, silently losing its pinned status below.
        $project = new TempProject([]);
        $project->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [
                    ['label' => 'other', 'paths' => ['o'], 'pinned' => false],
                    ['label' => 'target', 'paths' => ['t'], 'pinned' => true],
                ],
            ]),
        );

        new SearchScopeStore($project->dir)->addRecent('target', ['t2']);

        $reloaded = new SearchScopeStore($project->dir)->all();
        $target = $reloaded[array_search('target', array_column($reloaded, 'label'), strict: true)];
        $this->assertTrue($target['pinned'], 'a pin sticks even when re-recorded as recent');
    }

    public function testPinKeepsTheFirstMatchingEntryNotALaterDuplicate(): void
    {
        // Two entries share a label (constructible only via a raw file — the
        // public API always dedupes on write). The lookup must stop at the
        // FIRST match, not let a later one silently override it.
        $project = new TempProject([]);
        $project->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [
                    ['label' => 'dup', 'paths' => ['first'], 'pinned' => false],
                    ['label' => 'dup', 'paths' => ['second'], 'pinned' => true],
                ],
            ]),
        );

        new SearchScopeStore($project->dir)->addRecent('dup', ['new']);

        $reloaded = new SearchScopeStore($project->dir)->all();
        $this->assertFalse($reloaded[0]['pinned'], 'the first (unpinned) match won, not the second');
    }

    public function testANonArrayScopesValueOnAPresentFileIsIgnoredNotFatal(): void
    {
        // `null === $data` is false here (the file parses), so this only
        // exercises the second half of the guard — a non-array `scopes`.
        $project = new TempProject([]);
        $project->write('.porta/search-scopes.json', (string) json_encode(['scopes' => 'not-an-array']));

        $this->assertSame([], new SearchScopeStore($project->dir)->all());
    }

    public function testAnInvalidScopeEntryDoesNotAbortTheRestOfTheList(): void
    {
        // The invalid entry (missing 'paths') comes FIRST — a loop that
        // wrongly stops scanning would drop the valid entry after it.
        $project = new TempProject([]);
        $project->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [
                    ['label' => 'bad'], // missing paths
                    ['label' => 'ok', 'paths' => ['x']],
                ],
            ]),
        );

        $this->assertSame(['ok'], array_column(new SearchScopeStore($project->dir)->all(), 'label'));
    }

    public function testLoadedPathsStayContiguouslyIndexedAfterFilteringInvalidOnes(): void
    {
        // The invalid entries (7, '') sort BEFORE the valid one, so filtering
        // them out leaves a gap unless the result is reindexed — a gapped
        // list serializes to a JSON *object*, not an array, corrupting the
        // very next save/load round trip.
        $project = new TempProject([]);
        $project->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [['label' => 'ok', 'paths' => [7, 'src', '']]],
            ]),
        );

        $loaded = new SearchScopeStore($project->dir)->all();
        $this->assertSame(['src'], $loaded[0]['paths']);
        $this->assertSame('["src"]', json_encode($loaded[0]['paths']), 'stays a JSON array, not an object');
    }

    public function testLoadKeepsEveryValidScopeNotJustTheFirst(): void
    {
        $project = new TempProject([]);
        $project->write(
            '.porta/search-scopes.json',
            (string) json_encode([
                'scopes' => [
                    ['label' => 'a', 'paths' => ['a']],
                    ['label' => 'b', 'paths' => ['b']],
                ],
            ]),
        );

        $this->assertSame(['a', 'b'], array_column(new SearchScopeStore($project->dir)->all(), 'label'));
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

    public function testARenamedFolderTakesItsScopeWithIt(): void
    {
        // Both pinned, `src` added first: promote() unshifts, so the scope
        // that CHANGES ends up second and the walk has to carry on past one it
        // does not touch.
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addPinned('src', ['src']);
        $store->addPinned('tests', ['tests']);

        $store->rename('src', 'lib');

        $expected = [
            ['label' => 'tests', 'paths' => ['tests'], 'pinned' => true],
            ['label' => 'lib', 'paths' => ['lib'], 'pinned' => true],
        ];
        $this->assertSame($expected, $store->all(), 'the label was the path, so it moved; the untouched scope kept its place');
        $this->assertSame($expected, new SearchScopeStore($tempProject->dir)->all(), 'and the next session agrees');
    }

    public function testARenamedFolderMovesTheScopesNESTEDUnderItAndLeavesItsLabelAlone(): void
    {
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addPinned('the widgets', ['src/Widget', 'srcery/x']);

        $store->rename('src', 'lib');

        $this->assertSame(
            [
                ['label' => 'the widgets', 'paths' => ['lib/Widget', 'srcery/x'], 'pinned' => true],
            ],
            $store->all(),
            'a label the user chose is not a path and does not move; a prefix-sharing sibling is a different folder',
        );
    }

    public function testARenameThatTouchesNoScopeDoesNotRewriteTheFile(): void
    {
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addRecent('src', ['src']);
        $file = $tempProject->dir.'/.porta/search-scopes.json';
        touch($file, mtime: 1_000_000_000); // backdated: mtime resolves to the second
        clearstatcache();

        $store->rename('tests', 'spec');

        $this->assertSame(['src'], array_column($store->all(), 'label'));
        $this->assertSame(1_000_000_000, filemtime($file), 'no write for a rename that changed nothing');
    }

    public function testADeletedFolderLeavesTheScopeWithNowhereToSearch(): void
    {
        // Dropped rather than emptied: a scope with no paths searches the whole
        // project, which is the opposite of what its name promises.
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addPinned('gone', ['src/Old']);
        $store->addPinned('both', ['src/Old', 'tests']);
        $store->addRecent('kept', ['src/Older']);

        $store->forget('src/Old');

        $expected = [
            ['label' => 'both', 'paths' => ['tests'], 'pinned' => true],
            ['label' => 'kept', 'paths' => ['src/Older'], 'pinned' => false],
        ];
        $this->assertSame($expected, $store->all());
        $this->assertSame($expected, new SearchScopeStore($tempProject->dir)->all(), 'and the next session agrees');
    }

    public function testADeleteThatTouchesNoScopeDoesNotRewriteTheFile(): void
    {
        $tempProject = new TempProject();
        $store = new SearchScopeStore($tempProject->dir);
        $store->addRecent('src', ['src']);
        $file = $tempProject->dir.'/.porta/search-scopes.json';
        touch($file, mtime: 1_000_000_000);
        clearstatcache();

        $store->forget('tests');

        $this->assertSame(['src'], array_column($store->all(), 'label'));
        $this->assertSame(1_000_000_000, filemtime($file), 'no write for a delete that changed nothing');
    }
}
