<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Porta\Graph\DotFiles;
use Porta\Graph\GraphExportFormat;

final class DotFilesTest extends TestCase
{
    public function testDotPathsAreRecognizedByExtension(): void
    {
        $this->assertTrue(DotFiles::isDotPath('/p/architecture.dot'));
        $this->assertTrue(DotFiles::isDotPath('/p/graph.GV'), 'case-insensitive');
        $this->assertFalse(DotFiles::isDotPath('/p/readme.md'));
        $this->assertFalse(DotFiles::isDotPath('/p/dot'), 'no extension');
    }

    public function testExportFormats(): void
    {
        $this->assertSame('-Tsvg', GraphExportFormat::Svg->flag());
        $this->assertSame('-Tpng', GraphExportFormat::Png->flag());
        $this->assertSame('svg', GraphExportFormat::Svg->extension());
        $this->assertSame('png', GraphExportFormat::Png->extension());
    }
}
