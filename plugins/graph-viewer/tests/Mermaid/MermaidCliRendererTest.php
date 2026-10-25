<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\MermaidEngine;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Mermaid\MermaidCliPicture;
use Porta\GraphViewer\Mermaid\MermaidCliRenderer;
use Porta\GraphViewer\Mermaid\MermaidSvgRasterizer;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;
use Symfony\Component\Tui\Render\RenderContext;

final class MermaidCliRendererTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;

    private static function support(string $name): string
    {
        return \dirname(__DIR__, levels: 4).'/tests/Support/'.$name;
    }

    /** @return array{MermaidCliPicture|null, string|null} */
    private function render(MermaidCliRenderer $renderer, string $source, int $dpi = 96): array
    {
        $picture = null;
        $error = null;
        $renderer->render(
            $source,
            $dpi,
            static function (MermaidCliPicture $value) use (&$picture): void {
                $picture = $value;
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$picture, &$error): bool {
            return null !== $picture || null !== $error;
        }, what: 'the CLI picture and semantic map');

        return [$picture, $error];
    }

    public function testOneSvgSnapshotProvidesBothOversampledPixelsAndLogicalHitRegions(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::support('fake-mmdc'));
        $renderer = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')));
        [$picture, $error] = $this->render($renderer, "flowchart LR\nA[Alpha]\nB[Beta]\nA-->B", dpi: 192);
        $this->assertNull($error);
        $this->assertSame([200, 100], PngDimensions::read($picture->png));
        $this->assertSame([100.0, 50.0], [$picture->scene->graph->width, $picture->scene->graph->height]);
        $this->assertSame([1, 2], \array_values($picture->scene->elements));
        $this->assertSame(['Alpha', 'Beta'], \array_column($picture->elements, 'label'));
        $this->assertSame([[0.05, 0.1], [0.35, 0.1], [0.35, 0.5], [0.05, 0.5]], $picture->elements[0]->polygon);
        [$options, $source] = \json_decode((string) \file_get_contents($project->path('mermaid-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('svg', $options['--outputFormat']);
        $this->assertSame('1', $options['--scale']);
        $this->assertStringContainsString('Alpha', $source);
        $this->assertFileDoesNotExist($options['--output']);
        $this->assertDirectoryDoesNotExist(\dirname($options['--output']));
        \unlink($project->path('mermaid-request.json'));
        [$refined, $error] = $this->render($renderer, "flowchart LR\nA[Alpha]\nB[Beta]\nA-->B", dpi: 288);
        $this->assertNull($error);
        $this->assertSame([300, 150], PngDimensions::read($refined->png));
        $this->assertSame($picture->elements[0]->polygon, $refined->elements[0]->polygon);
        $this->assertFileDoesNotExist($project->path('mermaid-request.json'), 'resolution-only refinement reuses the successful SVG layout');

        $renderer->cancel();
    }

    /** A resolution of nothing is drawn at one dot, never at none: the picture exists and maps back at its size. */
    public function testANonPositiveResolutionDrawsAtOneDot(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::support('fake-mmdc'));
        $renderer = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')));

        [$picture, $error] = $this->render($renderer, "flowchart LR\nA[Alpha]", dpi: 0);

        $this->assertNull($error);
        $this->assertSame([1, 1], PngDimensions::read($picture->png), 'a hundred by fifty, at a 96th');
        $this->assertSame([96.0, 96.0], [$picture->scene?->graph->width, $picture->scene?->graph->height], 'one dot back to 96 points');
        $renderer->cancel();
    }

    public function testToolChangesInvalidateSnapshotsAndLargeSvgSnapshotsAreNotRetained(): void
    {
        $project = new TempProject();
        $tool = self::support('fake-mmdc');
        $engine = new MermaidEngine($project->dir, static function () use (&$tool): string {
            return $tool;
        });
        $renderer = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')));
        [$picture, $error] = $this->render($renderer, 'flowchart; A');
        $this->assertNull($error);
        $this->assertInstanceOf(MermaidScene::class, $picture->scene);
        \unlink($project->path('mermaid-request.json'));
        $tool = \dirname($tool).'/../Support/fake-mmdc';
        $this->render($renderer, 'flowchart; A');
        $this->assertFileExists($project->path('mermaid-request.json'), 'a live CLI path change cannot reuse the previous tool snapshot');
        \unlink($project->path('mermaid-request.json'));
        $this->render($renderer, 'flowchart; B');
        $this->assertFileExists($project->path('mermaid-request.json'), 'changed source cannot reuse a previous diagram snapshot');

        foreach ([96, 192] as $dpi) {
            // @noinspection DisconnectedForeachInstructionInspection -- Remove the preceding render's record each time to prove the next render invokes the CLI.
            \unlink($project->path('mermaid-request.json'));
            [$picture, $error] = $this->render($renderer, 'flowchart; A[oversized-svg]', $dpi);
            $this->assertNull($error);
            $this->assertNull($picture->scene);
            $this->assertFileExists($project->path('mermaid-request.json'), 'large SVGs are displayed without retaining a cached copy or hit map');
        }
        $renderer->cancel();
    }

    #[DataProvider('unmapped')]
    public function testValidCliPicturesSurviveUnsupportedNativeSyntaxOrMissingSemanticMatches(string $source): void
    {
        $project = new TempProject();
        $renderer = new MermaidCliRenderer(
            new MermaidEngine($project->dir, self::support('fake-mmdc')),
            new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')),
        );
        [$picture, $error] = $this->render($renderer, $source);
        $this->assertNull($error);
        $this->assertSame([100, 50], PngDimensions::read($picture->png));
        $this->assertNull($picture->scene);
        $this->assertSame([], $picture->elements);
        $renderer->cancel();
    }

    public static function unmapped(): iterable
    {
        yield ['unsupported chart'];
        yield ['flowchart; C[Gamma]'];
    }

    #[DataProvider('converters')]
    public function testBrowserFailuresAndGarbageFallBackToCliPng(string $converter): void
    {
        $project = new TempProject();
        $renderer = new MermaidCliRenderer(
            new MermaidEngine($project->dir, self::support('fake-mmdc')),
            new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support($converter)),
        );
        [$picture, $error] = $this->render($renderer, 'flowchart; A');
        $this->assertNull($error);
        $this->assertSame([120, 80], PngDimensions::read($picture->png));
        $this->assertNull($picture->scene);
        [$options] = \json_decode((string) \file_get_contents($project->path('mermaid-request.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('png', $options['--outputFormat']);
        $renderer->cancel();
    }

    public static function converters(): iterable
    {
        yield ['fake-dot-fail'];
        yield ['fake-dot-garbage'];
    }

    public function testMissingBrowserUsesTheOriginalPngPathAndSvgFailuresReportErrors(): void
    {
        $project = new TempProject(['bin/.keep' => '']);
        \symlink(\PHP_BINARY, $project->path('bin/php'));
        \putenv('PATH='.$project->path('bin'));
        $engine = new MermaidEngine($project->dir, self::support('fake-mmdc'));
        $renderer = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc')));
        [$picture, $error] = $this->render($renderer, 'flowchart; A');
        $this->assertNull($error);
        $this->assertSame([120, 80], PngDimensions::read($picture->png));
        $this->assertNull($picture->scene);
        $renderer->cancel();
        $renderer = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')));
        [$picture, $error] = $this->render($renderer, 'broken source');
        $this->assertNull($picture);
        $this->assertStringContainsString('Mermaid parse error', $error);
        [$picture, $error] = $this->render($renderer, 'empty-output');
        $this->assertNull($picture);
        $this->assertSame('mmdc produced no SVG', $error);
        $renderer->cancel();
    }

    public function testWidgetUsesCliCoordinatesForSelectionAndRestoresNativeCells(): void
    {
        $project = new TempProject();
        $engine = new MermaidEngine($project->dir, self::support('fake-mmdc'));
        $cli = new MermaidCliRenderer($engine, new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')));
        $scheduler = new FakeScheduler();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, mermaid: $engine, cli: $cli);
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/diagram.mmd', "flowchart LR\nA[Alpha]\nB[Beta]\nA-->B");
        $widget->render(new RenderContext(200, 60));
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->await(static fn (): bool => [] !== $widget->nodeChoices(), what: 'the mapped CLI picture');
        $widget->render(new RenderContext(200, 60));
        $this->assertSame(['Alpha', 'Beta'], \array_column($widget->nodeChoices(), 'label'));
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
        $scene = Probe::get($widget, 'nativeScene');
        $node = $scene->graph->nodes[\array_key_first($scene->elements)];
        $grid = new GraphCellGrid(200, 60, Probe::get($widget, 'view'), null);
        $handler->events = [];
        $widget->selectAt($grid->rowOf($node->y), $grid->colOf($node->x));
        $this->assertSame(['view', 'reveal:1:0'], $handler->events);
        $previousBitmap = Probe::get($widget, 'bitmap');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->await(static fn (): bool => $previousBitmap !== Probe::get($widget, 'bitmap'), what: 'the refined mapped picture');
        $this->assertSame($node->id, Probe::get($widget, 'selected'), 'resolution-only refinement keeps semantic selection');

        $widget->setRenderMode('cells');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertFalse(Probe::get($widget, 'nativeBitmap'));
        $this->assertNotSame($scene, Probe::get($widget, 'nativeScene'));
        $this->assertSame(['Alpha', 'Beta'], \array_column($widget->nodeChoices(), 'label'));
        $widget->setRenderMode('bitmap');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $widget->hide();
        $this->assertSame([], $scheduler->scheduled);
        $widget->clear();
    }

    public function testCancellationRetiresNativeParsingAndMappingAndNewRequestsReplaceOldOnes(): void
    {
        $project = new TempProject();
        $parsing = new FakeScheduler();
        $mapping = new FakeScheduler();
        $renderer = new MermaidCliRenderer(
            new MermaidEngine($project->dir, self::support('fake-mmdc')),
            new MermaidSvgRasterizer($project->dir, self::support('fake-mmdc'), nodePath: self::support('fake-mermaid-browser')),
            new NativeMermaidRenderer(new MermaidTask($parsing)),
            new MermaidTask($mapping),
        );
        $pictures = [];
        $accept = static function (MermaidCliPicture $picture) use (&$pictures): void {
            $pictures[] = $picture;
        };
        $fail = static function (string $error): never {
            self::fail($error);
        };
        foreach ([false, true] as $advance) {
            $renderer->render('flowchart; A', dpi: 96, success: $accept, error: $fail);
            $this->await(static fn (): bool => [] !== $parsing->scheduled, what: 'native semantics to be scheduled');
            if ($advance) {
                while ([] !== $parsing->scheduled) {
                    $parsing->fire();
                }
                $this->assertNotEmpty($mapping->scheduled);
            }
            $renderer->cancel();
            $this->assertSame([], $parsing->scheduled);
            $this->assertSame([], $mapping->scheduled);
            $this->assertSame([], $pictures);
        }
        $renderer->render('slow obsolete', dpi: 96, success: $accept, error: $fail);
        $renderer->render('unsupported current', dpi: 96, success: $accept, error: $fail);
        $this->await(static fn (): bool => [] !== $parsing->scheduled, what: 'the replacement source');
        while ([] !== $parsing->scheduled) {
            $parsing->fire();
        }
        $this->assertCount(1, $pictures);
        $this->assertNull($pictures[0]->scene);
        $renderer->cancel();
    }
}
