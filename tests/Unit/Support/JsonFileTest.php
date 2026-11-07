<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\JsonFile;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

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

    public function testAnEditableWriteKeepsNestedEmptyObjects(): void
    {
        $project = new TempProject(['c.json' => '{"a": {"b": {}, "c": []}, "d": [{}]}']);
        $path = $project->path('c.json');

        $this->assertTrue(JsonFile::writeEditable(new Filesystem(), $path, ['a' => ['b' => [], 'c' => []], 'd' => [[]], 'new' => []]));

        $this->assertSame('{"a":{"b":{},"c":[]},"d":[{}],"new":[]}', \json_encode(
            \json_decode((string) \file_get_contents($path), associative: false, flags: \JSON_THROW_ON_ERROR),
            \JSON_THROW_ON_ERROR,
        ));
        $this->assertStringStartsWith("{\n    \"a\"", (string) \file_get_contents($path), 'four spaces, the file had no indent of its own');
    }

    public function testAnEditableWriteOverMalformedJsonStillWrites(): void
    {
        $project = new TempProject(['c.json' => "{\n\t\"x\": oops"]);

        $this->assertTrue(JsonFile::writeEditable(new Filesystem(), $project->path('c.json'), ['x' => ['y' => 1]]));

        $this->assertSame("{\n\t\"x\": {\n\t\t\"y\": 1\n\t}\n}\n", \file_get_contents($project->path('c.json')), 'the tab indent read from what was there');
    }

    public function testScalarJsonIsNotAnArrayReturnsNull(): void
    {
        $project = new TempProject(['scalar.json' => '42']);

        $this->assertNull(JsonFile::read($project->dir.'/scalar.json'));
    }

    /**
     * A project folder moved or deleted while the IDE has it open is not made
     * again by the next save of a file of Porta's own: the layout written as a
     * pane was dragged left a folder holding only `.porta/layout.json` where
     * the project had been.
     */
    public function testAWriteInAProjectThatIsGoneMakesNothing(): void
    {
        $project = new TempProject();
        $root = $project->path('moved');
        \mkdir($root);
        $this->assertTrue(JsonFile::writeInProject(new Filesystem(), $root, $root.'/.porta/layout.json', ['a' => 1]), 'written while the project is there');
        new Filesystem()->remove($root);

        $this->assertFalse(JsonFile::writeInProject(new Filesystem(), $root, $root.'/.porta/layout.json', ['a' => 2]));
        $this->assertDirectoryDoesNotExist($root);
    }
}
