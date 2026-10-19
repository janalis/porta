<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidFrontMatter;
use Porta\GraphViewer\Mermaid\MermaidInitialization;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceDiagram;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;
use Porta\GraphViewer\Mermaid\SequenceVisibility;

final class SequenceVisibilityTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testVisibilityProjectsGroupsMenusAndTypesWithoutMutatingTheSource(): void
    {
        $source = self::parse(<<<'MMD'
            sequenceDiagram
            box Hidden group
            participant H@{"type":"database"} as Hidden
            end
            box Team
            participant 0 as Zero
            participant U as Unused
            participant A as Alice
            end
            link H: Hidden docs @ https://example.test/hidden
            link A: Docs @ https://example.test/docs
            loop Run
            0->>+A: Work
            end
            create participant B as Born
            A->>B: Hello
            destroy B
            B->>A: Done
            MMD);
        $work = SequenceVisibility::apply($source, new MermaidConfiguration(hideUnusedParticipants: true));
        $this->assertCount(\count($source->events) + 6, \iterator_to_array($work), message: 'events, group members and groups each yield a cooperative step');
        $visible = $work->getReturn();
        $this->assertNotSame($source, $visible);
        $this->assertSame([0, 'A', 'B'], \array_keys($visible->participants));
        $this->assertSame([['label' => 'Team', 'actors' => ['0', 'A'], 'origin' => 4]], $visible->groups);
        $this->assertSame([0 => 0, 'A' => 0], $visible->memberships);
        $this->assertSame(['A'], \array_keys($visible->menus));
        $this->assertSame([], $visible->participantTypes);
        $this->assertSame($source->events, $visible->events);
        $this->assertSame($source->frames, $visible->frames);
        $this->assertSame($source->activations, $visible->activations);
        $this->assertSame($source->lifetimes, $visible->lifetimes);
        $this->assertCount(5, $source->participants);
        $this->assertCount(2, $source->groups);
        $this->assertArrayHasKey('H', $source->menus);
        $this->assertSame('database', $source->participantTypes['H']);
        $layout = new SequenceLayout(new MermaidConfiguration(mirrorActors: true, hideUnusedParticipants: true))->layout($source);
        \iterator_to_array($layout);
        $scene = $layout->getReturn();
        $names = \array_column($scene->graph->nodes, 'name');
        $this->assertNotContains('H', $names);
        $this->assertNotContains('U', $names);
        $this->assertSame(2, \array_count_values($names)[0]);
        $this->assertSame(2, \array_count_values($names)['A']);
        $this->assertSame(2, \array_count_values($names)['B'], 'created, then destroyed beneath its message');
        $original = new SequenceLayout()->layout($source);
        \iterator_to_array($original);
        $this->assertLessThan($original->getReturn()->graph->width, $scene->graph->width);
        $this->assertCount(5, $source->participants);
        $this->assertSame(['Team', 'loop'], \array_column($scene->graph->clusters, 'label'));
        foreach ($scene->graph->nodes as $node) {
            if ('A' !== $node->name) {
                continue;
            }
            $this->assertSame(7, $scene->origin($node->id));
        }
    }

    public function testNotesAndActivationOnlyParticipantsRemainVisible(): void
    {
        $source = self::parse('sequenceDiagram; participant Hidden; participant A; participant B; participant C; Note over A,B: Remember; activate C; deactivate C');
        $work = SequenceVisibility::apply($source, new MermaidConfiguration(hideUnusedParticipants: true));
        \iterator_to_array($work);
        $this->assertSame(['A', 'B', 'C'], \array_keys($work->getReturn()->participants));
        $unchanged = SequenceVisibility::apply($source, new MermaidConfiguration());
        $this->assertSame([], \iterator_to_array($unchanged));
        $this->assertSame($source, $unchanged->getReturn());
        $disabled = SequenceVisibility::apply($source, new MermaidConfiguration(hideUnusedParticipants: false));
        \iterator_to_array($disabled);
        $this->assertSame($source, $disabled->getReturn());
    }

    public function testNativeExportsRowsOverridesAndEmptyDiagrams(): void
    {
        $body = "sequenceDiagram\nparticipant Hidden\nparticipant A as Alice\nparticipant B as Bob\nA->>B: Hello";
        $prefix = "---\nconfig: {sequence: {hideUnusedParticipants: true}}\n---\n";
        $source = $prefix.$body;
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringNotContainsString('Hidden', $svg);
        $this->assertStringContainsString('Alice', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 35);
        $this->assertStringNotContainsString('Hidden', \implode("\n", $rows->rows));
        $this->assertContains(5, $rows->origins);
        $this->assertContains(7, $rows->origins);
        $override = $prefix."%%{init: {sequence: {hideUnusedParticipants: false}}}%%\n".$body;
        $this->assertStringContainsString('Hidden', NativeFixtureExport::render($override, GraphExportFormat::Svg));
        $enabled = "%%{initialize: {sequence: {hideUnusedParticipants: true}}}%%\n".$body;
        $this->assertStringNotContainsString('Hidden', NativeFixtureExport::render($enabled, GraphExportFormat::Svg));
        $empty = $prefix."sequenceDiagram\nbox Empty\nparticipant Hidden\nend";
        $this->assertStringNotContainsString('Hidden', NativeFixtureExport::render($empty, GraphExportFormat::Svg));
        $this->assertStringNotContainsString('Empty', NativeFixtureExport::render($empty, GraphExportFormat::Svg));
    }

    public function testIndependentOptionsMergeAndRejectTheWrongFamily(): void
    {
        $front = MermaidFrontMatter::parse("---\nconfig: {sequence: {hideUnusedParticipants: true}}\n---\nsequenceDiagram");
        $work = MermaidInitialization::parse('%%{init: {sequence: {showSequenceNumbers: true, mirrorActors: true}}}%%', $front->configuration);
        \iterator_to_array($work);
        $configuration = $work->getReturn()->configuration;
        $this->assertTrue($configuration->hideUnusedParticipants);
        $this->assertTrue($configuration->sequenceNumbers);
        $this->assertTrue($configuration->mirrorActors);
        $this->expectException(MermaidSyntaxError::class);
        $front->configuration->validateFamily('pie');
    }

    public function testInvalidVisibilityConfigurationIsLocated(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Line 1, column 1: Sequence hideUnusedParticipants must be boolean');
        MermaidFrontMatter::parse("---\ntitle: Test\nconfig: {sequence: {hideUnusedParticipants: 1}}\n---\nsequenceDiagram");
    }
}
