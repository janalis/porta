<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\RequirementDiagram;
use Porta\GraphViewer\Mermaid\RequirementLayout;
use Porta\GraphViewer\Mermaid\RequirementLexer;
use Porta\GraphViewer\Mermaid\RequirementParser;

final class RequirementTest extends TestCase
{
    private static function parse(string $source): RequirementDiagram
    {
        $parser = new RequirementParser();
        foreach (new RequirementLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RequirementLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testFieldsQuotesMetadataAndFirstDefinitionWins(): void
    {
        $source = "%% intro\nrequirementDiagram\n# comment\ntitle Safety\naccTitle: Accessible\naccDescr { two\n lines }\nrequirement \"Safe system\" {\nid: 1\ntext: \"A &amp; B<br/>next\nline %% literal\"\nrisk: HIGH\nverifyMethod: test\nid: ignored\n}\nrequirement \"Safe system\" { id: ignored\n}\nelement e { type: simulation\ndocRef: reqs/test\n}\n";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('Safety', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame("two\n lines", $diagram->metadata['accdescr']['label']);
        $this->assertSame(['id' => '1', 'text' => "A & B\nnext\nline %% literal", 'risk' => 'HIGH', 'verifymethod' => 'test'], $diagram->nodes['Safe system']['fields']);
        $this->assertSame(7, $diagram->nodes['Safe system']['origin']);
        $this->assertSame(\strpos($source, needle: 'requirement "'), $diagram->nodes['Safe system']['offset']);
        $this->assertSame(['type' => 'simulation', 'docref' => 'reqs/test'], $diagram->nodes['e']['fields']);
        $this->assertSame('', self::parse("requirementDiagram\ntitle")->metadata['title']['label']);
        $this->assertSame('one', self::parse("requirementDiagram\naccDescr: one")->metadata['accdescr']['label']);
    }

    public function testAllTypesAndDirectedRelationshipsWithForwardReferences(): void
    {
        $source = "requirementDiagram\na - contains -> b\na <- copies - b\n";
        foreach (['derives', 'satisfies', 'verifies', 'refines', 'traces'] as $kind) {
            $source .= 'a - '.$kind." -> b\n";
        }
        $source .= "requirement a {}\nelement b {}\n";
        foreach (RequirementDiagram::TYPES as $index => $type) {
            $source .= $type.' n'.$index." {}\n";
        }
        $diagram = self::parse($source);
        $this->assertCount(9, $diagram->nodes);
        $this->assertSame(['contains', 'copies', 'derives', 'satisfies', 'verifies', 'refines', 'traces'], \array_column($diagram->relations, 'kind'));
        $this->assertSame('b', $diagram->relations[1]['from']);
        $this->assertSame('a', $diagram->relations[1]['to']);
        $scene = self::scene($source);
        $this->assertCount(9, $scene->elements);
        $relations = \array_values($scene->graph->relationships);
        $this->assertCount(7, $relations);
        $this->assertCount(4 + (6 * 2), \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration), 'a circled plus for contains, an open arrowhead for the rest');
        $this->assertNull($relations[0]->arrowTip);
        $this->assertFalse($relations[0]->dashed);
        $this->assertNull($relations[1]->arrowTip, 'open arrowheads replace filled tips');
        $this->assertTrue($relations[1]->dashed);
        $this->assertSame('b', \array_column($scene->graph->nodes, 'name', 'id')[$relations[1]->tail]);
        $this->assertSame('a', \array_column($scene->graph->nodes, 'name', 'id')[$relations[1]->head]);
        $this->assertSame(1, $diagram->relations[0]['origin']);
    }

    public function testStylesResolveForwardWithInlinePrecedence(): void
    {
        $source = "requirementDiagram\nclass a strong\nclassDef default fill:white,stroke:black\nclassDef strong fill:red,color:blue,stroke-width:2px,stroke-dasharray:3 2\nstyle a fill:green,stroke:none\nrequirement a:::strong {}\nelement b {}\nb:::strong\nclassDef empty stroke-width:0,stroke-dasharray:0\nrequirement c:::empty {}";
        $scene = self::scene($source);
        [$a, $b, $c] = $scene->graph->nodes;
        $this->assertSame('#008000', $a->fillColor);
        $this->assertSame('none', $a->color);
        $this->assertSame('#0000ff', $a->textColor);
        $this->assertSame(0.2, $a->strokeWidth);
        $this->assertTrue($a->dashed);
        $this->assertSame('#ff0000', $b->fillColor);
        $this->assertSame('#000000', $b->color);
        $this->assertSame(0.0, $c->strokeWidth);
        $this->assertFalse($c->dashed);
    }

    #[DataProvider('directions')]
    public function testDirectionsWrappingAndSourceLinks(string $direction): void
    {
        $scene = self::scene("requirementDiagram\ndirection ".$direction."\ntitle Header\nrequirement a { text: \"".\str_repeat('日本', times: 80)."\"\n}\nelement b {}\na - satisfies -> b");
        [$a, $b] = $scene->graph->nodes;
        $this->assertStringContainsString("\n", $a->label);
        $this->assertSame(3, $scene->origin($a->id));
        $this->assertSame('Header', $scene->legend[0]['text']);
        $delta = \in_array($direction, ['LR', 'RL'], strict: true) ? $b->x - $a->x : $b->y - $a->y;
        $this->assertGreaterThan(0, \in_array($direction, ['RL', 'BT'], strict: true) ? -$delta : $delta);
        $this->assertSame([], self::scene('requirementDiagram')->elements);
        $gap = \in_array($direction, ['LR', 'RL'], strict: true) ? \abs($b->x - $a->x) - (($a->w + $b->w) / 2) : \abs($b->y - $a->y) - (($a->h + $b->h) / 2);
        $this->assertGreaterThanOrEqual(20.0, $gap, 'relationship captions need space between cards');
    }

    /** @return iterable<array{string}> */
    public static function directions(): iterable
    {
        foreach (['TB', 'BT', 'LR', 'RL'] as $direction) {
            yield [$direction];
        }
    }

    #[DataProvider('invalidSources')]
    public function testInvalidInputIsLocated(string $source, string $message): void
    {
        try {
            self::parse($source);
            $this->fail('Invalid requirement input accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertGreaterThanOrEqual(0, $error->sourceLine);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected requirementDiagram'];
        yield ['element e {}', 'Expected requirementDiagram'];
        yield ["requirementDiagram\nrequirement a {", 'Unclosed'];
        yield ["requirementDiagram\nrequirement a { element b {}", 'Expected a requirement field'];
        yield ["requirementDiagram\nrequirement a { risk: extreme\n}", 'Invalid requirement risk'];
        yield ["requirementDiagram\nrequirement a { verifyMethod: magic\n}", 'Invalid requirement verifymethod'];
        yield ["requirementDiagram\nelement a { id: 1\n}", 'Field is not valid'];
        yield ["requirementDiagram\na - copies -> b", 'Unknown requirement'];
        yield ["requirementDiagram\nstyle a filter:blur(2px)", 'Unsupported requirement CSS'];
        yield ["requirementDiagram\nstyle a fill:bogus", 'Invalid requirement style color'];
        yield ["requirementDiagram\nstyle a broken", 'Expected requirement style property'];
        yield ["requirementDiagram\nstyle a stroke-width:-1", 'stroke width'];
        yield ["requirementDiagram\nstyle a stroke-width:1001", 'stroke width'];
        yield ["requirementDiagram\nstyle a stroke-dasharray:-1", 'dash lengths'];
        yield ["requirementDiagram\nclass a", 'Expected requirement style'];
        yield ["requirementDiagram\n?", 'Invalid requirement declaration'];
        yield ['requirementDiagram'."\n".'requirement a { text: "unclosed', 'Invalid requirement declaration'];
    }

    public function testDirectParserRejectsUnknownStatements(): void
    {
        $parser = new RequirementParser();
        $parser->accept(new MermaidStatement('requirementDiagram', offset: 0, line: 0, column: 0));
        $this->expectExceptionMessageIsOrContains('Expected a requirement declaration or relationship');
        $parser->accept(new MermaidStatement('unknown', offset: 19, line: 1, column: 0));
    }

    #[DataProvider('limits')]
    public function testAuthoredLimits(string $body, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse("requirementDiagram\n".\str_repeat($body."\n", times: 4097));
    }

    /** @return iterable<array{string, string}> */
    public static function limits(): iterable
    {
        yield ['requirement a {}', 'declaration limit'];
        yield ['a - copies -> a', 'relationship limit'];
        yield ['style a fill:red', 'style limit'];
    }

    public function testInlineClassesShareTheBoundedCssIdentifierGrammar(): void
    {
        $source =
            'requirementDiagram'
            ."\nclassDef blue-edge, wide.border fill:blue,stroke-width:2px\n"
            .'requirement "Safe requirement":::blue-edge, wide.border {}'
            ."\nelement b {}\nclass b blue-edge, wide.border";
        $scene = self::scene($source);
        foreach ($scene->graph->nodes as $node) {
            $this->assertSame('#0000ff', $node->fillColor);
            $this->assertSame(0.2, $node->strokeWidth);
        }
        $this->assertCount(2, $scene->elements);
    }

    public function testPinnedUpstreamSample(): void
    {
        $source = \file_get_contents(__DIR__.'/../fixtures/mermaid/requirement/sample.mmd');
        $this->assertIsString($source);
        $diagram = self::parse($source);
        $this->assertCount(5, $diagram->nodes);
        $this->assertCount(4, $diagram->relations);
        $this->assertSame('test_entity2', $diagram->relations[3]['from']);
        $this->assertSame('test_req', $diagram->relations[3]['to']);
        $this->assertSame('medium', $diagram->nodes['test_req3']['fields']['risk']);
        $this->assertSame('inspection', $diagram->nodes['test_req2']['fields']['verifymethod']);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
    }

    public function testTerminalAndNativeExports(): void
    {
        $source = "requirementDiagram\nrequirement safe { text: \"<script>&amp;\"\n}\nelement code {}\ncode - satisfies -> safe";
        $rows = NativeFixtureRows::render($source, width: 80);
        $this->assertNotEmpty($rows->rows);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $svg);
        $this->assertStringContainsString('&lt;&lt;satisfies&gt;&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $png = NativeFixtureExport::render($source, GraphExportFormat::Png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }
}
