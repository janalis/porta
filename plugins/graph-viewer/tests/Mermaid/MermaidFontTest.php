<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Text\Font;
use PHPUnit\Framework\TestCase;
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
        $archive->addFile(Font::FILE, 'font.ttf');
        $source = 'phar://'.$project->path('font.tar').'/font.ttf';
        $font = new Font($source);
        $path = $font->path();
        $this->assertFileExists($path);
        $this->assertSame($path, $font->path(), 'one lease reuses one extraction');
        $this->assertFileEquals(Font::FILE, $path);
        $this->assertSame(0o600, \fileperms($path) & 0o777);
        $font->close();
        $this->assertFileDoesNotExist($path);
        $font->close();
        $this->assertFileExists($source);
        $local = new Font();
        $this->assertSame(Font::FILE, $local->path());
        $local->close();

        $work = new PngBackend(font: $source)->render(new Drawing(20, 10, [new Mark(MarkKind::Text, [[10.0, 5.0]], 'Packaged')]));
        \iterator_to_array($work);
        $this->assertStringStartsWith("\x89PNG", $work->getReturn());
    }

    public function testCancellationClosesGeneratorResourcesImmediately(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('font.tar'));
        $archive->addFile(Font::FILE, 'font.ttf');
        $font = new Font('phar://'.$project->path('font.tar').'/font.ttf');
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

    public function testExtractionErrorsAreReportedAsRenderFailures(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('font.tar'));
        $archive->addFile(Font::FILE, 'font.ttf');
        $files = new class extends Filesystem {
            public function tempnam(string $dir, string $prefix, string $suffix = ''): string
            {
                throw new IOException('disk full');
            }
        };
        $font = new Font('phar://'.$project->path('font.tar').'/font.ttf', $files);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot prepare the bundled font: disk full');
        $font->path();
    }
}
