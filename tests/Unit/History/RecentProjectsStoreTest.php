<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\RecentProjectsStore;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\TempProject;

/**
 * The project switcher's memory, which is GLOBAL — a list of projects kept
 * inside one project is a list you can only read from the place you no longer
 * want to be.
 */
final class RecentProjectsStoreTest extends TestCase
{
    use PreservesPortaHome;

    private TempProject $home;

    private function store(): RecentProjectsStore
    {
        // Pinned to a temp home BEFORE the store is built, and restored by the
        // trait afterwards: `phpunit.xml.dist` points PORTA_HOME at a COMMITTED
        // fixture, so a global write here would land in the repository.
        $this->home = new TempProject([]);
        putenv('PORTA_HOME='.$this->home->dir);

        return new RecentProjectsStore();
    }

    public function testRootsComeBackMostRecentFirstWithoutDuplicates(): void
    {
        $store = $this->store();

        $store->record('/projects/alpha');
        $store->record('/projects/beta');
        $store->record('/projects/alpha'); // revisited: moves to the front, not added twice

        $this->assertSame(['/projects/alpha', '/projects/beta'], $store->all());
    }

    /** Reopening the project you are already in is not a visit, and writes nothing. */
    public function testRecordingTheFrontEntryAgainIsANoOp(): void
    {
        $store = $this->store();
        $store->record('/projects/alpha');
        $file = $this->home->dir.'/.porta/recent-projects.json';
        $before = filemtime($file);

        $store->record('/projects/alpha');

        clearstatcache();
        $this->assertSame($before, filemtime($file), 'the file was not rewritten');
        $this->assertSame(['/projects/alpha'], $store->all());
    }

    public function testTheListIsCappedAndKeepsTheNewest(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 20; ++$i) {
            $store->record('/projects/p'.$i);
        }

        $roots = $store->all();

        $this->assertCount(16, $roots);
        $this->assertSame('/projects/p19', $roots[0], 'newest first');
        $this->assertNotContains('/projects/p0', $roots, 'the oldest fell off');
    }

    /**
     * A home pointed somewhere that does not exist yet still works: the store
     * creates its directory the way every other JSON store does.
     */
    public function testAHomeWithNoPortaDirectoryIsCreatedOnFirstWrite(): void
    {
        $store = $this->store();
        $this->assertFileDoesNotExist($this->home->dir.'/.porta/recent-projects.json');

        $store->record('/projects/alpha');

        $this->assertFileExists($this->home->dir.'/.porta/recent-projects.json');
    }

    /**
     * Neither PORTA_HOME nor HOME: nowhere to write, so the store is INERT
     * rather than throwing. The switcher then shows an empty list and says so,
     * which is a worse experience than remembering, not a broken one.
     */
    public function testWithNoHomeAtAllTheStoreRemembersNothingAndDoesNotThrow(): void
    {
        // HOME is restored by hand: putenv is process-global and paratest runs a
        // worker's methods in sequence, so unsetting it would follow every later
        // test in this worker. PreservesPortaHome covers its sibling; nothing
        // covers HOME because until now no test had reason to touch it.
        $home = getenv('HOME');
        putenv('PORTA_HOME');
        putenv('HOME');

        try {
            $store = new RecentProjectsStore();
            $store->record('/projects/alpha');

            $this->assertSame([], $store->all());
        } finally {
            putenv(false === $home ? 'HOME' : 'HOME='.$home);
        }
    }

    /** A corrupt or foreign file reads as empty rather than as garbage entries. */
    public function testAMalformedFileReadsAsEmpty(): void
    {
        $store = $this->store();
        $dir = $this->home->dir.'/.porta';
        mkdir($dir, permissions: 0o755, recursive: true);
        file_put_contents($dir.'/recent-projects.json', data: '{"roots": [42, null, "", {"nested": true}, "/projects/real"]}');

        $this->assertSame(['/projects/real'], $store->all(), 'only the usable entries survive');
    }
}
