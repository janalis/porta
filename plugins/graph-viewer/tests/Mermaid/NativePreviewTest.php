<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidPng;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\MarkdownFenceRenderer;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RendersWidget;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Render\RenderContext;

final class NativePreviewTest extends TestCase
{
    use RendersWidget;
    use PumpsLoop;

    public function testStandaloneCellsPreserveLastSceneCacheZoomAndCancelOnHide(): void
    {
        $scheduler = new FakeScheduler();
        $dot = new FakeLayoutEngine();
        $mermaid = new FakeLayoutEngine();
        $widget = new GraphViewerWidget($dot, new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: $mermaid);
        Probe::invoke($widget, 'requestNative');
        $widget->show('/a.mmd', 'graph LR; A[Hello]-->B[World]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame([], $mermaid->renderDpis);
        $context = new RenderContext(60, 15);
        $screen = $this->plain($widget->render($context));
        $this->assertStringContainsString('Hello', $screen);
        $this->assertStringContainsString('World', $screen);
        $this->assertSame('2 nodes · 1 edges', $widget->statusInfo());
        $widget->handleInput('1');
        $widget->show('/a.mmd', 'graph LR; A[');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('Unclosed', $this->plain($widget->render($context)));
        $this->assertStringContainsString('Hello', $this->plain($widget->render($context)));
        $widget->show('/a.mmd', 'graph LR; A[Hello]-->B[World]');
        $this->assertSame([], $scheduler->scheduled, 'a successful scene is cached');
        $widget->setRenderMode('bitmap');
        $mermaid->nextPng = PngFixture::bytes(120, 80);
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('▀', $this->plain($widget->render($context)));
        $widget->handleInput('v');
        $this->assertStringContainsString('Hello', $this->plain($widget->render($context)));
        // Nine unique sources exercise the bounded scene memo.
        for ($i = 0; $i < 9; ++$i) {
            $widget->show('/a.mmd', 'graph TD; N'.$i);
            while ([] !== $scheduler->scheduled) {
                $scheduler->fire();
            }
        }
        $this->assertCount(8, Probe::get($widget, 'nativeCache'));
        $widget->show('/a.mmd', 'graph TD; '.\implode(';', \array_map(static fn (int $i): string => 'N'.$i, \range(0, end: 200))));
        $scheduler->fire();
        $widget->hide();
        $this->assertSame([], $scheduler->scheduled);
        $widget->show('/a.mmd', 'graph TD; '.\implode(';', \array_map(static fn (int $i): string => 'N'.$i, \range(0, end: 200))));
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame(201, Probe::get($widget, 'nativeScene')->graph->nodeCount());
        $widget->clear();
        $this->assertNull(Probe::get($widget, 'nativeScene'));
    }

    public function testStandaloneFrontMatterRetainsSceneForUnsupportedConfiguration(): void
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: new FakeLayoutEngine());
        $widget->show('/front.mmd', "---\ntitle: Native caption\n---\nflowchart LR\nA[First]-->B[Second]");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $scene = Probe::get($widget, 'nativeScene');
        $this->assertSame(['First', 'Second'], \array_column($widget->nodeChoices(), 'label'));
        $this->assertSame([4, 4], \array_values($scene->elements));
        $this->assertStringContainsString('Native caption', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->show('/front.mmd', "---\nconfig: {sequence: {mirrorActors: true}}\n---\nflowchart LR; A");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
        $this->assertStringContainsString('configuration does not apply to this diagram family', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->clear();
    }

    public function testConfiguredPieKeepsSelectionOriginsAndRetainsErrors(): void
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: new FakeLayoutEngine());
        $widget->show('/configured.mmd', "---\nconfig: {pie: {highlightSlice: Cats}}\n---\npie\n\"Dogs\":3\n\"Cats\":1");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $scene = Probe::get($widget, 'nativeScene');
        $this->assertSame(['Dogs (75.00%)', '* Cats (25.00%)'], \array_column($widget->nodeChoices(), 'label'));
        $this->assertSame([4, 5], \array_values($scene->elements));
        $this->assertStringContainsString('* Cats', $this->plain($widget->render(new RenderContext(120, 35))));
        foreach (['%%{init: {pie: {highlightSlice: hover}}}%%', '%%{init: {sequence: {showSequenceNumbers: true}}}%%'] as $directive) {
            $widget->show('/configured.mmd', $directive."\npie\n\"Dogs\":1");
            while ([] !== $scheduler->scheduled) {
                $scheduler->fire();
            }
            $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
        }
        $this->assertStringContainsString('does not apply', $this->plain($widget->render(new RenderContext(120, 35))));
        $widget->clear();
    }

    public function testStandaloneSequenceMenusRetainPanelsForInvalidMenuEdits(): void
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: new FakeLayoutEngine());
        $widget->show('/menus.mmd', 'sequenceDiagram; participant A; link A: Docs @ https://example.test; A->B: hi');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $scene = Probe::get($widget, 'nativeScene');
        $this->assertStringContainsString('https://example.test', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->show('/menus.mmd', 'sequenceDiagram; links A: {invalid}');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
    }

    public function testStandalonePieRetainsChartForInvalidValues(): void
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: new FakeLayoutEngine());
        $widget->show('/pie.mmd', "pie showData title Pets\n\"Dogs\":3\n\"Cats\":1");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $scene = Probe::get($widget, 'nativeScene');
        $this->assertCount(2, $scene->elements);
        $context = new RenderContext(124, 30);
        $this->assertStringContainsString('Dogs: 3 (75.00%)', $this->plain($widget->render($context)));
        $widget->show('/pie.mmd', "pie\n\"Dogs\":-1");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
        $this->assertStringContainsString('nonnegative', $this->plain($widget->render($context)));
        $widget->clear();
    }

    public function testSequenceStandaloneKeepsLastSceneForInvalidEdits(): void
    {
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: new FakeLayoutEngine());
        $widget->show(
            '/sequence.mmd',
            'sequenceDiagram; title Lifecycle; box Team; participant A@{type: boundary} as Alice; end; loop retry; create participant B; A->>+B: Hello; destroy B; B-->>-A: reply; end',
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $scene = Probe::get($widget, 'nativeScene');
        $this->assertCount(2, $scene->graph->clusters);
        $this->assertStringContainsString('Alice', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->show('/sequence.mmd', 'sequenceDiagram; loop pending');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
        $this->assertStringContainsString('Unclosed sequence control block', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->show('/sequence.mmd', 'sequenceDiagram; participant A@{alias: true}');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame($scene, Probe::get($widget, 'nativeScene'));
        $this->assertStringContainsString('Unsupported sequence participant configuration field', $this->plain($widget->render(new RenderContext(100, 25))));
        $widget->clear();
    }

    public function testMissingAndFailingCliShowNativePixelsAndKeepAnActionableWarning(): void
    {
        $scheduler = new FakeScheduler();
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, mermaid: $mermaid);
        $widget->show('/a.mmd', 'graph TD; A[Native]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $context = new RenderContext(100, 20);
        $screen = $this->plain($widget->render($context));
        $this->assertStringContainsString('mmdc not found', $screen);
        $this->assertStringContainsString('▀', $screen);
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
        $this->assertSame('Native', $widget->nodeChoices()[0]['label']);
        $mermaid->binary = '/fake/mmdc';
        $mermaid->pngIsError = true;
        $mermaid->nextPng = 'renderer failure';
        $widget->show('/b.mmd', 'graph LR; B[Fallback]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('renderer failure', $this->plain($widget->render($context)));
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
        $this->assertSame('Fallback', $widget->nodeChoices()[0]['label']);
        $mermaid->pngIsError = false;
        $mermaid->nextPng = 'not PNG';
        $widget->show('/b.mmd', 'graph LR; B[Undecodable]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertStringContainsString('could not be decoded', $this->plain($widget->render($context)));
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
        $this->assertSame('Undecodable', $widget->nodeChoices()[0]['label']);
        $widget->hide();
    }

    public function testMarkdownCellsAndCliFallbackCarryOriginsAndErrors(): void
    {
        $project = new TempProject();
        $mode = 'cells';
        $renderer = new MarkdownFenceRenderer(
            static function (string $key) use (&$mode): string {
                return 'renderMode' === $key ? $mode : '/nonexistent/mmdc';
            },
            static fn (string $message): string => 'translated: '.$message,
        );
        $result = null;
        $error = null;
        $cancel = $renderer(
            'mermaid',
            source: "graph TD\nA[Inline]-->B",
            path: $project->dir.'/doc.md',
            width: 30,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$result, &$error): bool {
            return null !== $result || null !== $error;
        }, what: 'native fence');
        $this->assertNull($error);
        $this->assertNull($result->error);
        $this->assertStringContainsString('Inline', $this->plain($result->rows));
        $this->assertContains(1, $result->origins);
        $cancel();
        $mode = 'bitmap';
        $result = null;
        $cancel = $renderer(
            'mermaid',
            source: 'sequenceDiagram; title Inline; box Team; participant A@{type: control, alias: Client}; end; critical attempt; create actor B; A->>+B: Fallback; destroy B; B-->>-A: reply; option fail; end; link A: Docs @ https://example.test/menu; properties B: {"state":"ready"}',
            path: $project->dir.'/doc.md',
            width: 30,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$result, &$error): bool {
            return null !== $result || null !== $error;
        }, what: 'CLI fallback');
        $this->assertStringStartsWith("\x89PNG", $result->png);
        $this->assertStringStartsWith('translated:', $result->error);
        $this->assertSame([], $result->rows);
        $cancel();
        $error = null;
        $renderer(
            'mermaid',
            source: 'graph TD; A[',
            path: $project->dir.'/doc.md',
            width: 30,
            success: static function (): never {
                self::fail('bad syntax rendered');
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'native parse error');
        $this->assertStringContainsString('Unclosed', $error);
        $result = null;
        $error = null;
        $cancel = $renderer(
            'mermaid',
            source: "%%{init: {pie: {highlightSlice: Cats}}}%%\npie showData\n\"Dogs\":3\n\"Cats\":1",
            path: $project->dir.'/doc.md',
            width: 30,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$result, &$error): bool {
            return null !== $result || null !== $error;
        }, what: 'pie CLI fallback');
        $this->assertNull($error);
        $this->assertStringStartsWith("\x89PNG", $result->png);
        $this->assertStringStartsWith('translated:', $result->error);
        $cancel();
    }

    public function testMarkdownFallsBackToReadableRowsWhenGdIsUnavailable(): void
    {
        $project = new TempProject();
        $renderer = new MarkdownFenceRenderer(
            static fn (string $key): string => 'renderMode' === $key ? 'bitmap' : '/nonexistent/mmdc',
            static fn (string $message): string => $message,
            static fn (): NativeMermaidRenderer => new NativeMermaidRenderer(new MermaidTask(), new MermaidPipeline(png: new MermaidPng(gdAvailable: false))),
        );
        $result = null;
        $renderer(
            'mermaid',
            source: 'graph TD; A[Readable]',
            path: $project->path('doc.md'),
            width: 40,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                self::fail($error);
            },
        );
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'native fence without GD');
        $this->assertNull($result->png);
        $this->assertStringContainsString('Readable', $this->plain($result->rows));
        $this->assertSame('could not start mmdc · Native Mermaid PNG requires the PHP GD extension with FreeType support', $result->error, 'why there is no picture, then why there are only rows');
        $this->assertContains(0, $result->origins);
    }

    public function testMarkdownSaysWhyNeitherMmdcNorTheNativeRendererDrewIt(): void
    {
        $project = new TempProject();
        $renderer = new MarkdownFenceRenderer(
            static fn (string $key): string => 'renderMode' === $key ? 'bitmap' : '/nonexistent/mmdc',
            static fn (string $message): string => $message,
        );
        $error = null;
        $renderer(
            'mermaid',
            source: 'graph TD; A[',
            path: $project->path('doc.md'),
            width: 40,
            success: static function (): never {
                self::fail('a broken diagram drew');
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'both renderers failing');

        $this->assertStringStartsWith('could not start mmdc · ', (string) $error);
        $this->assertGreaterThan(\strlen('could not start mmdc · '), \strlen((string) $error), 'and the source\'s own error after it');
    }
}
