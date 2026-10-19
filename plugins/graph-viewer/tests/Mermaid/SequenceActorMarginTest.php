<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

final class SequenceActorMarginTest extends TestCase
{
    private const string SOURCE = "sequenceDiagram\nparticipant A as Long participant label\nparticipant B\nA->>B: hello";

    public function testFrontMatterAndInitializationPreserveZeroOverridesInGraphicsAndRows(): void
    {
        $prefix = "---\nconfig:\n  sequence:\n    actorMargin: 100\n---\n";
        $wide = NativeFixtureExport::render($prefix.self::SOURCE, Format::Svg);
        $zero = $prefix."%%{init: {'sequence': {'actorMargin': 0}}}%%\n".self::SOURCE;
        $compact = NativeFixtureExport::render($zero, Format::Svg);
        $this->assertNotSame($wide, $compact);
        $this->assertStringContainsString('Long participant label', $compact);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($zero, Format::Png));
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
}
