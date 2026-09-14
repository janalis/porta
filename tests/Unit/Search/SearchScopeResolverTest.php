<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Search\SearchScopeResolver;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\TempProject;
use Porta\Ui\ScopeKind;

final class SearchScopeResolverTest extends TestCase
{
    public function testProjectScopeIsAlwaysTheWholeTree(): void
    {
        $project = new TempProject([]);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile(), new ProjectPaths($project->dir));

        $this->assertSame(['.'], $resolver->resolve(ScopeKind::Project));
    }

    public function testOpenFilesScopeIsEveryOpenBufferRelativeToTheRoot(): void
    {
        $project = new TempProject(['a.php' => '', 'sub/b.php' => '']);
        $buffers = new BufferManager();
        $buffers->open($project->path('a.php'), '');
        $buffers->open($project->path('sub/b.php'), '');
        $resolver = new SearchScopeResolver($buffers, new FixedActiveFile(), new ProjectPaths($project->dir));

        $this->assertSame(['a.php', 'sub/b.php'], $resolver->resolve(ScopeKind::OpenFiles));
    }

    public function testOpenFilesScopeIsEmptyWithNothingOpen(): void
    {
        $project = new TempProject([]);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile(), new ProjectPaths($project->dir));

        $this->assertSame([], $resolver->resolve(ScopeKind::OpenFiles));
    }

    public function testOpenFilesScopeDropsBuffersOutsideTheProject(): void
    {
        $project = new TempProject(['a.php' => '']);
        $outside = \tempnam(\sys_get_temp_dir(), prefix: 'outside');
        $this->assertNotFalse($outside);
        try {
            $buffers = new BufferManager();
            $buffers->open($project->path('a.php'), '');
            $buffers->open($outside, '');
            $resolver = new SearchScopeResolver($buffers, new FixedActiveFile(), new ProjectPaths($project->dir));

            $this->assertSame(['a.php'], $resolver->resolve(ScopeKind::OpenFiles), 'a buffer outside the project tree is excluded');
        } finally {
            if (\is_file($outside)) {
                \unlink($outside);
            }
        }
    }

    public function testCurrentFileAndDirectoryScopesFallBackToTheProjectWithNoFileOpen(): void
    {
        $project = new TempProject([]);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile(), new ProjectPaths($project->dir));

        $this->assertSame(['.'], $resolver->resolve(ScopeKind::CurrentFile));
        $this->assertSame(['.'], $resolver->resolve(ScopeKind::Directory));
        $this->assertSame(['.'], $resolver->resolve(ScopeKind::Module));
    }

    public function testCurrentFileScopeIsTheActiveFileItself(): void
    {
        $project = new TempProject(['src/Foo.php' => '']);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($project->path('src/Foo.php')), new ProjectPaths($project->dir));

        $this->assertSame(['src/Foo.php'], $resolver->resolve(ScopeKind::CurrentFile));
    }

    public function testDirectoryScopeIsTheActiveFilesContainingFolder(): void
    {
        $project = new TempProject(['src/Foo.php' => '']);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($project->path('src/Foo.php')), new ProjectPaths($project->dir));

        $this->assertSame(['src'], $resolver->resolve(ScopeKind::Directory));
    }

    public function testCurrentFileScopeOutsideTheProjectFallsBackToTheWholeProject(): void
    {
        $project = new TempProject([]);
        $outside = \tempnam(\sys_get_temp_dir(), prefix: 'outside');
        $this->assertNotFalse($outside);
        try {
            $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($outside), new ProjectPaths($project->dir));

            $this->assertSame(['.'], $resolver->resolve(ScopeKind::CurrentFile));
        } finally {
            if (\is_file($outside)) {
                \unlink($outside);
            }
        }
    }

    public function testModuleScopeFindsTheNearestAncestorComposerJson(): void
    {
        $project = new TempProject([
            'packages/foo/composer.json' => '{}',
            'packages/foo/src/Bar.php' => '',
        ]);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($project->path('packages/foo/src/Bar.php')), new ProjectPaths($project->dir));

        $this->assertSame(['packages/foo'], $resolver->resolve(ScopeKind::Module));
    }

    public function testModuleScopeClimbsPastDirectoriesWithoutAComposerJson(): void
    {
        $project = new TempProject([
            'packages/foo/composer.json' => '{}',
            'packages/foo/src/deep/nested/Bar.php' => '',
        ]);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($project->path('packages/foo/src/deep/nested/Bar.php')), new ProjectPaths($project->dir));

        $this->assertSame(['packages/foo'], $resolver->resolve(ScopeKind::Module));
    }

    public function testModuleScopeFallsBackToTheWholeProjectWhenNoComposerJsonIsFound(): void
    {
        $project = new TempProject(['src/deep/Bar.php' => '']);
        $resolver = new SearchScopeResolver(new BufferManager(), new FixedActiveFile($project->path('src/deep/Bar.php')), new ProjectPaths($project->dir));

        $this->assertSame(['.'], $resolver->resolve(ScopeKind::Module));
    }
}
