<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Files;
use Porta\Tests\Support\PharFixture;
use Porta\Tests\Support\TempProject;

final class FilesTest extends TestCase
{
    public function testReadReturnsEmptyForAMissingFile(): void
    {
        $project = new TempProject();
        $this->assertSame('', Files::read($project->path('missing.txt')));
    }

    public function testReadReturnsTheFilesBytes(): void
    {
        $project = new TempProject(['a.txt' => 'hello']);
        $this->assertSame('hello', Files::read($project->path('a.txt')));
    }

    public function testReadWritableReturnsNullForAMissingFile(): void
    {
        $project = new TempProject();
        $this->assertNull(Files::readWritable($project->path('missing.txt')));
    }

    public function testReadWritableReturnsNullForAnUnwritableFile(): void
    {
        $project = new TempProject(['a.txt' => 'hello']);
        $path = $project->path('a.txt');
        \chmod($path, permissions: 0o444);
        try {
            $this->assertNull(Files::readWritable($path), 'existing but unwritable must not be read as writable');
        } finally {
            \chmod($path, permissions: 0o644);
        }
    }

    public function testReadWritableReturnsTheFilesBytesWhenWritable(): void
    {
        $project = new TempProject(['a.txt' => 'hello']);
        $this->assertSame('hello', Files::readWritable($project->path('a.txt')));
    }

    public function testResolveIsRealpathWhereRealpathAnswers(): void
    {
        $project = new TempProject(['docs/a.md' => "a\n"]);

        $this->assertSame(\realpath($project->path('docs/a.md')), Files::resolve($project->dir.'/docs/../docs/./a.md'));
        $this->assertNull(Files::resolve($project->dir.'/docs/missing.md'));
    }

    /**
     * Inside the phar realpath() answers false for everything, so the help
     * browser refused every link there. The segments resolve instead — and
     * the result must still exist.
     */
    public function testResolveWorksThroughThePharWrapperRealpathCannotSee(): void
    {
        $phar = PharFixture::root();
        $this->assertFalse(\realpath($phar.'/docs/README.md'), 'the reason this exists');

        $this->assertSame($phar.'/outside.md', Files::resolve($phar.'/docs/../outside.md'));
        $this->assertSame($phar.'/docs/README.md', Files::resolve($phar.'/docs/./guide.md/../README.md'));
        $this->assertSame($phar.'/plugins/demo/docs', Files::resolve($phar.'//plugins/demo/docs/'), 'empty segments collapse; a directory resolves too');
        $this->assertNull(Files::resolve($phar.'/docs/missing.md'), 'nothing there is null, not a guess');
        $this->assertNull(Files::resolve('phar://relative.phar/docs/README.md'), 'a relative path behind a wrapper is not resolved');
        $this->assertNull(Files::resolve('not-a-wrapper:/docs'), 'only a wrapper path falls back');
        $this->assertNull(Files::resolve('missing/'.$phar.'/docs/README.md'), 'the wrapper must START the path, not sit inside a local one');
        $this->assertNull(Files::resolve($phar."/docs/README.md\nsomething"), 'the whole path is resolved, not the part before a newline');
        $upper = 'PHAR'.\substr($phar, offset: 4);
        $this->assertSame($upper.'/docs/README.md', Files::resolve($upper.'/docs/../docs/README.md'), 'a wrapper name is case-insensitive, as PHP reads it');
    }

    public function testCanonicalKeepsAPathThatDoesNotResolve(): void
    {
        $this->assertSame('/nowhere/at/../all.md', Files::canonical('/nowhere/at/../all.md'));
        $this->assertSame(PharFixture::root().'/outside.md', Files::canonical(PharFixture::root().'/docs/../outside.md'));
    }
}
