<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\SearchHistoryStore;
use Porta\Tests\Support\TempProject;

final class SearchHistoryStoreTest extends TestCase
{
    public function testAddRecordsMostRecentFirstAndDeduplicates(): void
    {
        $store = new SearchHistoryStore(new TempProject([])->dir);
        $store->add('foo');
        $store->add('bar');
        $store->add('foo'); // moves foo back to the front

        $this->assertSame(['foo', 'bar'], $store->all());
    }

    public function testBlankQueriesAreIgnored(): void
    {
        $store = new SearchHistoryStore(new TempProject([])->dir);
        $store->add('   ');
        $store->add('');

        $this->assertSame([], $store->all());
    }

    public function testCapsAtTwentyEntries(): void
    {
        $store = new SearchHistoryStore(new TempProject([])->dir);
        for ($i = 0; $i < 25; ++$i) {
            $store->add("q{$i}");
        }

        $this->assertCount(20, $store->all());
        $this->assertSame('q24', $store->all()[0], 'newest first');
        $this->assertSame('q5', $store->all()[19], 'oldest of the kept 20');
    }

    public function testPersistsAcrossInstances(): void
    {
        $project = new TempProject([]);
        $first = new SearchHistoryStore($project->dir);
        $first->add('alpha');
        $first->add('beta');

        $this->assertSame(['beta', 'alpha'], new SearchHistoryStore($project->dir)->all());
    }

    public function testAddToleratesAnUnwritableLocation(): void
    {
        // A regular file as the "root" → `<file>/.porta/...` cannot be written.
        $file = (string) tempnam(sys_get_temp_dir(), prefix: 'porta-hist-');

        try {
            $store = new SearchHistoryStore($file);
            $store->add('x'); // dumpFile throws → caught, in-memory list still updated
            $this->assertSame(['x'], $store->all());
        } finally {
            is_file($file) && unlink($file);
        }
    }

    public function testToleratesAbsentAndMalformedFiles(): void
    {
        // Absent file → empty.
        $this->assertSame([], new SearchHistoryStore(new TempProject([])->dir)->all());

        // Malformed JSON → empty.
        $bad = new TempProject([]);
        $bad->write('.porta/search-history.json', 'not json');
        $this->assertSame([], new SearchHistoryStore($bad->dir)->all());

        // Non-string entries are filtered out.
        $mixed = new TempProject([]);
        $mixed->write('.porta/search-history.json', (string) json_encode(['queries' => ['ok', 123, '', null]]));
        $this->assertSame(['ok'], new SearchHistoryStore($mixed->dir)->all());
    }

    public function testAnInvalidEntryDoesNotAbortTheRestOfTheList(): void
    {
        // The invalid entry comes FIRST here — a loop that wrongly stops
        // scanning (instead of skipping just that one entry) would drop the
        // valid entry that follows it.
        $project = new TempProject([]);
        $project->write('.porta/search-history.json', (string) json_encode(['queries' => [123, 'ok']]));

        $this->assertSame(['ok'], new SearchHistoryStore($project->dir)->all());
    }

    public function testANonArrayQueriesValueOnAPresentFileIsIgnoredNotFatal(): void
    {
        // `null === $data` is false here (the file parses), so this only
        // exercises the second half of the guard — a non-array `queries`.
        $project = new TempProject([]);
        $project->write('.porta/search-history.json', (string) json_encode(['queries' => 'not-an-array']));

        $this->assertSame([], new SearchHistoryStore($project->dir)->all());
    }

    public function testAnUnencodableQueryStaysLiveAndPersistenceDegradesSilently(): void
    {
        // Searching for an invalid-UTF-8 needle must not crash the IDE:
        // json_encode(JSON_THROW_ON_ERROR) throws, the widened catch keeps
        // save() best-effort, and the in-memory history still works.
        $project = new TempProject();
        $store = new SearchHistoryStore($project->dir);

        $store->add("needle\xB1");

        $this->assertSame(["needle\xB1"], $store->all(), 'the live list keeps the query');
        $this->assertFalse(is_file($project->path('.porta/search-history.json')), 'the file write degraded silently');
    }
}
