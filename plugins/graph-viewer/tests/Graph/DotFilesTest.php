<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotFiles;

final class DotFilesTest extends TestCase
{
    public function testDotPathsAreRecognizedByExtension(): void
    {
        $this->assertTrue(DotFiles::isDotPath('/p/architecture.dot'));
        $this->assertTrue(DotFiles::isDotPath('/p/graph.GV'), 'case-insensitive');
        $this->assertFalse(DotFiles::isDotPath('/p/readme.md'));
        $this->assertFalse(DotFiles::isDotPath('/p/dot'), 'no extension');
    }
}
