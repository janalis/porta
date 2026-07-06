<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\WorkspaceResolver;
use Porta\Tests\Support\TempProject;

final class WorkspaceResolverTest extends TestCase
{
    private WorkspaceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new WorkspaceResolver();
    }

    public function testDirectoryTarget(): void
    {
        $project = new TempProject(['a.php' => '']);

        $ws = $this->resolver->resolve($project->dir, '/nowhere');

        $this->assertSame(realpath($project->dir), $ws->rootDir);
        $this->assertNull($ws->initialFile);
        $this->assertNull($ws->line);
    }

    public function testFileTargetWalksUpToComposerRoot(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'src/Foo.php' => "<?php\n"]);

        $ws = $this->resolver->resolve($project->path('src/Foo.php'), '/nowhere');

        $this->assertSame(realpath($project->dir), $ws->rootDir, 'root is the enclosing composer project');
        $this->assertSame(realpath($project->path('src/Foo.php')), $ws->initialFile);
    }

    public function testFileTargetWithoutComposerRootUsesItsOwnDirectory(): void
    {
        $project = new TempProject(['src/Foo.php' => "<?php\n"]); // no composer.json anywhere above

        $ws = $this->resolver->resolve($project->path('src/Foo.php'), '/nowhere');

        $this->assertSame(realpath($project->path('src')), $ws->rootDir);
        $this->assertSame(realpath($project->path('src/Foo.php')), $ws->initialFile);
    }

    public function testRelativeTargetResolvedAgainstCwd(): void
    {
        $project = new TempProject(['a.php' => '']);

        $ws = $this->resolver->resolve('a.php', $project->dir);

        $this->assertSame(realpath($project->path('a.php')), $ws->initialFile);
    }

    public function testFileLineShorthand(): void
    {
        $project = new TempProject(['a.php' => "1\n2\n3\n"]);

        $ws = $this->resolver->resolve($project->path('a.php').':42', '/nowhere');

        $this->assertSame(realpath($project->path('a.php')), $ws->initialFile);
        $this->assertSame(42, $ws->line);
    }

    public function testNonNumericSuffixIsNotTreatedAsLine(): void
    {
        // "a.php:oops" is not a file:line — no split, and no such file → error.
        $project = new TempProject(['a.php' => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolve($project->path('a.php').':oops', '/nowhere');
    }

    public function testColonSuffixOnAMissingFileIsNotSplit(): void
    {
        // "nope:5" matches the regex but "nope" is not a file → not split → missing.
        $this->expectExceptionMessage('no such file or directory: /nope:5');
        $this->resolver->resolve('/nope:5', '/nowhere');
    }

    public function testMissingTargetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no such file or directory: /does/not/exist');
        $this->resolver->resolve('/does/not/exist', '/nowhere');
    }
}
