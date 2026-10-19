<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidPng;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidExport;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TempProject;

final class MermaidDrawingTest extends TestCase
{
    public function testNativeExportsPublishAtomicallyAndCancelLatestWork(): void
    {
        $project = new TempProject(['out.svg' => 'previous', 'not-directory' => 'file']);
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $exporter = new NativeMermaidExport($renderer);
        $errors = [];
        $done = static function (?string $error) use (&$errors): void {
            $errors[] = $error;
        };
        $exporter->export('graph TD; A[', Format::Svg, $project->path('out.svg'), $done);
        $this->finish($scheduler);
        $this->assertStringContainsString('Unclosed', $errors[0]);
        $this->assertSame('previous', \file_get_contents($project->path('out.svg')));
        $exporter->export('graph TD; A[Cancelled]', Format::Svg, $project->path('out.svg'), $done);
        $exporter->cancel();
        $this->assertSame([], $scheduler->scheduled);
        $this->assertSame('previous', \file_get_contents($project->path('out.svg')));
        $exporter->export('graph TD; A[SVG]', Format::Svg, $project->path('out.svg'), $done);
        $this->finish($scheduler);
        $this->assertNull($errors[1]);
        $this->assertStringContainsString('>SVG</text>', (string) \file_get_contents($project->path('out.svg')));
        $exporter->export('timeline TD'."\n2000 : PNG", Format::Png, $project->path('out.png'), $done);
        $this->finish($scheduler);
        $this->assertNull($errors[2]);
        $this->assertStringStartsWith("\x89PNG", (string) \file_get_contents($project->path('out.png')));
        $exporter->export('graph TD; A', Format::Svg, $project->path('not-directory/out.svg'), $done);
        $this->finish($scheduler);
        $this->assertNotNull($errors[3]);
        $this->assertStringContainsString('not-directory', $errors[3]);
    }

    public function testTaskReportsRasterFailures(): void
    {
        $scheduler = new FakeScheduler();
        $error = null;
        new MermaidTask($scheduler)->run(
            new MermaidPng(gdAvailable: false)->render(new MermaidDrawing(1, 1, [])),
            static function (): never {
                self::fail('missing GD cannot produce a PNG');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->finish($scheduler);
        $this->assertStringContainsString('GD extension', $error);
    }

    public function testMarkdownNativePreviewUsesPixelsOrReadableRowsWithoutGd(): void
    {
        foreach ([new MermaidPng(), new MermaidPng(gdAvailable: false)] as $backend) {
            $scheduler = new FakeScheduler();
            $result = null;
            new NativeMermaidRenderer(new MermaidTask($scheduler), new MermaidPipeline(png: $backend))->preview(
                'graph TD; A[Readable]',
                width: 20,
                success: static function (FenceResult $value) use (&$result): void {
                    $result = $value;
                },
                error: static function (string $error): never {
                    self::fail($error);
                },
            );
            $this->finish($scheduler);
            $this->assertInstanceOf(FenceResult::class, $result);
            if (null !== $result->png) {
                $this->assertStringStartsWith("\x89PNG", $result->png);
                $this->assertNull($result->error);
            }
            if (null === $result->png) {
                $this->assertStringContainsString('Readable', \implode('', $result->rows));
                $this->assertStringContainsString('GD extension', $result->error);
                $this->assertContains(0, $result->origins);
            }
        }
    }

    private function finish(FakeScheduler $scheduler): void
    {
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
    }
}
