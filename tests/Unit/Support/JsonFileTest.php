<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\JsonFile;
use Porta\Tests\Support\TempProject;

final class JsonFileTest extends TestCase
{
    public function testReadsValidJsonObject(): void
    {
        $project = new TempProject(['data.json' => '{"a": 1, "b": [2, 3]}']);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], JsonFile::read($project->dir.'/data.json'));
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull(JsonFile::read('/no/such/file.json'));
    }

    public function testMalformedJsonReturnsNull(): void
    {
        $project = new TempProject(['bad.json' => '{not valid']);

        $this->assertNull(JsonFile::read($project->dir.'/bad.json'));
    }

    public function testScalarJsonIsNotAnArrayReturnsNull(): void
    {
        $project = new TempProject(['scalar.json' => '42']);

        $this->assertNull(JsonFile::read($project->dir.'/scalar.json'));
    }
}
