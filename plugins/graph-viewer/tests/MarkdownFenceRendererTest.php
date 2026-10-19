<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\MarkdownFenceRenderer;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Help\Fence\FenceAction;
use Porta\Help\Fence\FenceResult;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;

final class MarkdownFenceRendererTest extends TestCase
{
    use PumpsLoop;
    use PreservesPath;

    public function testCliPictureRetainsSourceLinkedFenceRegionsFromTheSameSvg(): void
    {
        $project = new TempProject(['bin/.keep' => '']);
        \symlink(\PHP_BINARY, $project->path('bin/php'));
        $support = \dirname(__DIR__, levels: 3).'/tests/Support/';
        \symlink($support.'fake-mermaid-browser', $project->path('bin/node'));
        \putenv('PATH='.$project->path('bin'));
        $renderer = new MarkdownFenceRenderer(
            static fn (string $key): string => 'renderMode' === $key ? 'bitmap' : $support.'fake-mmdc',
            static fn (string $text): string => $text,
        );
        $result = null;
        $cancel = $renderer(
            'mermaid',
            source: "flowchart LR\nA[Alpha]\nB[Beta]\nA-->B",
            path: $project->path('doc.md'),
            width: 80,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                self::fail($error);
            },
        );
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the CLI fence semantic regions');
        $this->assertSame([100, 50], PngDimensions::read($result->png));
        $this->assertSame(['Alpha', 'Beta'], \array_column($result->elements, 'label'));
        $this->assertSame([1, 2], \array_column($result->elements, 'origin'));
        $this->assertNull($result->error);
        $cancel();
    }

    public function testNativeResultsKeepSemanticElementsWhenActionsAreAttached(): void
    {
        $scheduler = new FakeScheduler();
        $received = [];
        $renderer = new MarkdownFenceRenderer(
            static fn (string $key): string => 'cells',
            static fn (string $text): string => $text,
            static fn (): NativeMermaidRenderer => new NativeMermaidRenderer(new MermaidTask($scheduler)),
            static function (string $source, string $path, FenceResult $result) use (&$received): array {
                $received = [$source, $path, \array_column($result->elements, 'label')];

                return [new FenceAction('export-svg', 'SVG', static function (): void {})];
            },
        );
        $result = null;
        $cancel = $renderer(
            'mermaid',
            source: 'flowchart; A[First] --> B[Last]',
            path: '/doc.md',
            width: 40,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (): never {
                self::fail('Native rows should render');
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame(['flowchart; A[First] --> B[Last]', '/doc.md', ['First', 'Last']], $received);
        $this->assertCount(2, $result->elements);
        $this->assertSame(['export-svg'], \array_column($result->actions, 'id'));
        $cancel();
    }

    public function testEveryFenceUsesAnIndependentEngineAndReadsLivePaths(): void
    {
        $project = new TempProject();
        $support = \dirname(__DIR__, levels: 3).'/tests/Support/';
        $paths = ['renderMode' => 'bitmap', 'dotPath' => $support.'fake-dot', 'plantumlPath' => $support.'fake-plantuml', 'mermaidPath' => $support.'fake-mmdc'];
        $renderer = new MarkdownFenceRenderer(
            static function (string $key) use (&$paths): string {
                return $paths[$key];
            },
            static fn (string $message): string => 'translated: '.$message,
        );
        foreach (['dot', 'graphviz', 'plantuml', 'puml', 'mermaid'] as $language) {
            $result = null;
            $error = null;
            $cancel = $renderer(
                $language,
                source: 'graph TD; A-->B',
                path: $project->dir.'/doc.md',
                width: 40,
                success: static function (FenceResult $value) use (&$result): void {
                    $result = $value;
                },
                error: static function (string $value) use (&$error): void {
                    $error = $value;
                },
            );
            $this->await(static function () use (&$result, &$error): bool {
                return null !== $result || null !== $error;
            }, what: 'fence PNG');
            $this->assertNull($error);
            $this->assertStringStartsWith("\x89PNG", $result->png);
            $cancel();
        }
        $paths['dotPath'] = $support.'fake-dot-garbage';
        $error = null;
        $renderer(
            'dot',
            source: 'digraph {}',
            path: $project->dir.'/doc.md',
            width: 40,
            success: static function (): never {
                self::fail('malformed PNG accepted');
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'malformed PNG error');
        $this->assertSame('translated: Cannot display diagram PNG', $error);
        $error = null;
        $paths['dotPath'] = $support.'fake-dot';
        $renderer(
            'dot',
            source: 'broken',
            path: $project->dir.'/doc.md',
            width: 40,
            success: static function (): never {
                self::fail('invalid DOT accepted');
            },
            error: static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $this->await(static function () use (&$error): bool {
            return null !== $error;
        }, what: 'syntax error');
        $this->assertStringStartsWith('translated:', $error);
        $this->expectException(\InvalidArgumentException::class);
        $renderer('plantxml', source: '', path: $project->dir.'/doc.md', width: 40, success: static function (): void {}, error: static function (): void {});
    }
}
