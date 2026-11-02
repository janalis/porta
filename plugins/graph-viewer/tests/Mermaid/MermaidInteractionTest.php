<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Draw\Encoder;
use Janalis\Viz\Draw\PngBackend;
use Janalis\Viz\Mermaid\MermaidPipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\GraphViewerWidget;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphCellGrid;
use Porta\I18n\Translator;
use Porta\Tests\Support\FakeLayoutEngine;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Symfony\Component\Tui\Render\RenderContext;

final class MermaidInteractionTest extends TestCase
{
    #[DataProvider('diagrams')]
    public function testSemanticElementsCanBeSearchedSelectedAndFollowed(string $source, array $labels): void
    {
        $scheduler = new FakeScheduler();
        $picked = 0;
        $widget = new GraphViewerWidget(
            new FakeLayoutEngine(),
            new Translator('en'),
            scheduler: $scheduler,
            renderMode: 'cells',
            onFindNode: static function () use (&$picked): void {
                ++$picked;
            },
            mermaid: new FakeLayoutEngine(),
        );
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/diagram.mmd', $source);
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $widget->render(new RenderContext(180, 80));
        $this->assertSame($labels, \array_column($widget->nodeChoices(), 'label'));
        if (\str_starts_with($source, 'erDiagram') || \str_starts_with($source, 'classDiagram')) {
            $this->assertSame(['0 in · 1 out', '1 in · 0 out'], \array_column($widget->nodeChoices(), 'hint'));
            $this->assertStringStartsWith('2 nodes · 1 edges', $widget->statusInfo());
        }
        $widget->handleInput('/');
        $this->assertSame(1, $picked);
        $widget->handleInput('n');
        $this->assertStringEndsWith('· '.$labels[0], $widget->statusInfo());
        $widget->handleInput('p');
        $this->assertStringEndsWith('· '.\array_last($labels), $widget->statusInfo());
        $scene = Probe::get($widget, 'nativeScene');
        $id = \array_key_first($scene->elements);
        $node = $scene->graph->nodes[$id];
        $widget->focusNode($id);
        $handler->events = [];
        $grid = new GraphCellGrid(180, 80, Probe::get($widget, 'view'), null);
        $widget->selectAt($grid->rowOf($node->y), $grid->colOf($node->x));
        $this->assertSame(['view', 'reveal:'.$scene->elements[$id].':0'], $handler->events);
        $widget->followSource($scene->elements[$id], 0);
        $widget->followSource(999, 0);
        $this->assertCount(2, $handler->events, 'following the already selected element does not pan again');
        $handler->events = [];
        $widget->selectAt(0, 0);
        $this->assertSame(['view'], $handler->events, 'Empty space does not reveal a composite whose id happens to be -1');
        $handler->events = [];
        $widget->followSource($scene->elements[$id], 0);
        $this->assertSame(['view'], $handler->events);
        $this->assertStringEndsWith('· '.$labels[0], $widget->statusInfo());
        $widget->clear();
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function diagrams(): iterable
    {
        yield 'railroad' => ["railroad-beta\nentry=sequence(terminal(\"First\"),terminal(\"Last\"));", ['entry', 'First', 'Last']];
        yield 'railroad EBNF' => ["railroad-ebnf-beta\nentry=\"First\" \"Last\";", ['entry', 'First', 'Last']];
        yield 'railroad ABNF' => ["railroad-abnf-beta\nentry=\"First\" \"Last\";", ['entry', 'First', 'Last']];
        yield 'railroad PEG' => ["railroad-peg-beta\nentry<-\"First\" \"Last\";", ['entry', 'First', 'Last']];
        yield 'modern flowchart graphics' => ['flowchart LR; A@{shape:folder,label:"First"} --> B@{shape:doc,label:"Last"}', ['First', 'Last']];
        yield 'venn' => ["venn-beta\nset A [First]\nset B [Last]\nunion A,B", ['First', 'Last', 'A & B']];
        yield 'eventmodeling' => ["eventmodeling\ntf 01 ui Screen\ntf 02 cmd Work", ['01 · Screen', '02 · Work']];
        yield 'architecture' => ["architecture-beta\nservice a[First]\nservice b[Last]\na:R --> L:b", ['First', 'Last']];
        yield 'usecase' => ["usecase-beta\nactor User\nUser --> Order(Checkout)", ['User', 'Checkout']];
        yield 'er' => ["erDiagram\nCustomer ||--o{ Order : places", ['Customer', 'Order']];
        yield 'class' => ["classDiagram\nParent <|-- Child", ['Parent', 'Child']];
        yield 'class markers at both ends' => ["classDiagram\nParent <|--|> Child", ['Parent', 'Child']];
        yield 'gantt' => ["gantt\nsection Work\nA:a, 2024-01-01, 1d\nB:b, after a, 1d", ['Work', 'A', 'B']];
        yield 'agentflow' => ["agentflow-beta LR\nflow Agent\nA[\"First\"]@{shape: task}\nend\nA --> B[\"Second\"]", ['Second', 'First', 'Agent']];
        yield 'swimlane' => ["swimlane-beta LR\nsubgraph Team\nA[\"First\"]\nB[\"Second\"]\nend\nA --> B", ['First', 'Second', 'Team']];
        yield 'block' => ["block\nA[\"A\"] B[\"B\"]\nA --> B", ['A', 'B']];
        yield 'c4' => ["C4Context\nSystem(a,\"A\")\nSystem(b,\"B\")\nBiRel(a,b,\"Uses\")", ['A [Software System]', 'B [Software System]']];
        yield 'c4 boundary' => ["C4Context\nBoundary(b,\"Boundary\") {\nSystem(a,\"A\")\n}", ['A [Software System]', 'Boundary [System]']];
        yield 'wardley' => ["wardley-beta\ncomponent First [0.8,0.2]\ncomponent Last [0.3,0.7]\nFirst -> Last", ['First', 'Last']];
        yield 'git' => ["gitGraph\ncommit id:'A'\nbranch topic\ncommit id:'B'", ['main', 'topic', 'A', 'B']];
        yield 'state' => ["stateDiagram-v2\nA --> B", ['A', 'B']];
        yield 'empty composite state' => ["stateDiagram-v2\nstate Parent {}", ['Parent']];
        yield 'info' => ['info showInfo', ['Janalis Viz native Mermaid Compatibility target: v12.1.0']];
        yield 'requirement' => ["requirementDiagram\nrequirement Safe {}\nelement Code {}", ['<<Requirement>> Safe', '<<Element>> Code']];
        yield 'cynefin' => ["cynefin-beta\ncomplex\n\"Explore\"", ['Complex', 'Complicated', 'Chaotic', 'Clear', 'Confusion', 'Complex: Explore']];
        yield 'radar' => ["radar-beta\naxis A, B, C\ncurve S{1,2,3}", ['S · A: 1', 'S · B: 2', 'S · C: 3']];
        yield 'ishikawa' => ["ishikawa\nEffect\nCause\n  Detail", ['Effect', 'Cause', 'Detail']];
        yield 'tree-view' => ["treeView-beta\nRoot/\n  First\n  Last ## Details", ['Root/', 'First', 'Last — Details']];
        yield 'treemap' => ["treemap\n\"Root\"\n  \"First\":4\n  \"Last\":1", ['Root (5)', 'First (4)', 'Last (1)']];
        yield 'sankey' => ["sankey\nSource,Target,10", ['Source', 'Target']];
        yield 'kanban' => ["kanban\nTodo\n  First\nDone\n  Last", ['Todo', 'First', 'Done', 'Last']];
        yield 'packet' => ["packet\n0-15: \"Source\"\n+16: \"Destination\"", ['0–15: Source', '16–31: Destination']];
        yield 'xy' => ["xychart\nx-axis [A, B]\nline [10,20]", ['line 1 · A: 10', 'line 1 · B: 20']];
        yield 'quadrant' => ["quadrantChart\nquadrant-1 Decoration\nA: [0.75, 0.93]\nB: [0.25, 0.25]", ['A', 'B']];
        yield 'mindmap' => ["mindmap\nRoot\n  Same\n    Leaf\n  Same", ['Root', 'Same', 'Same', 'Leaf']];
        yield 'flowchart' => ["graph LR\nA[First]-->B[Second]", ['First', 'Second']];
        yield 'pie' => ["pie title Demo\n\"Dogs\":3\n\"Cats\":1", ['Dogs (75.00%)', 'Cats (25.00%)']];
        yield 'journey' => ["journey\nsection Heading\nTask:5:A", ['1. Task | score: 5/5 | actors: ["A"]']];
        yield 'timeline' => ["timeline\nsection Heading\n2000 : Event", ['2000', 'Event']];
        yield 'sequence' => ["sequenceDiagram\ntitle Demo\nparticipant A\nA->>+B: Message\nB-->>-A: Reply", ['A', 'B', 'Message', 'Reply', 'A', 'B']];
    }

    public function testDecorationsAndExternalBitmapHaveNoMisalignedSelection(): void
    {
        $scheduler = new FakeScheduler();
        $mermaid = new FakeLayoutEngine();
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: $mermaid);
        $widget->show('/diagram.mmd', "timeline\ntitle Decoration\nPeriod");
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $widget->render(new RenderContext(180, 80));
        $widget->focusNode(0);
        $this->assertNull(Probe::get($widget, 'selected'));
        $node = Probe::get($widget, 'nativeScene')->graph->nodes[0];
        $grid = new GraphCellGrid(180, 80, Probe::get($widget, 'view'), null);
        $widget->selectAt($grid->rowOf($node->y), $grid->colOf($node->x));
        $this->assertNull(Probe::get($widget, 'selected'));
        $widget->handleInput('n');
        $this->assertSame(1, Probe::get($widget, 'selected'));
        $mermaid->nextPng = PngFixture::bytes(120, 80);
        $widget->setRenderMode('bitmap');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame([], $widget->nodeChoices());
        $this->assertNull(Probe::get($widget, 'selected'));
        $widget->followSource(2, 0);
        $this->assertNull(Probe::get($widget, 'selected'));
        $widget->clear();
    }

