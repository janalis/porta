<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidFont;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final class MermaidFontTest extends TestCase
{
    public function testPharFontIsMaterializedAndRemovedWithoutTouchingItsSource(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('font.tar'));
        $archive->addFile(MermaidFont::FILE, 'font.ttf');
        $source = 'phar://'.$project->path('font.tar').'/font.ttf';
        $font = new MermaidFont($source);
        $path = $font->path();
        $this->assertFileExists($path);
        $this->assertSame($path, $font->path(), 'one lease reuses one extraction');
        $this->assertFileEquals(MermaidFont::FILE, $path);
        $this->assertSame(0o600, \fileperms($path) & 0o777);
        $font->close();
        $this->assertFileDoesNotExist($path);
        $font->close();
        $this->assertFileExists($source);
        $local = new MermaidFont();
        $this->assertSame(MermaidFont::FILE, $local->path());
        $local->close();

        $work = new MermaidPng(font: $source)->render(new MermaidDrawing(20, 10, [new MermaidMark('text', [[10.0, 5.0]], 'Packaged')]));
        \iterator_to_array($work);
        $this->assertStringStartsWith("\x89PNG", $work->getReturn());
    }

    public function testCancellationClosesGeneratorResourcesImmediately(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('font.tar'));
        $archive->addFile(MermaidFont::FILE, 'font.ttf');
        $font = new MermaidFont('phar://'.$project->path('font.tar').'/font.ttf');
        $path = $font->path();
        $scheduler = new FakeScheduler();
        $work = static function () use ($font): \Generator {
            try {
                for ($index = 0; $index < 1000; ++$index) {
                    yield null;
                }
            } finally {
                $font->close();
            }
        };
        $cancel = new MermaidTask($scheduler)->run(
            $work(),
            static function (): never {
                self::fail('cancelled work cannot complete');
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertFileExists($path);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $this->assertFileDoesNotExist($path, 'cancellation runs finally without waiting for cyclic garbage collection');
    }

    public function testMissingPackagedFontIsReportedBeforeCallingFreeType(): void
    {
        $font = new MermaidFont('phar:///nonexistent/font.tar/font.ttf');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot read');
        $font->path();
    }

    public function testExtractionErrorsAreReportedAsRenderFailures(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('font.tar'));
        $archive->addFile(MermaidFont::FILE, 'font.ttf');
        $files = new class extends Filesystem {
            public function tempnam(string $dir, string $prefix, string $suffix = ''): string
            {
                throw new IOException('disk full');
            }
        };
        $font = new MermaidFont('phar://'.$project->path('font.tar').'/font.ttf', $files);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot prepare the bundled Mermaid font: disk full');
        $font->path();
    }
}
