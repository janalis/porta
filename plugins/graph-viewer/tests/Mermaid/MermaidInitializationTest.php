<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidFrontMatter;
use Janalis\Viz\Mermaid\MermaidInitialization;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidSource;
use Janalis\Viz\Mermaid\PieLayout;
use Janalis\Viz\Mermaid\PieParser;
use Janalis\Viz\Mermaid\SequenceParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

final class MermaidInitializationTest extends TestCase
{
    private static function initialize(string $source, MermaidConfiguration $configuration = new MermaidConfiguration()): MermaidInitialization
    {
        $work = MermaidInitialization::parse($source, $configuration);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testDirectiveBatchesYieldAndCancelBeforeBuildingTheScene(): void
    {
        $steps = MermaidInitialization::parse('%%{init: {}}%%%%{init: {}}%%');
        $this->assertCount(2, \iterator_to_array($steps), message: 'each directive yields a cancellable step');
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $scene = null;
        $cancel = $renderer->render(
            \str_repeat('%%{init: {}}%%', times: 4096).'sequenceDiagram; A->B: Ready',
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNull($scene, 'initialization yields before producing a scene');
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $renderer->render(
            'sequenceDiagram; A->B: Replacement',
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(MermaidScene::class, $scene);
        $this->assertStringContainsString('Replacement', \implode("\n", \array_column($scene->legend, 'text')));
    }

    public function testFrontMatterAndDirectivesMergeInOrderWithoutLosingFalseOrOrigins(): void
    {
        $source = "---\ntitle: Numbering\nconfig:\n  sequence:\n    showSequenceNumbers: true\n---\n%% ordinary comment\n  %%{init: {'sequence': {'showSequenceNumbers': true}}}%%\r\n%%{initialize: {sequence: {showSequenceNumbers: false}}}%%\nsequenceDiagram\nA->>B: First\nautonumber 3.25 0.5\nB-->>A: Second";
        $front = MermaidFrontMatter::parse($source);
        $document = self::initialize($front->source, $front->configuration);
        $this->assertFalse($document->configuration->sequenceNumbers);
        $this->assertSame(\strlen($source), \strlen($document->source));
        $parser = new SequenceParser($document->configuration);
        foreach (new MermaidLexer($document->source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $this->assertSame(['First', '3.25. Second'], \array_column($diagram->events, 'label'));
        $this->assertSame([10, 12], \array_column($diagram->events, 'origin'));
        $rows = NativeFixtureRows::render($source, width: 50);
        $this->assertStringContainsString('3.25. Second', \implode("\n", $rows->rows));
        $this->assertContains(12, $rows->origins);
    }

    public function testConfiguredNumberingRemainsVisibleAcrossAutonumberOffAndResets(): void
    {
        $source = "---\nconfig: {sequence: {showSequenceNumbers: true}}\n---\nsequenceDiagram\nA->>B: First\nautonumber off\nNote over A: Note\nB->>A: Second\nautonumber 10 2\nA->>B: Third";
        $rows = NativeFixtureRows::render($source, width: 70);
        $text = \implode("\n", $rows->rows);
        foreach (['1. First', '2. Second', '10. Third'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        $this->assertStringNotContainsString('3. Note', $text);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('2. Second', $svg);
    }

    public function testALeadingByteOrderMarkIsDroppedAsABrowserDropsIt(): void
    {
        $this->assertSame("   ---\n", MermaidSource::unmarked("\u{FEFF}---\n"));
        $this->assertSame("a\u{FEFF}", MermaidSource::unmarked("a\u{FEFF}"));
        $this->assertStringContainsString('>Marked</text>', NativeFixtureExport::render("\u{FEFF}flowchart TD\nA[Marked]", Format::Svg));
        // As pinned, the mark reads as leading space: front matter must open the file.
        $scheduler = new FakeScheduler();
        $error = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "\u{FEFF}---\ntitle: T\n---\nflowchart TD\nA",
            Format::Svg,
            static function (): void {},
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertSame('Line 1, column 4: Unclosed Mermaid front matter', $error);
    }

    public function testPieHighlightChangesOnlyTheMatchingSlicePresentation(): void
    {
        $source = "---\nconfig: {pie: {highlightSlice: Cats}}\n---\n%%{init: {pie: {highlightSlice: Dogs}}}%%\npie showData\n\"Dogs\": 3\n\"Cats\": 1\n\"Empty\": 0";
        $front = MermaidFrontMatter::parse($source);
        $document = self::initialize($front->source, $front->configuration);
        $parser = new PieParser();
        foreach (new MermaidLexer($document->source, chart: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new PieLayout($document->configuration)->layout($parser->finish());
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $nodes = \array_intersect_key($scene->graph->nodes, $scene->elements);
        [$dogs, $cats, $empty] = \array_values($nodes);
        $this->assertSame('* Dogs: 3 (75.00%)', $dogs->label);
        $this->assertSame('#ececff', $dogs->fillColor);
        $this->assertSame('#000000', $dogs->color);
        $this->assertSame(0.2, $dogs->strokeWidth);
        $this->assertSame(1.0, $dogs->opacity);
        $this->assertSame(0.7, $cats->opacity);
        $this->assertSame('25.2', \explode(':', $scene->shapes[$dogs->id])[6]);
        $this->assertSame('24', \explode(':', $scene->shapes[$cats->id])[6]);
        $this->assertSame('#ffffde', $cats->fillColor);
        $this->assertSame('Empty: 0 (0.00%)', $empty->label);
        $this->assertSame([], $scene->graph->edges);
        $this->assertSame([5, 6, 7], \array_values($scene->elements));
        $rows = NativeFixtureRows::render($source, width: 50);
        $this->assertStringContainsString('* Dogs: 3 (75.00%)', \implode("\n", $rows->rows));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('#ececff', $svg);
        $this->assertStringContainsString('opacity="0.7"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }
}
