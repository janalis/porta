<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Debug\DebugStateStore;
use Porta\History\BookmarkStore;
use Porta\History\RecentFilesStore;
use Porta\History\SearchHistoryStore;
use Porta\History\SearchPrefsStore;
use Porta\History\SearchScopeStore;
use Porta\History\SessionStore;
use Porta\Onboarding\OnboardingState;
use Porta\Support\Files;
use Porta\Tests\Support\TempProject;

/**
 * What Porta keeps of a project, in its `.porta/`, comes with a clone like the
 * rest of it — and a link there would aim Porta's own writes anywhere:
 * `.porta/session.json -> ~/.bashrc` has the session written over the user's
 * shell on a quit. Every store of a project's state, swept: neither a link
 * at the file nor a `.porta/` that is one is written, or emptied, through.
 */
final class ProjectStateLinksTest extends TestCase
{
    private const string KEPT = "export A=1\n";

    /** @return iterable<string, array{string, \Closure(string): mixed}> */
    public static function stores(): iterable
    {
        yield 'layout' => ['layout.json', static fn (string $root): bool => new LayoutStore($root)->save(new LayoutPrefs(sidebarWidthPercent: 30))];
        yield 'bookmarks' => ['bookmarks.json', static fn (string $root): bool => new BookmarkStore($root)->save([$root.'/a.php' => [1]])];
        yield 'bookmarks emptied' => ['bookmarks.json', static fn (string $root): bool => new BookmarkStore($root)->save([])];
        yield 'recent files' => ['recent-files.json', static fn (string $root) => new RecentFilesStore($root)->record($root.'/a.php')];
        yield 'search scopes' => ['search-scopes.json', static fn (string $root) => new SearchScopeStore($root)->addRecent('src', ['src'])];
        yield 'session' => ['session.json', static fn (string $root): bool => new SessionStore($root)->save([['path' => $root.'/a.php', 'line' => 0, 'col' => 0, 'pinned' => false]], null)];
        yield 'session emptied' => ['session.json', static fn (string $root): bool => new SessionStore($root)->save([], null)];
        yield 'search history' => ['search-history.json', static fn (string $root) => new SearchHistoryStore($root)->add('needle')];
        yield 'search prefs' => ['search-prefs.json', static fn (string $root) => new SearchPrefsStore($root)->setMask('*.php')];
        yield 'debug' => ['debug.json', static fn (string $root): bool => new DebugStateStore($root)->save([], ['$a'], null)];
        yield 'debug emptied' => ['debug.json', static fn (string $root): bool => new DebugStateStore($root)->save([], [], null)];
        yield 'onboarding' => ['onboarding.json', static fn (string $root): bool => new OnboardingState($root)->markWelcomeSeen()];
    }

    /** @param \Closure(string): mixed $write */
    #[DataProvider('stores')]
    public function testAFileThatIsALinkIsNotWrittenThrough(string $name, \Closure $write): void
    {
        $outside = new TempProject(['.bashrc' => self::KEPT]);
        $project = new TempProject(['a.php' => '<?php', '.porta/keep' => '']);
        \symlink($outside->path('.bashrc'), $project->path('.porta/'.$name));

        $write($project->dir);

        $this->assertSame(self::KEPT, \file_get_contents($outside->path('.bashrc')));
    }

    /** @param \Closure(string): mixed $write */
    #[DataProvider('stores')]
    public function testAPortaFolderThatIsALinkIsNotWrittenInto(string $name, \Closure $write): void
    {
        $outside = new TempProject([$name => self::KEPT]);
        $project = new TempProject(['a.php' => '<?php']);
        \symlink($outside->dir, $project->path('.porta'));

        $write($project->dir);

        $this->assertSame(self::KEPT, \file_get_contents($outside->path($name)));
    }

    /** The control: the same writes land where nothing is linked, so the two above refuse for the link alone. */
    #[DataProvider('stores')]
    public function testAnUnlinkedPortaFolderIsWritten(string $name, \Closure $write): void
    {
        $project = new TempProject(['a.php' => '<?php', '.porta/'.$name => self::KEPT]);

        $write($project->dir);

        $this->assertNotSame(self::KEPT, Files::read($project->path('.porta/'.$name)));
    }
}
