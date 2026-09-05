<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\History\SearchScopePathListener;
use Porta\History\SearchScopeStore;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\TempProject;

/**
 * The translation is the whole point of this listener: the facts carry
 * absolute canonical paths, the scopes are project-relative.
 */
final class SearchScopePathListenerTest extends TestCase
{
    public function testAnAbsoluteRenameReachesTheScopeInRelativeTerms(): void
    {
        // In the production order: the fact is dispatched AFTER the rename,
        // with the old path resolved before it and the new one resolving now.
        // A test that renames nothing on disk gets an unresolvable `to` and a
        // listener that correctly refuses it.
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('src', ['src']);
        $from = (string) realpath($project->path('src'));
        rename($from, $project->path('lib'));

        $this->listener($project, $store)->onFileRenamed(new FileRenamed($from, (string) realpath($project->path('lib'))));

        $this->assertSame([['label' => 'lib', 'paths' => ['lib'], 'pinned' => true]], $store->all());
    }

    public function testAnAbsoluteDeleteReachesTheScopeInRelativeTerms(): void
    {
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('src', ['src']);

        $this->listener($project, $store)->onFileDeleted(new FileDeleted($project->path('src')));

        $this->assertSame([], $store->all());
    }

    public function testAPathOUTSIDETheProjectIsNotAScopeAndIsIgnored(): void
    {
        // relative() answers an ABSOLUTE path for one it cannot make relative,
        // and passing that through would have the store comparing an absolute
        // path against relative keys — matching nothing, but by accident.
        $project = new TempProject();
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('src', ['src']);
        $listener = $this->listener($project, $store);

        $listener->onFileRenamed(new FileRenamed('/elsewhere/old', '/elsewhere/new'));
        $listener->onFileDeleted(new FileDeleted('/elsewhere/gone'));

        $this->assertSame([['label' => 'src', 'paths' => ['src'], 'pinned' => true]], $store->all());
    }

    public function testARenameWithONLYONEEndInsideTheProjectIsIgnoredToo(): void
    {
        // A server's RenameFile can move a folder clean out of the workspace.
        // Half a translation is not a rename: there is no relative path to move
        // the scope TO, and the store speaks nothing else.
        $project = new TempProject(['src/A.php' => "<?php\n"]);
        $store = new SearchScopeStore($project->dir);
        $store->addPinned('src', ['src']);

        $this->listener($project, $store)->onFileRenamed(new FileRenamed((string) realpath($project->path('src')), '/elsewhere/lib'));

        $this->assertSame([['label' => 'src', 'paths' => ['src'], 'pinned' => true]], $store->all());
    }

    private function listener(TempProject $project, SearchScopeStore $store): SearchScopePathListener
    {
        return new SearchScopePathListener($store, new ProjectPaths($project->dir));
    }
}
