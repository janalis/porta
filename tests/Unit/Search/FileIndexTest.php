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
        $this->assertSame(['a.php', 'b.php', 'sub/C.php'], \array_column($entries, 'path'), 'sorted by path');

        $sub = $entries[2];
        $this->assertSame('sub/c.php', $sub['lower']);
        $this->assertSame(\strlen('sub/'), $sub['boundary'], 'boundary is the start of the basename');
    }

    /**
     * A tree git ignores — a coverage report, a framework's cache — is not
     * walked: its files filled the index's budget and pushed the project's own
     * out (16,000 of 20,000 entries here, and 1,060 of src/ missing). A single
     * ignored file is still found: `.env` is opened by name.
     */
    public function testATreeGitIgnoresIsLeftOutAndAnIgnoredFileIsNot(): void
    {
        $project = new TempProject(['.gitignore' => "/var/\n.env\n", '.env' => 'X=1', 'src/A.php' => '', 'var/cache/Container.php' => '']);

        $this->assertSame(['.env', '.gitignore', 'src/A.php'], \array_column(new FileIndex($project->dir)->entries(), 'path'));
    }

    /** "Search vendor/ files" reaches into vendor/ even where git ignores it, as it nearly always does. */
    public function testTheVendorSettingReachesAnIgnoredVendor(): void
    {
        $project = new TempProject(['.gitignore' => "/vendor/\n", 'a.php' => '', 'vendor/pkg/Lib.php' => '']);
        $index = new FileIndex($project->dir);
        $index->setIncludeVendor(true);

        $this->assertSame(['.gitignore', 'a.php', 'vendor/pkg/Lib.php'], \array_column($index->entries(), 'path'));
    }

    /** A directory nobody may list — a root-owned Docker volume — is passed over, not the end of Go to File. */
    public function testAnUnreadableDirectoryIsPassedOver(): void
    {
        $project = new TempProject(['a.php' => '']);
        if (!$project->lock('docker/db')) {
            $this->markTestSkipped('this user reads every directory');
        }

        $this->assertSame(['a.php'], \array_column(new FileIndex($project->dir)->entries(), 'path'));
    }

    public function testExcludesVcsAndDependencyDirs(): void
    {
        $project = new TempProject([
            'a.php' => '',
            '.git/config' => 'x',
            '.porta/history/ab/1.snap' => 'x',
            '.porta/plugins/acme/porta.php' => '',
            'node_modules/lib.js' => 'x',
            '.idea/x.iml' => 'y',
            'vendor/pkg/Lib.php' => 'z',
        ]);

        $paths = \array_column(new FileIndex($project->dir)->entries(), 'path');

        $this->assertContains('a.php', $paths);
        $this->assertNotContains('.git/config', $paths);
        $this->assertStringNotContainsString('node_modules', \implode('|', $paths));
        $this->assertStringNotContainsString('.idea', \implode('|', $paths));
        $this->assertStringNotContainsString('.porta/history', \implode('|', $paths), "local history's snapshots are Porta's, not the project's");
        $this->assertContains('.porta/plugins/acme/porta.php', $paths, "a project's own plugin is the user's code");
        $this->assertStringNotContainsString('vendor', \implode('|', $paths), 'vendor/ can eat the whole file budget by itself');
    }

    public function testTheVendorSettingReScopesTheIndexInPlace(): void
    {
        $project = new TempProject(['a.php' => '', 'vendor/pkg/Lib.php' => 'z']);
        $index = new FileIndex($project->dir);
        $this->assertSame(['a.php'], \array_column($index->entries(), 'path'));

        $index->setIncludeVendor(true);
        $this->assertContains('vendor/pkg/Lib.php', \array_column($index->entries(), 'path'), 'enabling the setting re-walks with vendor/ in scope');

        $index->setIncludeVendor(true); // unchanged: must not drop the cache
        $this->assertContains('vendor/pkg/Lib.php', \array_column($index->entries(), 'path'));

        $index->setIncludeVendor(false);
        $this->assertSame(['a.php'], \array_column($index->entries(), 'path'), 'disabling narrows back');
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

    public function testSearchTiesOnScoreBreakByShorterPath(): void
    {
        // Same basename match pattern relative to its own boundary, and both
        // total lengths fall in the same /10 length-penalty bucket (10 and 12
        // chars) — the fuzzy score itself ties, so only the explicit length
        // tie-break in FileIndex::search() can order them. The shorter path
        // ("z/...") is deliberately alphabetically AFTER the longer one
        // ("aaa/...highlighted"), so a walk-order-preserving stable sort
        // (i.e. the tie-break silently removed) would get this backwards.
        $project = new TempProject(['aaa/User.php' => '', 'z/User.php' => '']);
        $index = new FileIndex($project->dir);

        $this->assertSame(['z/User.php', 'aaa/User.php'], $index->search('user', 2), 'tied scores fall back to the shorter path first');
    }

    public function testRefreshRewalks(): void
    {
        $project = new TempProject(['a.php' => '']);
        $index = new FileIndex($project->dir);
        $this->assertSame(['a.php'], \array_column($index->entries(), 'path'));

        \file_put_contents($project->path('b.php'), data: '');
        $this->assertNotContains('b.php', \array_column($index->entries(), 'path'), 'cached until refreshed');

        $index->refresh();
        $this->assertContains('b.php', \array_column($index->entries(), 'path'));
    }

    /** Go to File folds every letter: été finds Été.md. */
    public function testGoToFileFoldsEveryLetter(): void
    {
        $project = new TempProject(['docs/Été.md' => '', 'docs/ete.md' => '']);

        $this->assertSame(['docs/Été.md'], new FileIndex($project->dir)->search('été', limit: 10));
        $this->assertSame(['docs/Été.md'], new FileIndex($project->dir)->search('ÉTÉ', limit: 10), 'the query folds as well as the path');
    }
}
