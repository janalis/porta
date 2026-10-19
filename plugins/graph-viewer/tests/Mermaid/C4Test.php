<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\C4Arguments;
use Porta\GraphViewer\Mermaid\C4Diagram;
use Porta\GraphViewer\Mermaid\C4Layout;
use Porta\GraphViewer\Mermaid\C4Lexer;
use Porta\GraphViewer\Mermaid\C4Parser;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class C4Test extends TestCase
{
    private static function parse(string $source): C4Diagram
    {
        $parser = new C4Parser();
        foreach (new C4Lexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $layout = new C4Layout()->layout(self::parse($source));
        \iterator_to_array($layout);

        return $layout->getReturn();
    }

    public function testVerticalCellRelationsFollowTheirActualEndpoints(): void
    {
        foreach (['Rel' => '▾', 'Rel_Back' => '▴'] as $macro => $glyph) {
            $source = "C4Context\nUpdateLayoutConfig(1,1)\nSystem(a,\"A\")\nSystem(b,\"B\")\n".$macro.'(a,b,"Calls")';
            $rows = NativeFixtureRows::render($source, width: 80);
            $this->assertStringContainsString($glyph, \implode("\n", $rows->rows));
        }
    }

    public function testPositionalNamedAndEmptyArgumentsRetainC4StringSemantics(): void
    {
        $statement = new MermaidStatement('Container(a,"A, B",,"Literal\\ntext",$tags="api,public",$link="https://example.com")', offset: 10, line: 2, column: 3);
        $args = C4Arguments::parse($statement);
        $this->assertSame(['a', 'A, B', '', 'Literal\\ntext', 'api,public', 'https://example.com'], \array_column($args, 'value'));
        $this->assertSame(
            ['alias' => 'a', 'label' => 'A, B', 'techn' => '', 'descr' => 'Literal\\ntext', 'tags' => 'api,public', 'link' => 'https://example.com'],
            C4Arguments::bind($args, ['alias', 'label', 'techn', 'descr']),
        );
        $this->assertSame([], C4Arguments::bind([['name' => null, 'value' => 'extra']], []));
    }

    public function testAliasesUpdatesAndNamedStylesFollowSourceOrder(): void
    {
        $diagram = self::parse(<<<'MMD'
            C4Context
            accDescr: Description
            accDescription More context
            UpdateElementStyle(future, $bgColor="red")
            UpdateRelStyle(person, system, $lineColor="red")
            Person(person, "User", "Original description", $tags="external")
            Enterprise_Boundary(group, "Company", $type="Custom") {
                System(system, "API", "Processes requests")
                System(future, "Future")
            }
            Person_Ext(person, "Visitor")
            UpdateElementStyle(person, $bgColor="red")
            Rel(person, system, "Calls", "HTTPS", "Initial")
            UpdateElementStyle(person, $fontColor="white", $bgColor="purple", $borderColor="black")
            UpdateElementStyle(group, $fontColor="blue", $bgColor="lightgray")
            UpdateRelStyle(person, system, $lineColor="blue", $textColor="red", $offsetX="10")
            Rel(person, system, "Uses")
            MMD);
        $this->assertSame(['person', 'system', 'future'], \array_keys($diagram->nodes));
        $this->assertSame('Person_Ext', $diagram->nodes['person']['macro']);
        $this->assertSame('Original description', $diagram->nodes['person']['attributes']['descr']);
        $this->assertSame('external', $diagram->nodes['person']['attributes']['tags']);
        $this->assertNull($diagram->nodes['person']['parent']);
        $this->assertSame('purple', $diagram->nodes['person']['attributes']['bgColor']);
        $this->assertSame('group', $diagram->nodes['system']['parent']);
        $this->assertArrayNotHasKey('bgColor', $diagram->nodes['future']['attributes']);
        $this->assertSame('Custom', $diagram->groups['group']['attributes']['type']);
        $this->assertCount(1, $diagram->edges);
        $edge = \array_first($diagram->edges);
        $this->assertSame(['Uses', 'HTTPS', 'Initial', 'blue', 'red', '10'], \array_map(static fn (string $key): string => $edge['attributes'][$key], [
            'label',
            'techn',
            'descr',
            'lineColor',
            'textColor',
            'offsetX',
        ]));
        $this->assertSame(10, $diagram->nodes['person']['origin']);
        $this->assertCount(2, $diagram->metadata);
    }

    public function testRowLimitsAndNestedBoundariesControlGridPlacement(): void
    {
        $source = <<<'MMD'
            C4Container
            UpdateLayoutConfig($c4ShapeInRow="2", $c4BoundaryInRow="1")
            System(a,"A")
            System(b,"B")
            System(c,"C")
            Container_Boundary(first,"First") {
                Container(api,"API","PHP")
                System_Boundary(nested,"Nested") {
                    Component(worker,"Worker","Messenger")
                }
            }
            Boundary(second,"Second") {
                System(db,"DB")
            }
            Rel(api,worker,"Dispatches")
            MMD;
        $diagram = self::parse($source);
        $this->assertSame([2, 1], [$diagram->columns, $diagram->groupColumns]);
        $scene = self::scene($source);
        $byName = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame($byName['a']->y, $byName['b']->y);
        $this->assertGreaterThan($byName['a']->y, $byName['c']->y);
        $frames = \array_column($scene->graph->clusters, null, 'name');
        $this->assertGreaterThan($frames['first']->y1, $frames['second']->y0);
        $this->assertGreaterThan($frames['first']->x0, $frames['nested']->x0);
        $this->assertLessThan($frames['first']->x1, $frames['nested']->x1);
        $this->assertSame(GraphFont::Bold, $frames['first']->font);
        $this->assertTrue($frames['first']->dashed);
        $this->assertSame(5, $scene->elements[$byName['first']->id]);
        $this->assertSame('text', $scene->shapes[$byName['first']->id]);
        foreach ($scene->graph->nodes as $node) {
            $this->assertGreaterThanOrEqual(0, $node->x - ($node->w / 2));
            $this->assertGreaterThanOrEqual(0, $node->y - ($node->h / 2));
            $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
            $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    public function testPersonQueueDatabaseAndStyleOverridesHaveDistinctOutlines(): void
    {
        $source = <<<'MMD'
            C4Component
            Person(person,"User")
            ContainerQueue(queue,"Queue","AMQP")
            ComponentDb(db,"Database","SQL")
            System(custom,"Custom")
            UpdateElementStyle(person,$bgColor="purple",$fontColor="white",$borderColor="red")
            UpdateElementStyle(custom,$shape="circle")
            MMD;
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(['c4-person', 'c4-queue', 'cylinder', 'circle'], \array_values($scene->shapes));
        $this->assertSame(['#800080', '#ffffff', '#ff0000'], [$nodes['person']->fillColor, $nodes['person']->textColor, $nodes['person']->color]);
        $this->assertTrue(MermaidOutline::contains($nodes['person'], 'c4-person', $nodes['person']->x, $nodes['person']->y - ($nodes['person']->h / 2) + 2));
        $this->assertCount(2, MermaidOutline::marks($nodes['person'], 'c4-person'));
        $this->assertCount(3, MermaidOutline::marks($nodes['queue'], 'c4-queue'));
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('#800080', $svg);
        $this->assertStringContainsString('Database', $svg);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 20)->rows);
    }

    public function testCustomPersonAndQueueAliasesUseTheirNativeSymbols(): void
    {
        foreach (['person' => 'c4-person', 'queue' => 'c4-queue'] as $custom => $expected) {
            $source = "C4Context\nSystem(a,\"A\")\nUpdateElementStyle(a,\$shape=\"".$custom.'")';
            $scene = self::scene($source);
            $this->assertSame([$expected], \array_values($scene->shapes));
        }
    }

    public function testDynamicNumbersReverseAndBidirectionalRelationsKeepSemanticCounts(): void
    {
        $source = <<<'MMD'
            C4Dynamic
            Container(a,"A","PHP")
            Container(b,"B","SQL")
            Container(c,"C","HTTP")
            RelIndex(9,a,b,"Calls","JDBC","Reads data")
            Rel_Back(b,c,"Replies")
            BiRel(c,a,"Syncs")
            UpdateRelStyle(a,b,$textColor="red",$lineColor="blue",$offsetX="20.5px",$offsetY="-10")
            MMD;
        $scene = self::scene($source);
        $this->assertSame(3, $scene->graph->edgeCount());
        $this->assertCount(4, $scene->graph->edges);
        $this->assertSame(["1: Calls\n[JDBC]\nReads data", '2: Replies', '3: Syncs'], \array_column($scene->graph->relationships, 'label'));
        $first = $scene->graph->relationships[0];
        $this->assertSame(['#0000ff', '#ff0000'], [$first->color, $first->textColor]);
        $this->assertSame([(($first->points[0][0] + $first->points[1][0]) / 2) + 2, (($first->points[0][1] + $first->points[1][1]) / 2) - 1], $first->labelPos);
        $back = $scene->graph->relationships[1];
        $from = $scene->graph->nodes[$back->tail];
        $to = $scene->graph->nodes[$back->head];
        $tip = $back->arrowTip;
        $this->assertNotNull($tip);
        $this->assertLessThan(\hypot($tip[0] - $to->x, $tip[1] - $to->y), \hypot($tip[0] - $from->x, $tip[1] - $from->y));
    }

    public function testDeploymentFramesHavePerspectiveWithoutAddingRelationships(): void
    {
        $source = "C4Deployment\nNode_L(left,\"Left\",\"Linux\") {\nContainer(a,\"A\",\"PHP\")\n}\nNode_R(right,\"Right\",\"Linux\") {\nContainer(b,\"B\",\"SQL\")\n}\nRel(a,b,\"Uses\")";
        $scene = self::scene($source);
        $this->assertSame(1, $scene->graph->edgeCount());
        $this->assertCount(5, $scene->graph->edges);
        $this->assertFalse($scene->graph->clusters[0]->dashed);
        $this->assertFalse($scene->graph->clusters[1]->dashed);
        [$left, $right] = $scene->graph->clusters;
        $this->assertLessThan($left->x0, $scene->graph->edges[1]->minX);
        $this->assertGreaterThan($right->x1, $scene->graph->edges[3]->maxX);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    public function testMultilineArgumentErrorsPointToTheArgumentAfterIndentation(): void
    {
        $source = "C4Context\nSystem(a,\"A\",\n  \$tags=bad)";
        try {
            self::parse($source);
            $this->fail('Invalid argument accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([\strpos($source, needle: '$tags'), 2, 2], [$error->offset, $error->sourceLine, $error->column]);
        }
    }

    public function testSelfLoopsRespectCardAndFullBoundaryBounds(): void
    {
        $source = <<<'MMD'
            C4Context
            Boundary(frame,"Frame") {
                System(a,"A")
            }
            Rel(a,a,"Retry this operation")
            Rel(frame,frame,"Retry this boundary")
            MMD;
        $scene = self::scene($source);
        $frame = $scene->graph->clusters[0];
        [$cardEdge, $frameEdge] = $scene->graph->relationships;
        $this->assertLessThan($frame->x1, $cardEdge->maxX);
        $this->assertLessThan($frame->y1, $cardEdge->maxY);
        $this->assertGreaterThan($frame->x1, $frameEdge->maxX);
        $this->assertGreaterThan($frame->y1, $frameEdge->maxY);
        $this->assertLessThanOrEqual($scene->graph->width, $frameEdge->maxX);
        $this->assertLessThanOrEqual($scene->graph->height, $frameEdge->maxY);
        $collision = self::scene("C4Context\nBoundary(same,\"Boundary\") {\nSystem(same,\"Element\")\n}\nRel(same,same,\"Retry\")");
        $edge = $collision->graph->relationships[0];
        $node = $collision->graph->nodes[$edge->tail];
        $this->assertStringContainsString('Element', $node->label);
        $this->assertSame($node->x + ($node->w / 2) + 8, $edge->maxX);
    }

    public function testCaptionOffsetsExtendCanvasAndApplyAfterSelfRouting(): void
    {
        $plain = "C4Context\nSystem(a,\"A\")\nRel(a,a,\"Self\")";
        $before = self::scene($plain);
        $after = self::scene($plain."\nUpdateRelStyle(a,a,\$offsetX=\"-300\",\$offsetY=\"-200\")");
        $old = $before->graph->relationships[0];
        $new = $after->graph->relationships[0];
        $a = $before->graph->nodes[$old->tail];
        $b = $after->graph->nodes[$new->tail];
        $this->assertEqualsWithDelta($old->labelPos[0] - $a->x - 30, $new->labelPos[0] - $b->x, delta: 0.0001);
        $this->assertEqualsWithDelta($old->labelPos[1] - $a->y - 20, $new->labelPos[1] - $b->y, delta: 0.0001);
        $this->assertGreaterThanOrEqual(0, $new->labelPos[0]);
        $this->assertGreaterThanOrEqual(0, $new->labelPos[1]);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($plain."\nUpdateRelStyle(a,a,\$offsetX=\"-300\")", GraphExportFormat::Png));
    }

    public function testAccessibilityTitleOverridesFrontMatterAndBodyMetadataKeepsLocations(): void
    {
        $source = "---\ntitle: Outer\n---\nC4Context\naccTitle: Accessible\nSystem(a,\"A\")";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('Accessible', $svg);
        $this->assertStringNotContainsString('Outer', $svg);
        $rows = NativeFixtureRows::render($source, width: 40);
        $this->assertContains(5, $rows->origins);
        $this->assertStringContainsString('Only title', NativeFixtureExport::render("C4Context\ntitle Only title", GraphExportFormat::Svg));
    }

    public function testNewlineBraceVariantAndIgnoredStylesRemainValid(): void
    {
        $source = "C4Context\nBoundary(b,\"B\")\n{System(a,\"A\")}\nUpdateElementStyle(missing,\$bgColor=\"red\")\nUpdateRelStyle(a,missing,\$lineColor=\"red\")\nRel(a)\nUpdateLayoutConfig(-10000000,not_a_number)";
        $diagram = self::parse($source);
        $this->assertSame([4, 2], [$diagram->columns, $diagram->groupColumns]);
        $this->assertCount(0, $diagram->edges);
        $this->assertCount(1, self::scene($source)->graph->clusters);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'header' => ['C4Unknown'];
        yield 'empty' => ['C4Context'];
        yield 'header newline' => ['C4Context System(a,"A")'];
        yield 'call newline' => ["C4Context\nSystem(a,\"A\") System(b,\"B\")"];
        yield 'inline boundary content' => ["C4Context\nBoundary(b,\"B\"){System(a,\"A\")}"];
        yield 'unknown macro' => ["C4Context\nUnknown(a)"];
        yield 'empty call' => ["C4Context\nSystem()"];
        yield 'unknown endpoint' => ["C4Context\nRel(a,b,\"Unknown\")"];
        yield 'unclosed call' => ["C4Context\nSystem(a,\"A\""];
        yield 'unclosed boundary' => ["C4Context\nBoundary(b,\"B\") {\nSystem(a,\"A\")"];
        yield 'missing brace' => ["C4Context\nBoundary(b,\"B\")\nSystem(a,\"A\")"];
        yield 'closing brace' => ["C4Context\n}"];
        yield 'empty boundary' => ["C4Context\nBoundary(b,\"B\") {\n}"];
        yield 'metadata first inside boundary' => ["C4Context\nBoundary(b,\"B\") {\ntitle Inside\nSystem(a,\"A\")\n}"];
        yield 'cycle' => ["C4Context\nBoundary(b,\"B\") {\nBoundary(b,\"Again\") {\nSystem(a,\"A\")\n}\n}"];
        yield 'row limit' => ["C4Context\nUpdateLayoutConfig(65,2)"];
        yield 'bare named value' => ["C4Context\nSystem(a,\"A\",\$tags=plain)"];
        yield 'empty named value' => ["C4Context\nSystem(a,\"A\",\$tags=\"\")"];
        yield 'broken string' => ["C4Context\nSystem(a,\"A\"junk)"];
        yield 'stray brace' => ["C4Context\n{"];
    }

    #[DataProvider('invalid')]
    public function testInvalidSyntaxHasLocatedErrors(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testDepthArgumentAndDeclarationLimitsAreEnforced(): void
    {
        $nested = "C4Context\n";
        for ($i = 0; $i < 129; ++$i) {
            $nested .= 'Boundary(b'.$i.',"B") {'."\n";
        }
        foreach ([[$nested, 'nesting limit'], ["C4Context\nSystem(".\str_repeat('a,', times: 4096).'"A")', 'argument limit']] as [$source, $message]) {
            try {
                self::parse($source);
                $this->fail('Resource limit accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertGreaterThan(0, $error->sourceLine);
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $parser = new C4Parser();
        $parser->accept(new MermaidStatement('C4Context', offset: 0, line: 0, column: 0));
        for ($i = 0; $i < 4096; ++$i) {
            $parser->accept(new MermaidStatement('System(a,"A")', offset: 10, line: $i + 1, column: 0));
        }
        $this->assertCount(1, $parser->finish()->nodes);
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('System(b,"B")', offset: 10, line: 4097, column: 0));
    }

    public function testRenderOnlyValidationAndMissingHeadersAreLocated(): void
    {
        foreach ([
            "C4Context\nSystem(a,\"A\")\nUpdateElementStyle(a,\$shape=\"unknown\")",
            "C4Context\nSystem(a,\"A\")\nUpdateElementStyle(a,\$bgColor=\"not-a-color\")",
            "C4Context\nSystem(a,\"A\")\nRel(a,a,\"Self\")\nUpdateRelStyle(a,a,\$offsetX=\"10001\")",
        ] as $source) {
            try {
                self::scene($source);
                $this->fail('Invalid render property accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertGreaterThan(0, $error->sourceLine);
            }
        }
        try {
            new C4Parser()->accept(new MermaidStatement('wrong', offset: 5, line: 2, column: 3));
            $this->fail('Invalid direct header accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([5, 2, 3], [$error->offset, $error->sourceLine, $error->column]);
        }
        $this->expectException(MermaidSyntaxError::class);
        new C4Parser()->finish();
    }

    /** @return iterable<string, array{string}> */
    public static function pinned(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/c4/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [(string) \file_get_contents($file)];
        }
    }

    #[DataProvider('pinned')]
    public function testPinnedC4ExamplesRenderToCellsAndSvg(string $source): void
    {
        $this->assertNotEmpty(self::parse($source)->nodes);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    public function testConfiguredFontsScaleAndWeightTheCardsAsPinned(): void
    {
        $scene = NativeFixtureScene::render(
            "---\nconfig:\n  c4:\n    personFontSize: 21\n    systemFontWeight: 700\n    container_dbFontWeight: bolder\n    messageFontSize: '18px'\n    boundaryFontSize: 28\n---\nC4Context\nEnterprise_Boundary(b0, \"Bank\") {\nPerson(a, \"Customer\", \"A bank customer\")\nSystem(b, \"Banking\", \"Core\", \$shape=\"hexagon\")\nContainerDb(d, \"Store\", \"SQL\")\nSystem_Ext(e, \"Mail\")\n}\nRel(a, b, \"uses\")",
        );
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame([1.5, GraphFont::Regular], [$nodes['a']->fontScale, $nodes['a']->font], 'a size scales from the pinned 14 pixels');
        $this->assertSame([1.0, GraphFont::Bold], [$nodes['b']->fontScale, $nodes['b']->font]);
        $this->assertSame([GraphFont::Bold, GraphFont::Regular], [$nodes['d']->font, $nodes['e']->font], 'each type reads its own weight');
        $this->assertSame('hexagon', $scene->shapes[$nodes['b']->id], 'any registered flowchart shape');
        $this->assertSame([['', GraphFont::Regular]], $scene->runs['node:'.$nodes['a']->id][0], 'a person keeps room for its head');
        $this->assertSame(
            [[['Customer', GraphFont::Bold]], [['[Person]', GraphFont::Regular]]],
            \array_slice($scene->runs['node:'.$nodes['a']->id], offset: 2, length: 2),
            'the name is bold, as pinned',
        );
        $this->assertSame([[['Banking', GraphFont::Bold]], [['[Software System]', GraphFont::Bold]]], \array_slice($scene->runs['node:'.$nodes['b']->id], offset: 0, length: 2));
        $edge = \array_find($scene->graph->edges, static fn ($edge): bool => null !== $edge->label);
        $this->assertSame(1.5, $edge->fontScale, 'messages scale from the pinned 12 pixels, `px` or not');
        $this->assertSame([2.0, 2.0], [$scene->graph->clusters[0]->fontScale, $nodes['b0']->fontScale]);
        $plain = \array_column(NativeFixtureScene::render("C4Context\nPerson(a, \"A\")\nRel(a, a, \"x\")")->graph->nodes, null, 'name');
        $this->assertSame([1.0, GraphFont::Regular], [$plain['a']->fontScale, $plain['a']->font]);
        $zero = \array_column(NativeFixtureScene::render("---\nconfig:\n  c4:\n    personFontSize: 0\n---\nC4Context\nPerson(a, \"A\")")->graph->nodes, null, 'name');
        $this->assertSame(1.0, $zero['a']->fontScale, 'a zero size keeps the default rather than vanishing');
        // Pinned sprites reserve 48 pixels above the text, and draw nothing in them.
        $plainSystem = \array_column(NativeFixtureScene::render("C4Context\nSystem(s, \"S\")")->graph->nodes, null, 'name')['s'];
        $sprite = NativeFixtureScene::render("C4Context\nSystem(s, \"S\", \$sprite=\"users\")");
        $node = \array_column($sprite->graph->nodes, null, 'name')['s'];
        $this->assertSame([$plainSystem->h + 4, "\n\nS\n[Software System]"], [$node->h, $node->drawLabel]);
        $this->assertSame([['', GraphFont::Regular]], $sprite->runs['node:'.$node->id][1]);
        $person = \array_column(NativeFixtureScene::render("C4Context\nPerson(p, \"P\", \$sprite=\"users\")")->graph->nodes, null, 'name')['p'];
        $this->assertSame("\n\n\n\nP\n[Person]", $person->drawLabel, 'a person keeps its head room too');
    }

    public function testUnknownCustomShapesAreLocated(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unknown C4 shape blob');
        self::scene("C4Context\nSystem(b, \"B\", \$shape=\"blob\")");
    }
}
