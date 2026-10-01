<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteIndex;
use Porta\Filesystem\RemoteStamp;
use Porta\Tests\Support\TempProject;

/** What the server said of each path, kept across sessions. */
final class RemoteIndexTest extends TestCase
{
    public function testItRemembersFilesAndDirectoriesAcrossSessions(): void
    {
        $dir = new TempProject();
        $index = new RemoteIndex($dir->path('index.json'));

        $index->directory('src');
        $index->file('src/A.php', new RemoteStamp(12, 34), fetched: true);
        $index->file('B.php', new RemoteStamp(5, 6), fetched: false);
        $this->assertNull(new RemoteIndex($dir->path('index.json'))->get('src'), 'nothing is written before a save');
        $index->save();

        $again = new RemoteIndex($dir->path('index.json'));
        $this->assertSame(['dir' => true, 'size' => 0, 'mtime' => 0, 'fetched' => true], $again->get('src'));
        $this->assertEquals(new RemoteStamp(12, 34), $again->stamp('src/A.php'));
        $this->assertNull($again->stamp('B.php'), 'no content here: nothing it was fetched at');
        $this->assertNull($again->stamp('nope'));
        $this->assertTrue($again->isPlaceholder('B.php'));
        $this->assertFalse($again->isPlaceholder('src/A.php'));
        $this->assertFalse($again->isPlaceholder('src'));
        $this->assertFalse($again->isPlaceholder('nope'));
    }

    public function testMovingAndForgettingTakeEverythingUnder(): void
    {
        $dir = new TempProject();
        $index = new RemoteIndex($dir->path('index.json'));
        $index->directory('src');
        $index->file('src/A.php', new RemoteStamp(1, 1), fetched: true);
        $index->file('srcx.php', new RemoteStamp(1, 1), fetched: true);

        $index->move('src', 'lib');
        $index->save();
        $this->assertNotNull(new RemoteIndex($dir->path('index.json'))->get('lib/A.php'), 'a move is saved');

        $this->assertNull($index->get('src/A.php'));
        $this->assertNotNull($index->get('lib/A.php'));
        $this->assertNotNull($index->get('lib'));
        $this->assertNotNull($index->get('srcx.php'), 'a sibling sharing the prefix is not under it');

        $index->forget('lib');
        $index->save();

        $this->assertNull($index->get('lib'));
        $this->assertNull($index->get('lib/A.php'));
        $this->assertNotNull($index->get('srcx.php'));
        $this->assertNull(new RemoteIndex($dir->path('index.json'))->get('lib/A.php'), 'and it is saved');
    }

    public function testAMalformedFileReadsAsWhatCanBeRead(): void
    {
        $dir = new TempProject(['index.json' => '{"b": 3, "0": {"dir": true}, "a": {"dir": false, "size": "7", "fetched": true}}']);

        $index = new RemoteIndex($dir->path('index.json'));

        $this->assertSame(['dir' => false, 'size' => 0, 'mtime' => 0, 'fetched' => true], $index->get('a'));
        $this->assertNull($index->get('b'));
        $this->assertNull($index->get('0'), 'a numeric key is not a path it wrote');
        $this->assertNull(new RemoteIndex($dir->path('missing.json'))->get('a'));
    }

    /** A write before any read keeps what the file already held. */
    public function testRecordingFirstStillKeepsWhatWasSaved(): void
    {
        $dir = new TempProject();
        $first = new RemoteIndex($dir->path('index.json'));
        $first->directory('kept');
        $first->save();

        $second = new RemoteIndex($dir->path('index.json'));
        $second->directory('added');
        $second->save();

        $this->assertNotNull(new RemoteIndex($dir->path('index.json'))->get('kept'));
    }

    public function testMovedRerootsOnlyWhatIsUnder(): void
    {
        $this->assertSame('lib', RemoteIndex::moved('src', 'src', 'lib'));
        $this->assertSame('lib/a/b', RemoteIndex::moved('src/a/b', 'src', 'lib'));
        $this->assertNull(RemoteIndex::moved('srcx', 'src', 'lib'));
        $this->assertNull(RemoteIndex::moved('other', 'src', 'lib'));
    }
}
