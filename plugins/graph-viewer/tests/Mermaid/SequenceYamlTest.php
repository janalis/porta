<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class SequenceYamlTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testFlowAndBlockMapsPreserveAliasesTypesAndOrigins(): void
    {
        $diagram = self::parse(<<<'MMD'
            sequenceDiagram
            box Team
            participant A@{type: boundary, alias: 'User''s {portal}; #hash %% literal'}
            actor B@{
              type: database
              alias: Store
              # ignore } [ " ;
            } as External
            end
            create actor C@{
              type: queue
              alias: >-
                New
                worker
            }
            A->>C: create
            C->>B: save
            MMD);
        $this->assertSame("User's {portal}; #hash %% literal", $diagram->participants['A']['label']);
        $this->assertSame('External', $diagram->participants['B']['label']);
        $this->assertSame('New worker', $diagram->participants['C']['label']);
        $this->assertSame(['A' => 'boundary', 'B' => 'database', 'C' => 'queue'], $diagram->participantTypes);
        $this->assertSame([2, 3, 9], \array_column($diagram->participants, 'origin'));
        $this->assertSame(['A', 'B'], $diagram->groups[0]['actors']);
        $this->assertSame(1, $diagram->lifetimes['C']['create']);
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertStringContainsString(' [boundary]', \implode("\n", \array_column($scene->legend, 'text')));
        $this->assertContains(2, $scene->elements);
        $rows = NativeFixtureRows::render("sequenceDiagram\nparticipant A@{type: boundary, alias: Portal}\nA->>B: send", width: 24);
        $this->assertStringContainsString('Portal', \implode("\n", $rows->rows));
        $this->assertContains(1, $rows->origins);
    }

    public function testScalarReferencesPreserveTypesLabelsAndDeclarationOrigins(): void
    {
        $source = <<<'MMD'
            sequenceDiagram
            participant A@{type: &kind boundary, alias: *kind}
            create participant B@{
              alias: &label 'queue'
              type: *label
            }
            A->>B: create
            MMD;
        $diagram = self::parse($source);
        $this->assertSame(['boundary', 'queue'], \array_column($diagram->participants, 'label'));
        $this->assertSame(['A' => 'boundary', 'B' => 'queue'], $diagram->participantTypes);
        $this->assertSame([1, 2], \array_column($diagram->participants, 'origin'));
        $this->assertSame(1, $diagram->lifetimes['B']['create']);
        $rows = NativeFixtureRows::render($source, width: 32);
        $this->assertStringContainsString('boundary', \implode("\n", $rows->rows));
        $this->assertContains(2, $rows->origins);
        $this->assertStringContainsString('queue', NativeFixtureExport::render($source, Format::Svg));
    }

    public function testTimestampAliasesSurviveCreationWrappingExportsAndSourceNavigation(): void
    {
        $source = "sequenceDiagram\nparticipant A@{alias: 2026-10-17}\ncreate participant B@{alias: 2001-12-15T02:59:43.1200Z}\nA->>B: create";
        $diagram = self::parse($source);
        $this->assertSame([1, 2], \array_column($diagram->participants, 'origin'));
        $this->assertSame(1, $diagram->lifetimes['B']['create']);
        $rows = NativeFixtureRows::render($source, width: 32);
        $this->assertStringContainsString('2026-10-17', \implode("\n", $rows->rows));
        $this->assertContains(2, $rows->origins);
        $this->assertStringContainsString('2001-12-15T02:59:43.1200Z', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }
}
