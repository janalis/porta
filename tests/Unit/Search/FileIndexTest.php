<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\FileIndex;
use Porta\Tests\Support\TempProject;

final class FileIndexTest extends TestCase
{
    public function testEntriesAreSortedWithLoweredPathAndBasenameBoundary(): void
    {
        $project = new TempProject(['b.php' => '', 'a.php' => '', 'sub/C.php' => '']);
        $index = new FileIndex($project->dir);

        $entries = $index->entries();
        $this->assertSame(['a.php', 'b.php', 'sub/C.php'], array_column($entries, 'path'), 'sorted by path');

        $sub = $entries[2];
        $this->assertSame('sub/c.php', $sub['lower']);
        $this->assertSame(\strlen('sub/'), $sub['boundary'], 'boundary is the start of the basename');
    }

    public function testExcludesVcsAndDependencyDirs(): void
    {
        $project = new TempProject(['a.php' => '', '.git/config' => 'x', 'node_modules/lib.js' => 'x', '.idea/x.iml' => 'y']);

        $paths = array_column(new FileIndex($project->dir)->entries(), 'path');

        $this->assertContains('a.php', $paths);
        $this->assertNotContains('.git/config', $paths);
        $this->assertStringNotContainsString('node_modules', implode('|', $paths));
        $this->assertStringNotContainsString('.idea', implode('|', $paths));
    }

    public function testSearchEmptyQueryReturnsTheFirstFilesUpToTheLimit(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '', 'c.php' => '']);

        $this->assertSame(['a.php', 'b.php'], new FileIndex($project->dir)->search('', 2));
    }

    public function testSearchFuzzyRanksPrefersTheShortestBasenameMatchAndCaps(): void
    {
        $project = new TempProject([
            'src/Controller/UserController.php' => '',
            'src/Entity/User.php' => '',
            'assets/user-avatar-tools.txt' => '',
            'other.php' => '',
        ]);
        $index = new FileIndex($project->dir);

        $results = $index->search('user', 8);

        $this->assertSame('src/Entity/User.php', $results[0], 'shortest basename match wins');
        $this->assertNotContains('other.php', $results, 'non-matches are excluded');

        $this->assertCount(1, $index->search('user', 1), 'capped at the limit');
    }

    public function testRefreshRewalks(): void
    {
        $project = new TempProject(['a.php' => '']);
        $index = new FileIndex($project->dir);
        $this->assertSame(['a.php'], array_column($index->entries(), 'path'));

        file_put_contents($project->path('b.php'), data: '');
        $this->assertNotContains('b.php', array_column($index->entries(), 'path'), 'cached until refreshed');

        $index->refresh();
        $this->assertContains('b.php', array_column($index->entries(), 'path'));
    }
}
