<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\SearchPrefsStore;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Ui\ScopeKind;

final class SearchPrefsStoreTest extends TestCase
{
    public function testDefaultsToWholeProjectAndNoMask(): void
    {
        $store = new SearchPrefsStore(new TempProject([])->dir);

        $this->assertSame(ScopeKind::Project, $store->lastScope());
        $this->assertSame('', $store->lastMask());
        $this->assertSame([], Probe::get($store, 'recentMasks'));
    }

    public function testPersistsScopeAndMaskAcrossInstances(): void
    {
        $project = new TempProject([]);
        $first = new SearchPrefsStore($project->dir);
        $first->setScope(ScopeKind::OpenFiles);
        $first->setMask('*.php');

        $reloaded = new SearchPrefsStore($project->dir);
        $this->assertSame(ScopeKind::OpenFiles, $reloaded->lastScope());
        $this->assertSame('*.php', $reloaded->lastMask());
    }

    public function testRecentMasksAreMostRecentFirstAndDeduplicated(): void
    {
        $store = new SearchPrefsStore(new TempProject([])->dir);
        $store->addRecentMask('*.php');
        $store->addRecentMask('*.js');
        $store->addRecentMask('*.php'); // moves *.php back to the front

        $this->assertSame(['*.php', '*.js'], Probe::get($store, 'recentMasks'));
    }

    public function testBlankRecentMasksAreIgnored(): void
    {
        $store = new SearchPrefsStore(new TempProject([])->dir);
        $store->addRecentMask('   ');
        $store->addRecentMask('');

        $this->assertSame([], Probe::get($store, 'recentMasks'));
    }

    public function testRecentMasksCapAtTwenty(): void
    {
        $store = new SearchPrefsStore(new TempProject([])->dir);
        for ($i = 0; $i < 25; ++$i) {
            $store->addRecentMask("*.x{$i}");
        }

        $this->assertCount(20, Probe::get($store, 'recentMasks'));
        $this->assertSame('*.x24', Probe::get($store, 'recentMasks')[0], 'newest first');
    }

    public function testToleratesAnUnwritableLocation(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), prefix: 'porta-prefs-');

        try {
            $store = new SearchPrefsStore($file);
            $store->setMask('*.php'); // dumpFile throws → caught, in-memory value still set
            $store->addRecentMask('*.php');
            $this->assertSame('*.php', $store->lastMask());
            $this->assertSame(['*.php'], Probe::get($store, 'recentMasks'));
        } finally {
            is_file($file) && unlink($file);
        }
    }

    public function testSetScopePersists(): void
    {
        $project = new TempProject([]);
        new SearchPrefsStore($project->dir)->setScope(ScopeKind::OpenFiles);

        $this->assertSame(ScopeKind::OpenFiles, new SearchPrefsStore($project->dir)->lastScope());
    }

    public function testLoadedRecentMasksAreCappedFromTheStart(): void
    {
        // A raw file with more than MAX_MASKS entries: the loader must keep
        // the FIRST 20 (offset 0), not the last one (an `offset: -1` bug
        // would collapse this to a single entry).
        $project = new TempProject([]);
        $raw = array_map(static fn (int $i): string => "*.x{$i}", range(start: 0, end: 24));
        $project->write('.porta/search-prefs.json', (string) json_encode(['recentMasks' => $raw]));

        $loaded = Probe::get(new SearchPrefsStore($project->dir), 'recentMasks');
        $this->assertCount(20, $loaded);
        $this->assertSame('*.x0', $loaded[0]);
        $this->assertSame('*.x19', $loaded[19]);
    }

    public function testToleratesAbsentMalformedAndInvalidValues(): void
    {
        // Absent file → defaults.
        $this->assertSame(ScopeKind::Project, new SearchPrefsStore(new TempProject([])->dir)->lastScope());

        // Malformed JSON → defaults.
        $bad = new TempProject([]);
        $bad->write('.porta/search-prefs.json', 'not json');
        $this->assertSame('', new SearchPrefsStore($bad->dir)->lastMask());

        // An unknown scope string falls back to the whole project; non-string masks are dropped.
        $mixed = new TempProject([]);
        $mixed->write(
            '.porta/search-prefs.json',
            (string) json_encode([
                'scope' => 'bogus',
                'mask' => 42,
                'recentMasks' => ['*.php', 7, '', null],
            ]),
        );
        $store = new SearchPrefsStore($mixed->dir);
        $this->assertSame(ScopeKind::Project, $store->lastScope());
        $this->assertSame('', $store->lastMask(), 'a non-string mask is ignored');
        $this->assertSame(['*.php'], Probe::get($store, 'recentMasks'));
    }

    public function testAnUnencodableMaskStaysLiveAndPersistenceDegradesSilently(): void
    {
        $project = new TempProject();
        $store = new SearchPrefsStore($project->dir);

        $store->setMask("*.\xB1");

        $this->assertSame("*.\xB1", $store->lastMask(), 'the live pref keeps the mask');
        $this->assertFalse(is_file($project->path('.porta/search-prefs.json')), 'the file write degraded silently');
    }
}