    public function testMissingGdFallsBackToCellsAndNativeModeCanToggleWithoutCli(): void
    {
        $scheduler = new FakeScheduler();
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;
        $native = new NativeMermaidRenderer(new MermaidTask($scheduler), new MermaidPipeline(encoder: new Encoder(new PngBackend(gdAvailable: false))));
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, mermaid: $mermaid, native: $native);
        $widget->show('/diagram.mmd', 'graph LR; A[Readable]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $screen = \implode("\n", $widget->render(new RenderContext(160, 30)));
        $this->assertStringContainsString('GD extension', $screen);
        $this->assertStringContainsString('Readable', $screen);
        $widget->clear();

        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, renderMode: 'cells', mermaid: $mermaid);
        $widget->show('/diagram.mmd', 'graph LR; A[Readable]');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $widget->setRenderMode('bitmap');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertTrue(Probe::get($widget, 'nativeBitmap'));
        $this->assertStringContainsString('▀', \implode('', $widget->render(new RenderContext(80, 20))));
        $widget->setRenderMode('cells');
        $this->assertFalse(Probe::get($widget, 'nativeBitmap'));
        $this->assertStringContainsString('Readable', \implode('', $widget->render(new RenderContext(80, 20))));
        $widget->clear();
    }

    public function testNativeBitmapHitTestingRejectsTheEmptyCornersOfACircle(): void
    {
        $scheduler = new FakeScheduler();
        $mermaid = new FakeLayoutEngine();
        $mermaid->binary = null;
        $widget = new GraphViewerWidget(new FakeLayoutEngine(), new Translator('en'), scheduler: $scheduler, mermaid: $mermaid);
        $widget->show('/circle.mmd', 'graph TD; A((Circle))');
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $widget->render(new RenderContext(100, 40));
        $widget->handleInput('1');
        $node = Probe::get($widget, 'nativeScene')->graph->nodes[0];
        $grid = new GraphCellGrid(100, 40, Probe::get($widget, 'view'), null);
        $widget->selectAt($grid->rowOf($node->y), $grid->colOf($node->x));
        $this->assertSame(0, Probe::get($widget, 'selected'));
        $widget->selectAt($grid->rowOf($node->y - ($node->h / 2) + 0.1), $grid->colOf($node->x - ($node->w / 2) + 0.1));
        $this->assertNull(Probe::get($widget, 'selected'), 'the circle does not fill the corners of its bounding box');
        $widget->selectAt(0, 0);
        $this->assertNull(Probe::get($widget, 'selected'), 'empty space has no semantic element');
        $widget->clear();
    }
}
