<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidExport;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\NativeFenceActions;
use Porta\Help\Fence\FenceContext;
use Porta\Help\Fence\FenceElement;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TempProject;

final class NativeFenceActionsTest extends TestCase
{
    public function testPickerAndExportsUseTheFenceSnapshotAndDiagramNumber(): void
    {
        $project = new TempProject(['doc.md' => "```mermaid\nflowchart; A[Saved]\n```\n"]);
        $scheduler = new FakeScheduler();
        $exports = new NativeMermaidExport(new NativeMermaidRenderer(new MermaidTask($scheduler)));
        $messages = [];
        $choices = [];
        $picked = null;
        $elements = [new FenceElement('node', 'Unsaved', 3, [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]])];
        $actions = new NativeFenceActions(
            'flowchart; A[Unsaved]',
            $project->path('doc.md'),
            $elements,
            $exports,
            static fn (string $text, array $parameters): string => \strtr($text, $parameters),
            static function (string $message, float $duration) use (&$messages): void {
                $messages[] = [$message, $duration];
            },
            static function (string $title, array $items, \Closure $callback) use (&$choices, &$picked): \Closure {
                $choices = [$title, $items];
                $picked = $callback;

                return static function (): void {};
            },
        )->actions();
        $this->assertSame(['find-node', 'export-svg', 'export-png', 'export-pdf'], \array_column($actions, 'id'));
        $this->assertSame(['SVG', 'PNG', 'PDF'], \array_column(\array_slice($actions, offset: 1), 'label'));
        $selected = [];
        $context = new FenceContext(2, static function (string $id) use (&$selected): void {
            $selected[] = $id;
        });
        ($actions[0]->run)($context);
        $this->assertSame(['Find node', [['label' => 'Unsaved', 'hint' => '4', 'value' => 'node']]], $choices);
        $picked('node');
        $this->assertSame(['node'], $selected);
        foreach (['svg', 'png', 'pdf'] as $index => $extension) {
            ($actions[$index + 1]->run)($context);
            while ([] !== $scheduler->scheduled) {
                $scheduler->fire();
            }
            $path = $project->path('doc-diagram-2.'.$extension);
            $this->assertFileExists($path);
            $this->assertSame(['Exported doc-diagram-2.'.$extension, 3.0], $messages[$index]);
        }
        $this->assertStringContainsString('>Unsaved</text>', \file_get_contents($project->path('doc-diagram-2.svg')));
        $this->assertStringStartsWith("\x89PNG", \file_get_contents($project->path('doc-diagram-2.png')));
        $this->assertStringStartsWith('%PDF-', \file_get_contents($project->path('doc-diagram-2.pdf')));
        $this->assertStringContainsString('Saved', \file_get_contents($project->path('doc.md')));
        $this->assertStringNotContainsString('Unsaved', \file_get_contents($project->path('doc.md')));
        $exports->cancel();
    }

    public function testPickerLabelsArePlainSingleLines(): void
    {
        $choices = [];
        $actions = new NativeFenceActions(
            'flowchart; A',
            '/doc.md',
            [new FenceElement('one', " A\nB\t\x1b[31mC\x1b[0m\x07", 0, [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]])],
            new NativeMermaidExport(),
            static fn (string $text): string => $text,
            static function (): void {},
            static function (string $title, array $items) use (&$choices): \Closure {
                $choices = $items;

                return static function (): void {};
            },
        )->actions();
        ($actions[0]->run)(new FenceContext(1, static function (): void {}));
        $this->assertSame('A B C', $choices[0]['label']);
    }

    public function testFailedExportsKeepThePriorFileAndReportFailureWithoutAnEmptyPicker(): void
    {
        $project = new TempProject(['doc.md' => 'source', 'doc-diagram-1.svg' => 'prior']);
        $scheduler = new FakeScheduler();
        $messages = [];
        $actions = new NativeFenceActions(
            'flowchart; A[',
            $project->path('doc.md'),
            [],
            new NativeMermaidExport(new NativeMermaidRenderer(new MermaidTask($scheduler))),
            static fn (string $text, array $parameters): string => \strtr($text, $parameters),
            static function (string $message, float $duration) use (&$messages): void {
                $messages[] = [$message, $duration];
            },
            static function (): never {
                self::fail('No semantic elements should create a picker');
            },
        )->actions();
        $this->assertSame(['export-svg', 'export-png', 'export-pdf'], \array_column($actions, 'id'));
        ($actions[0]->run)(new FenceContext(1, static function (): void {}));
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame('prior', \file_get_contents($project->path('doc-diagram-1.svg')));
        $this->assertStringStartsWith('Export failed:', $messages[0][0]);
        $this->assertSame(5.0, $messages[0][1]);
    }
}
