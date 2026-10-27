<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\GitIgnored;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Finder\Finder;

final class GitIgnoredTest extends TestCase
{
    /** The walk the IDE makes, before any pruning: Finder's own file filter. */
    private static function finder(TempProject $project): Finder
    {
        return Finder::create()
            ->files()
            ->in($project->dir)
            ->ignoreDotFiles(false)
            ->ignoreVCSIgnored(true);
    }

    /** @return list<string> what $finder finds, sorted */
    private static function found(Finder $finder): array
    {
        $found = [];
        foreach ($finder as $file) {
            $found[] = $file->getRelativePathname();
        }
        \sort($found);

        return $found;
    }

    /** @return list<string> */
    private static function walk(TempProject $project): array
    {
        return self::found(self::finder($project)->filter(GitIgnored::pruner($project->dir), prune: true));
    }

    /**
     * An ignored tree is never entered: a directory nobody may list inside it
     * would stop a walk that went in — nothing here passes it over.
     */
    public function testAnIgnoredDirectoryIsNotWalkedAtAll(): void
    {
        $project = new TempProject(['.gitignore' => "/coverage/\n", 'src/A.php' => '', 'coverage/index.html' => '']);
        if (!$project->lock('coverage/locked')) {
            $this->markTestSkipped('this user reads every directory');
        }

        $this->assertSame(['.gitignore', 'src/A.php'], self::walk($project));
    }

    /**
     * A project inside a repository that ignores it — a scratch folder under a
     * home directory kept in git with `*` ignored — is walked whole: that
     * repository's rules are about its own tree, not this one's.
     */
    public function testAProjectAnOuterRepositoryIgnoresIsWalkedWhole(): void
    {
        $outer = new TempProject(['.gitignore' => "proj/\n", 'proj/src/A.php' => '', 'proj/top.php' => '']);
        $outer->initGit();
        $finder = Finder::create()
            ->files()
            ->in($outer->path('proj'))
            ->filter(GitIgnored::pruner($outer->path('proj')), prune: true);

        $this->assertSame(['src/A.php', 'top.php'], self::found($finder));
    }

    /** A .gitignore that cannot be read rules out nothing: the walk goes on. */
    public function testAnUnreadableGitignoreRulesOutNothing(): void
    {
        $project = new TempProject(['src/sub/B.php' => '']);
        \mkdir($project->path('src/.gitignore')); // a directory where the file should be, read to judge src/sub

        $this->assertSame(['src/sub/B.php'], self::found(Finder::create()->files()->in($project->dir)->filter(GitIgnored::pruner($project->dir), prune: true)));
    }

    /** The rules are git's, as Finder reads them — a nested .gitignore, a negation, a directory-only rule: pruning keeps every file the filter keeps. */
    public function testItKeepsWhatGitKeeps(): void
    {
        $project = new TempProject([
            '.gitignore' => "*.log\n/build/\n!/build/\ntmp/\n",
            'src/.gitignore' => "gen/\n",
            'src/A.php' => '',
            'src/gen/G.php' => '',
            'logs.log/inside.php' => '', // `*.log` names a directory too
            'tmp' => '', // `tmp/` names directories only: a file so named stays
            'src/tmp/T.php' => '',
            'build/B.php' => '', // re-included by the negation
            'app.log' => '',
        ]);

        $this->assertSame(['.gitignore', 'build/B.php', 'src/.gitignore', 'src/A.php', 'tmp'], self::walk($project));
        $this->assertSame(self::found(self::finder($project)), self::walk($project));
    }
}
