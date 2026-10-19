<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\ClassDiagram;
use Porta\GraphViewer\Mermaid\ClassLayout;
use Porta\GraphViewer\Mermaid\ClassLexer;
use Porta\GraphViewer\Mermaid\ClassMarkers;
use Porta\GraphViewer\Mermaid\ClassParser;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class ClassTest extends TestCase
{
    private static function parse(string $source): ClassDiagram
    {
        $parser = new ClassParser();
        foreach (new ClassLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testNamespacesMembersAnnotationsAndForwardStyles(): void
    {
        $diagram = self::parse(<<<'MMD'
            %% comment
            classDiagram-v2
            direction LR
            accTitle: Types
            accDescr {A hierarchy}
            namespace Domain["Domain ❤"] {
                namespace Inner {
                    class Animal["Living thing"]:::base <<abstract>> {
                        %% member comment
                        +String name
                        +int age
                        +run() void
                        <<interface>>
                    }
                    class Duck
                }
            }
            Animal <|-- Duck : inherits
            Duck : +swim()
            <<service>> Duck
            classDef base fill:#ff0000
            style Duck stroke:#0000ff
            cssClass "Duck" base
            note for Duck "Can swim"
            note "Types"
            click Duck href "https://example.com" "Details" _blank
            MMD);
        $this->assertSame('LR', $diagram->graph->direction);
        $this->assertSame('Living thing', $diagram->graph->nodes['Animal']['label']);
        $this->assertSame(['abstract', 'interface'], $diagram->annotations['Animal']);
        $this->assertSame(['namespace:Domain', 'namespace:Domain.Inner'], $diagram->graph->nodes['Duck']['groups']);
        $this->assertSame(['+String name', '+int age', '+run() void'], \array_column($diagram->members['Animal'], 'text'));
        $this->assertSame([9, 10, 11], \array_column($diagram->members['Animal'], 'origin'));
        $this->assertSame([['left' => '<|', 'right' => '']], $diagram->markers);
        $this->assertCount(5, $diagram->metadata);
        $work = new ClassLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(4, $scene->elements, 'the two classes and their two notes');
        $this->assertSame(['Can swim', 'Types'], \array_values(\array_map(static fn (GraphNode $node): string => $node->label, \array_filter(
            $scene->graph->nodes,
            static fn (GraphNode $node): bool => \str_starts_with($node->name, 'class-note-'),
        ))));
        $this->assertCount(2, $scene->graph->clusters);
        $this->assertCount(3, $scene->graph->edges, 'the relationship, its marker and the note link');
        $this->assertSame('#ff0000', $scene->graph->nodes[0]->fillColor);
        $this->assertSame('#0000ff', $scene->graph->nodes[1]->color);
        $this->assertStringStartsWith('er-card:', $scene->shapes[0]);
        $this->assertStringContainsString('+run() : void', $scene->graph->nodes[0]->label);
        $this->assertContains(9, \array_column($scene->legend, 'origin'));
    }

    public function testGenericsLiteralNamesCardinalitiesAndAllEndpointKinds(): void
    {
        $source = <<<'MMD'
            classDiagram
            class List~Item~ {}
            class `User service`
            List~Item~ "1" *-- "many" `User service` : owns
            A o..> B
            A ()--() C
            A <..|> D
            A -- A
            X
            link X "https://example.com"
            callback X "inspect"
            MMD;
        $diagram = self::parse($source);
        $this->assertArrayHasKey('List', $diagram->graph->nodes);
        $this->assertArrayHasKey('User service', $diagram->graph->nodes);
        $this->assertSame('1 owns many', $diagram->graph->edges[0]['label']);
        $this->assertSame('-.-', $diagram->graph->edges[1]['arrow']);
        $work = new ClassLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(12, $scene->graph->edges);
        $this->assertCount(7, $scene->elements);
        $this->assertSame('er-card:1', $scene->shapes[0], 'a class without members keeps the pinned empty box');
        $this->assertNotNull($scene->graph->edges[5]->ribbon);
        $this->assertTrue($scene->graph->edges[1]->dashed);
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('owns', \implode("\n", $rows->rows));
        $this->assertStringContainsString('User service', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['flowchart TB', 'Expected classDiagram'];
        yield 'header suffix' => ['classDiagram-bad', 'Expected classDiagram'];
        yield 'unclosed class' => ["classDiagram\nclass A {\n+name", 'Invalid class'];
        yield 'extra close' => ["classDiagram\n}", 'Unexpected namespace'];
        yield 'unclosed namespace' => ["classDiagram\nnamespace N {\nclass A", 'Unclosed class namespace'];
        yield 'unknown punctuation' => ["classDiagram\n@bad", 'Invalid class'];
        yield 'namespace depth' => ["classDiagram\n".\implode("\n", \array_map(static fn (int $i): string => 'namespace N'.$i.' {', \range(0, end: 128))), 'namespace limit'];
        yield 'namespace count' => ["classDiagram\n".\implode("\n", \array_map(static fn (int $i): string => 'namespace N'.$i.' {}', \range(0, end: 4096))), 'namespace limit'];
        yield 'members' => ["classDiagram\nclass A {\n".\str_repeat("x\n", times: 4097).'}', 'member limit'];
    }

    #[DataProvider('invalid')]
    public function testInvalidSyntaxIsLocated(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    public function testParserGuardsAndBlankMembers(): void
    {
        foreach (['wrong', 'classDiagram;'] as $header) {
            try {
                new ClassParser()->accept(new MermaidStatement($header, offset: 0, line: 0, column: 0));
                $this->fail('Wrong header accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertStringContainsString('Expected classDiagram', $error->getMessage());
            }
        }
        foreach (['class ?', '???'] as $text) {
            $parser = new ClassParser();
            $parser->accept(new MermaidStatement('classDiagram', offset: 0, line: 0, column: 0));
            try {
                $parser->accept(new MermaidStatement($text, offset: 12, line: 1, column: 0));
                $this->fail('Invalid declaration accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame(1, $error->sourceLine);
            }
        }
        $diagram = self::parse("classDiagram; class\tA {\n\n+name\n}\nclass\tA[\"Updated\"]");
        $this->assertSame('Updated', $diagram->graph->nodes['A']['label']);
        $this->assertCount(1, $diagram->members['A']);
        $this->expectException(MermaidSyntaxError::class);
        new ClassParser()->finish();
    }

    public function testMarkerBoundsAndDegenerateRoutes(): void
    {
        $zero = new GraphEdge(0, 1, [[0.0, 0.0], [0.0, 0.0]], null, 0.0, 0.0, 0.0, 0.0, null, null, null, dashed: false);
        $this->assertSame([], ClassMarkers::forEdge($zero, '<|', '|>'));
        $this->assertSame($zero->points, ClassMarkers::shaft($zero, 'o', '*')->points);
        foreach (['<|', '|>', '*', 'o', '<', '>', '()'] as $kind) {
            $edge = new GraphEdge(0, 1, [[2.0, 3.0], [12.0, 8.0]], null, 2.0, 3.0, 12.0, 8.0, '#123456', null, null, dashed: true);
            $markers = ClassMarkers::forEdge($edge, $kind, $kind);
            $this->assertCount(2, $markers);
            foreach ($markers as $marker) {
                $this->assertSame('#123456', $marker->color);
                $this->assertFalse($marker->dashed);
                $this->assertEquals(\min(\array_column($marker->points, 0)), $marker->minX);
                $this->assertSame('*' === $kind, null !== $marker->ribbon);
            }
            $shaft = ClassMarkers::shaft($edge, $kind, $kind);
            $this->assertSame($edge->label, $shaft->label);
            $this->assertSame($edge->dashed, $shaft->dashed);
            $this->assertSame(\in_array($kind, ['<', '>'], strict: true), $edge->points === $shaft->points);
        }
    }

    public function testDottedRedeclaredAndNestedNamespacesShareAncestorsAndGenericIdentity(): void
    {
        $diagram = self::parse(<<<'MMD'
            classDiagram
            namespace Company.Engineering.Backend {
                class List~Item~
            }
            namespace Company.Engineering {
                namespace Frontend {
                    class View
                }
            }
            namespace Company.Engineering.Backend["Server"] {
                class List["Items"]
            }
            List --> View
            MMD);
        $this->assertCount(4, $diagram->graph->groups);
        $this->assertCount(2, $diagram->graph->nodes);
        $this->assertSame('Item', $diagram->types['List']);
        $this->assertSame('Server', $diagram->graph->groups['namespace:Company.Engineering.Backend']['label']);
        $this->assertSame(['namespace:Company', 'namespace:Company.Engineering', 'namespace:Company.Engineering.Backend'], $diagram->graph->nodes['List']['groups']);
        $this->assertSame('namespace:Company.Engineering', $diagram->graph->groups['namespace:Company.Engineering.Frontend']['parent']);
        $this->assertStringContainsString('Items&lt;Item&gt;', NativeFixtureExport::render(<<<'MMD'
            classDiagram
            class List~Item~["Items"]
            List --> Other
            MMD, GraphExportFormat::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function upstream(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/class/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [(string) \file_get_contents($file)];
        }
    }

    #[DataProvider('upstream')]
    public function testPinnedClassCorpusRendersWithoutCli(string $source): void
    {
        $diagram = self::parse($source);
        $this->assertNotEmpty($diagram->graph->nodes);
        $rows = NativeFixtureRows::render($source, width: 40);
        $this->assertNotEmpty($rows->rows);
        $this->assertCount(\count($rows->rows), $rows->origins);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }
}
