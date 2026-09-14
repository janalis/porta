<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Files;
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
}
