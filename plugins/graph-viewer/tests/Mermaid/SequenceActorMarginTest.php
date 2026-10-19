<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidText;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;

final class SequenceActorMarginTest extends TestCase
{
    private const string SOURCE = "sequenceDiagram\nparticipant A as Long participant label\nparticipant B\nA->>B: hello";

    private static function scene(MermaidConfiguration $configuration, string $source = self::SOURCE): MermaidScene
    {
        $parser = new SequenceParser($configuration);
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new SequenceLayout($configuration)->layout($parser->finish());
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testMarginChangesColumnsAndMessageEndpointsWithoutChangingSourceIdentity(): void
    {
        $default = self::scene(new MermaidConfiguration());
        $zero = self::scene(new MermaidConfiguration(sequenceActorMargin: 0));
        $wide = self::scene(new MermaidConfiguration(sequenceActorMargin: 100));
        $this->assertEquals($default, self::scene(new MermaidConfiguration(sequenceActorMargin: 50)));
        $this->assertSame($default->legend, $wide->legend);
        $this->assertSame($default->origins, $wide->origins);
        $this->assertSame(104.0, $zero->graph->width);
        $this->assertSame(136.0, $default->graph->width);
        $this->assertSame(168.0, $wide->graph->width);
        $actors = \array_values(\array_filter($wide->graph->nodes, static fn (GraphNode $node): bool => \in_array($node->name, ['A', 'B'], strict: true)));
        $this->assertCount(4, $actors, 'headers and their mirrored footers');
        $this->assertSame(42.0, $actors[1]->x - $actors[0]->x);
        $this->assertSame($actors[0]->x, $wide->graph->edges[0]->points[0][0]);
        $this->assertSame($actors[1]->x, $wide->graph->edges[0]->arrowTip[0]);
        $this->assertSame($zero->graph->nodes[0]->w, $wide->graph->nodes[0]->w);
    }

    public function testZeroMarginKeepsGroupFramesOutsideLongHeadersAndActivationBars(): void
    {
        $source = "sequenceDiagram\nbox Team\nparticipant A as Long participant label\nparticipant B\nend\nA->>B: hi\nactivate B\nactivate B\nB->>B: work\ndeactivate B\ndeactivate B";
        $scene = self::scene(new MermaidConfiguration(sequenceActorMargin: 0), $source);
        $frame = $scene->graph->clusters[0];
        foreach ($scene->graph->nodes as $node) {
            if (!\in_array($node->name, ['A', 'B', 'activation0', 'activation1'], strict: true)) {
                continue;
            }
            $this->assertGreaterThanOrEqual($frame->x0, $node->x - ($node->w / 2));
            $this->assertLessThanOrEqual($frame->x1, $node->x + ($node->w / 2));
        }
    }

    public function testFrontMatterAndInitializationPreserveZeroOverridesInGraphicsAndRows(): void
    {
        $prefix = "---\nconfig:\n  sequence:\n    actorMargin: 100\n---\n";
        $wide = NativeFixtureExport::render($prefix.self::SOURCE, GraphExportFormat::Svg);
        $zero = $prefix."%%{init: {'sequence': {'actorMargin': 0}}}%%\n".self::SOURCE;
        $compact = NativeFixtureExport::render($zero, GraphExportFormat::Svg);
        $this->assertNotSame($wide, $compact);
        $this->assertStringContainsString('Long participant label', $compact);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($zero, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($zero, width: 40);
        $this->assertStringContainsString('hello', \implode("\n", $rows->rows));
        $this->assertContains(7, $rows->origins);
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach ([0, 50, 1000] as $value) {
            $configuration = MermaidConfiguration::parse((object) ['sequence' => (object) ['actorMargin' => $value]], $statement);
            $this->assertSame($value, $configuration->sequenceActorMargin);
            $this->assertSame($configuration, $configuration->overlay(new MermaidConfiguration()));
            $this->assertSame($value, new MermaidConfiguration()->overlay($configuration)->sequenceActorMargin);
            $this->assertSame(0, $configuration->overlay(new MermaidConfiguration(sequenceActorMargin: 0))->sequenceActorMargin);
        }
    }

    public function testEveryMeasuredLabelRetainsMarginAndMinimumColumnSpacing(): void
    {
        foreach ([
            ["sequenceDiagram\nparticipant A\nparticipant B",                               'A'],
            ["sequenceDiagram\nparticipant A\nparticipant B\nA->>B: A much longer message", 'A much longer message'],
            ["sequenceDiagram\ntitle A much longer title\nparticipant A\nparticipant B",    'A much longer title'],
            ["sequenceDiagram\nbox A much longer group\nparticipant A\nparticipant B\nend", 'A much longer group'],
        ] as [$source, $longest]) {
            $compact = self::scene(new MermaidConfiguration(sequenceActorMargin: 0), $source);
            $wide = self::scene(new MermaidConfiguration(sequenceActorMargin: 1000), $source);
            $this->assertSame(4 * (MermaidText::box($longest)[0] + 160.0), $wide->graph->width);
            $this->assertSame($compact->legend, $wide->legend);
            $actors = \array_values(\array_filter($compact->graph->nodes, static fn (GraphNode $node): bool => \in_array($node->name, ['A', 'B'], strict: true)));
            $this->assertGreaterThanOrEqual(16.0, $actors[1]->x - $actors[0]->x);
        }
    }

    public function testCreatedAndMirroredParticipantsUseTheSameConfiguredColumns(): void
    {
        $source = "sequenceDiagram\nparticipant A as Long participant label\ncreate participant B\nA->>B: create\nB->>A: reply";
        $scene = self::scene(new MermaidConfiguration(mirrorActors: true, sequenceActorMargin: 100), $source);
        foreach (['A' => 42.0, 'B' => 84.0] as $actor => $x) {
            $headers = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => $actor === $node->name));
            $this->assertCount(2, $headers);
            $this->assertSame([$x, $x], \array_column($headers, 'x'));
        }
        $this->assertSame(84.0 - (MermaidText::box('B')[0] / 2), $scene->graph->edges[0]->arrowTip[0], 'the creating message meets the header side');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'negative' => [-1];
        yield 'large' => [1001];
        yield 'fraction' => [0.5];
        yield 'float integer' => [50.0];
        yield 'string' => ['50'];
        yield 'boolean' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('invalid')]
    public function testInvalidMarginsProduceLocatedErrors(mixed $value): void
    {
        try {
            MermaidConfiguration::parse((object) ['sequence' => (object) ['actorMargin' => $value]], new MermaidStatement('', offset: 12, line: 3, column: 2));
            $this->fail('Invalid margin accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Sequence actorMargin must be an integer from 0 to 1000', $error->getMessage());
            $this->assertSame(3, $error->sourceLine);
            $this->assertSame(2, $error->column);
        }
    }

    public function testZeroMarginIsStillSpecificToSequenceDiagrams(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply');
        new MermaidConfiguration(sequenceActorMargin: 0)->validateFamily('packet');
    }
}
