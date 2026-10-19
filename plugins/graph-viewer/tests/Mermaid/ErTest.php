<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\ErDiagram;
use Porta\GraphViewer\Mermaid\ErLayout;
use Porta\GraphViewer\Mermaid\ErLexer;
use Porta\GraphViewer\Mermaid\ErParser;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class ErTest extends TestCase
{
    private static function parse(string $source): ErDiagram
    {
        $parser = new ErParser();
        foreach (new ErLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new ErLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testSubgraphsFrameTheirEntitiesAsPinned(): void
    {
        $source = "erDiagram\nsubgraph S[Sales]\n  direction LR\n  CUSTOMER ||--o{ ORDER : places\n  subgraph Inner\n    LINE\n  end\nend\nORDER ||--|{ ITEM : contains\nstyle S fill:#fff5e6\ndirection RL";
        $diagram = self::parse($source);
        $this->assertSame(['S', 'S', ['S', 'Inner'], []], [
            $diagram->graph->nodes['CUSTOMER']['groups'][0],
            $diagram->graph->nodes['ORDER']['groups'][0],
            $diagram->graph->nodes['LINE']['groups'],
            $diagram->graph->nodes['ITEM']['groups'],
        ]);
        $this->assertSame(['Sales', 'LR', 'RL'], [$diagram->graph->groups['S']['label'], $diagram->graph->groups['S']['direction'], $diagram->graph->direction]);
        $scene = self::scene($source);
        $clusters = \array_column($scene->graph->clusters, null, 'name');
        $this->assertSame(['Sales', '#fff5e6'], [$clusters['S']->label, $clusters['S']->fillColor]);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        foreach (['CUSTOMER', 'ORDER', 'LINE'] as $name) {
            $this->assertGreaterThan($clusters['S']->x0, $nodes[$name]->x - ($nodes[$name]->w / 2), $name.' sits inside the frame');
            $this->assertLessThan($clusters['S']->y1, $nodes[$name]->y + ($nodes[$name]->h / 2), $name.' sits inside the frame');
        }
        $this->assertFalse(
            ($nodes['ITEM']->y - ($nodes['ITEM']->h / 2)) < $clusters['S']->y1 && $nodes['ITEM']->x > $clusters['S']->x0 && $nodes['ITEM']->x < $clusters['S']->x1,
            'ITEM stays outside',
        );
    }

    public function testEntitiesAliasesAttributesAndOriginalSourcePositions(): void
    {
        $source = "%% intro\nERDIAGRAM\nCustomer ||--o{ Order : places\nCustomer[\"Client &amp; account\"] {\n int id PK, UK \"primary } key\"\n %% comment\n string name\n decimal(10,2)? total FK\n `custom type`? `custom name` \"multi\nline\"\n List~string~ values\n}\nCustomer[Ignored] { string email }\nOrder {}\n42[\"\"]\n42[Answer]\n42[Ignored]";
        $diagram = self::parse($source);
        $this->assertSame(['Customer', 'Order', 42], \array_keys($diagram->graph->nodes));
        $this->assertSame('Client & account', $diagram->aliases['Customer']);
        $this->assertSame('Answer', $diagram->aliases[42]);
        $this->assertSame(
            ['int id [PK,UK] — primary } key', 'string name', 'decimal(10,2)? total [FK]', "custom type? custom name — multi\nline", 'List~string~ values', 'string email'],
            \array_column($diagram->attributes['Customer'], 'text'),
        );
        $this->assertSame([4, 6, 7, 8, 10, 12], \array_column($diagram->attributes['Customer'], 'origin'));
        $this->assertSame(2, $diagram->graph->nodes['Customer']['origin']);
        $this->assertSame(\strpos($source, needle: 'Customer ||'), $diagram->graph->nodes['Customer']['offset']);
        $this->assertSame([], $diagram->attributes['Order']);
        $scene = self::scene($source);
        $this->assertSame(2, $scene->origin(0));
        $this->assertCount(3, $scene->elements);
        $this->assertMatchesRegularExpression('/^string {11}email +$/m', $scene->graph->nodes[0]->label);
        $this->assertStringContainsString('multi line', $scene->graph->nodes[0]->label, 'a cell runs its line breaks together');
        $this->assertContains(['text' => 'string email', 'origin' => 12], $scene->legend);
    }

    #[DataProvider('cardinalities')]
    public function testCardinalitySpellings(string $card, string $expected): void
    {
        $diagram = self::parse('erDiagram'."\nA ".$card.'--'.$card.' B : owns');
        $edge = $diagram->graph->edges[0];
        $this->assertSame('A', $edge['from']);
        $this->assertSame('B', $edge['to']);
        $this->assertSame($expected.' : '.$expected.' · owns', $edge['label']);
        $this->assertSame('--', $edge['arrow']);
    }

    /** @return iterable<array{string, string}> */
    public static function cardinalities(): iterable
    {
        foreach (['one', 'only one', '1', '||'] as $card) {
            yield [$card, '1'];
        }
        foreach (['one or zero', 'zero or one', '|o', 'o|'] as $card) {
            yield [$card, '0..1'];
        }
        foreach (['one or more', 'one or many', '1+', 'many(1)', '}|', '|{'] as $card) {
            yield [$card, '1..*'];
        }
        foreach (['zero or more', 'zero or many', 'many', '0+', 'many(0)', '}o', 'o{'] as $card) {
            yield [$card, '0..*'];
        }
        yield ['u', 'MD parent'];
    }

    #[DataProvider('relationships')]
    public function testIdentifyingAndNonIdentifyingRelationships(string $relation, bool $dashed): void
    {
        $scene = self::scene('erDiagram'."\nA || ".$relation.' o{ B : "owns &amp; uses"');
        $edge = $scene->graph->edges[0];
        $this->assertSame($dashed, $edge->dashed);
        $this->assertNull($edge->arrowTip);
        $this->assertSame('1 : 0..* · owns & uses', $edge->label);
    }

    /** @return iterable<array{string, bool}> */
    public static function relationships(): iterable
    {
        yield ['--', false];
        yield ['to', false];
        yield ['..', true];
        yield ['.-', true];
        yield ['-.', true];
        yield ['optionally to', true];
    }

    #[DataProvider('directions')]
    public function testDirectionsMetadataAndCycles(string $direction): void
    {
        $source = "erDiagram\ndirection ".$direction."\ntitle Data %% hidden\naccTitle: Accessible\naccDescr { Multi\n line }\nA ||--|| B : links\nB }o..o{ A : reverse\nA ||--o{ A : self";
        $scene = self::scene($source);
        [$a, $b] = $scene->graph->nodes;
        $this->assertSame('Data', $scene->legend[0]['text']);
        $this->assertSame('Accessible', $scene->legend[1]['text']);
        $this->assertSame("Multi\n line", $scene->legend[2]['text']);
        $this->assertCount(3, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->label));
        $delta = \in_array($direction, ['LR', 'RL'], strict: true) ? $b->x - $a->x : $b->y - $a->y;
        $this->assertGreaterThan(0, \in_array($direction, ['RL', 'BT'], strict: true) ? -$delta : $delta);
        $this->assertSame('single', self::parse("erDiagram\naccDescr: single")->metadata[0]['text']);
        $this->assertSame('', self::parse("erDiagram\ntitle")->metadata[0]['text']);
        $this->assertSame([], self::scene('erDiagram')->elements);
    }

    /** @return iterable<array{string}> */
    public static function directions(): iterable
    {
        foreach (['TB', 'BT', 'LR', 'RL'] as $direction) {
            yield [$direction];
        }
    }

    public function testQuotedEntityNamesAndMultilineAttributesAreLiteral(): void
    {
        $diagram = self::parse('erDiagram'."\n".'"Customer account" ||--o{ "Purchase order" : "places: { %% literal"'."\n".'"Customer account" { string name "A &amp; B %% literal" int age }');
        $this->assertCount(2, $diagram->graph->nodes);
        $this->assertSame('Customer account', $diagram->graph->edges[0]['from']);
        $this->assertSame('1 : 0..* · places: { %% literal', $diagram->graph->edges[0]['label']);
        $this->assertSame('string name — A & B %% literal', $diagram->attributes['Customer account'][0]['text']);
        $this->assertSame('int age', $diagram->attributes['Customer account'][1]['text']);
    }

    #[DataProvider('invalidSources')]
    public function testErrorsRemainLocated(string $source, string $message): void
    {
        try {
            self::parse($source);
            $this->fail('Invalid ER source accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertGreaterThanOrEqual(0, $error->sourceLine);
            $this->assertGreaterThanOrEqual(0, $error->column);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected erDiagram'];
        yield ['Other', 'Expected erDiagram'];
        yield ["erDiagram\nA { int id", 'unclosed attribute block'];
        yield ["erDiagram\nA { invalid }", 'Expected ER attribute'];
        yield ["erDiagram\nA { int id ZZ }", 'Expected ER attribute'];
        yield ["erDiagram\nA { PK id }", 'keys must follow'];
        yield ["erDiagram\nA { int FK }", 'keys must follow'];
        yield ["erDiagram\nstyle A filter:blur(2px)", 'Unsupported ER CSS property'];
        yield ["erDiagram\nsubgraph Group", 'Unclosed subgraph'];
        yield ["erDiagram\nend", 'Unexpected end'];
        yield ["erDiagram\nA:::", 'Invalid entity relationship'];
        yield ["erDiagram\nA ||->o{ B : owns", 'Invalid entity relationship'];
    }

    public function testDirectParserRejectsUnknownDeclarations(): void
    {
        $parser = new ErParser();
        $parser->accept(new MermaidStatement('erDiagram', offset: 0, line: 0, column: 0));
        $this->expectExceptionMessageIsOrContains('Expected an ER entity or relationship');
        $parser->accept(new MermaidStatement('?', offset: 10, line: 1, column: 0));
    }

    #[DataProvider('limits')]
    public function testLimitsIncludeRepeatedDeclarations(string $source, string $message): void
    {
        $this->expectExceptionMessageIsOrContains($message);
        self::parse("erDiagram\n".$source);
    }

    /** @return iterable<array{string, string}> */
    public static function limits(): iterable
    {
        yield [\str_repeat("A\n", times: 4097), 'ER declaration limit'];
        yield [\str_repeat("A ||--|| A : self\n", times: 4097), 'edge limit'];
        yield ['A {'.\str_repeat("int a\n", times: 4097).'}', 'ER attribute limit'];
        yield ['A { string name "'.\str_repeat('a', times: 65_536).'" }', 'Statement exceeds'];
    }

    public function testLongFieldsWrapAndNativeExportsEscapeMarkup(): void
    {
        $source = "erDiagram\nA[\"<script>&amp;\"] { string name \"".\str_repeat('日本', times: 80)."\" }\nA ||..o{ B : owns";
        $scene = self::scene($source);
        $this->assertStringContainsString("\n", $scene->graph->nodes[0]->label);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $png = NativeFixtureExport::render($source, GraphExportFormat::Png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function testCompactRelationshipAndPinnedUpstreamExamples(): void
    {
        $edge = self::parse("erDiagram\nA||--o{B:owns")->graph->edges[0];
        $this->assertSame(['A', 'B', '1 : 0..* · owns'], [$edge['from'], $edge['to'], $edge['label']]);
        $paths = \glob(__DIR__.'/../fixtures/mermaid/er/*.mmd');
        $this->assertCount(15, $paths);
        foreach ($paths as $path) {
            $source = \file_get_contents($path);
            $this->assertIsString($source);
            $diagram = self::parse($source);
            $this->assertNotEmpty($diagram->graph->nodes);
            $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        }
    }

    public function testAttributeTablesAlignTheirColumnsAsPinned(): void
    {
        $header = \str_repeat('H', times: 50);
        $source = "erDiagram\nA[\"".$header.'"] { int id PK string note "'.\str_repeat('x', times: 70)."\" }\nB {}";
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        // Columns 6, 4, 2 and 70 wide, three spaces apart; each rule in the middle of its gap.
        $this->assertSame('er-card:2,4;c=91=7.5,14.5,19.5', $scene->shapes[$node->id]);
        $this->assertSame(
            'int      id     PK   '.\str_repeat(' ', times: 70)."\n\nstring   note        ".\str_repeat('x', times: 70),
            \implode("\n", \array_slice(\explode("\n", $node->label), offset: 3)),
        );
        $marks = MermaidOutline::marks($node, $scene->shapes[$node->id]);
        $this->assertCount(6, $marks);
        $top = $node->y - ($node->h / 2);
        $left = $node->x - (91 / 2);
        foreach ([7.5, 14.5, 19.5] as $index => $offset) {
            $this->assertSame([[$left + $offset, $top + 7], [$left + $offset, $top + $node->h]], $marks[$index + 1]->points, 'rules run from the first row down');
        }
        $this->assertSame($top + 7, $marks[4]->points[0][1]);
        $this->assertSame($top + 11, $marks[5]->points[0][1]);
        $this->assertSame([$node->x - ($node->w / 2), $node->x + ($node->w / 2)], [$marks[4]->points[0][0], $marks[4]->points[1][0]]);
        $this->assertSame(
            [['int   ', GraphFont::Regular], ['   id  ', GraphFont::Regular], ['   PK', GraphFont::Regular], ['   '.\str_repeat(' ', times: 70), GraphFont::Regular]],
            $scene->runs['node:'.$node->id][3],
        );
        $this->assertSame('rect', $scene->shapes[1]);
        $this->assertCount(2, $scene->selectionGraph->nodes);
        $this->assertSame(1, $scene->origin(0));
        $this->assertContains(['text' => 'int id [PK]', 'origin' => 1], $scene->legend, 'the legend keeps the prose row');
        $bare = self::scene("erDiagram\nB { string name int id }");
        $this->assertSame('er-card:1,3;c=13=7.5', $bare->shapes[$bare->graph->nodes[0]->id], 'columns no attribute fills are left out');
    }

    #[DataProvider('directions')]
    public function testEndpointDecorationsStayInsideTheSceneAndOutOfSelection(string $direction): void
    {
        $scene = self::scene("erDiagram\ndirection ".$direction."\nA ||--o{ B : \"relationship role!\"\nA u..|{ A : parents");
        $this->assertCount(2, $scene->elements);
        $this->assertCount(2, $scene->selectionGraph->nodes);
        $this->assertCount(2, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->label));
        $this->assertGreaterThan(2, \count($scene->graph->edges));
        $relation = $scene->graph->edges[0];
        $halfWidth = (\max(\array_map(AnsiUtils::visibleWidth(...), \explode("\n", $relation->label))) / 2) + 0.6;
        [$labelX, $labelY] = $relation->labelPos;
        $halfHeight = \substr_count($relation->label, needle: "\n") + 1;
        foreach ($scene->graph->edges as $edge) {
            $this->assertGreaterThanOrEqual(0.0, $edge->minX);
            $this->assertGreaterThanOrEqual(0.0, $edge->minY);
            $this->assertLessThanOrEqual($scene->graph->width, $edge->maxX);
            $this->assertLessThanOrEqual($scene->graph->height, $edge->maxY);
            if (null === $edge->label && $edge->tail === $relation->tail && $edge->head === $relation->head) {
                $this->assertTrue(
                    $edge->maxX < ($labelX - $halfWidth) || $edge->minX > ($labelX + $halfWidth) || $edge->maxY < ($labelY - $halfHeight) || $edge->minY > ($labelY + $halfHeight),
                    'the caption background must not erase endpoint glyphs',
                );
            }
        }
        $loop = $scene->graph->edges[1];
        $node = $scene->graph->nodes[$loop->tail];
        $this->assertCount(5, $loop->points);
        $this->assertSame([$node->x, $node->y + ($node->h / 2)], $loop->points[4]);
        $this->assertGreaterThan($loop->maxY, $loop->labelPos[1] - (\substr_count($loop->label, needle: "\n") + 1));
        $this->assertNotEmpty(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->ribbon));
    }

    #[DataProvider('directions')]
    public function testLongCaptionsContributeToTheViewportAndKeepGlyphSpace(string $direction): void
    {
        $scene = self::scene("erDiagram\ndirection ".$direction."\nA }o..o{ B : \"".\str_repeat('long role ', times: 60).'"');
        $edge = $scene->graph->edges[0];
        $lines = \explode("\n", $edge->label);
        $halfWidth = \max(\array_map(AnsiUtils::visibleWidth(...), $lines)) / 2;
        [$x, $y] = $edge->labelPos;
        $this->assertGreaterThanOrEqual(0.0, $x - $halfWidth - 0.6);
        $this->assertGreaterThanOrEqual(0.0, $y - \count($lines));
        $this->assertLessThanOrEqual($scene->graph->width, $x + $halfWidth + 0.6);
        $this->assertLessThanOrEqual($scene->graph->height, $y + \count($lines));
        $this->assertStringContainsString('long role', $edge->label);
        if (\in_array($direction, ['TB', 'BT'], strict: true)) {
            $this->assertGreaterThan((2 * \count($lines)) + 7, \abs(\array_last($edge->points)[1] - $edge->points[0][1]));
        }
    }

    public function testGraphicExportsContainOptionalCirclesAndCompartmentLines(): void
    {
        $source = "erDiagram\ndirection LR\nA { int id PK string name }\nA ||--o{ B : owns";
        self::scene($source);
        $drawing = MermaidDrawing::scene(NativeFixtureScene::render($source));
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        // The ring's inner disc is the entity background, white under the default redux-color theme.
        $this->assertNotEmpty(\array_filter($marks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind && 32 === \count($mark->points) && '#ffffff' === $mark->fill));
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>PK</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
    }

    public function testUnfilledEntityKeepsItsCompartmentOutline(): void
    {
        $source = "erDiagram\nA { int id }\nstyle A fill:none,stroke:blue";
        $scene = self::scene($source);
        $node = $scene->graph->nodes[0];
        $this->assertSame('none', $node->fillColor);
        $marks = MermaidOutline::marks($node, $scene->shapes[$node->id]);
        $this->assertSame('none', $marks[0]->fill);
        $this->assertSame('#0000ff', $marks[1]->stroke);
        $this->assertStringContainsString('fill="none"', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    public function testForwardClassesInlineStylesAndCompartmentBorders(): void
    {
        $source = "erDiagram\nclassDef default fill:#fafafa,stroke:black,stroke-width:1px,color:#111\nclassDef first, second fill:red\nclassDef second stroke:blue,stroke-dasharray:4 2\nclass A, B first, second %% hidden\nstyle A fill:#123456,stroke:none,stroke-width:0,color:white,stroke-dasharray:0;\nA[Account]:::first { int id PK }\nB:::second { int id }\nC";
        $diagram = self::parse($source);
        $this->assertSame(6, $diagram->graph->nodes['A']['origin']);
        $scene = self::scene($source);
        [$a, $b, $c] = $scene->graph->nodes;
        $this->assertSame('#123456', $a->fillColor);
        $this->assertSame('none', $a->color);
        $this->assertSame('#ffffff', $a->textColor);
        $this->assertSame(0.0, $a->strokeWidth);
        $this->assertFalse($a->dashed);
        $this->assertSame('#ff0000', $b->fillColor);
        $this->assertSame('#0000ff', $b->color);
        $this->assertTrue($b->dashed);
        $this->assertSame(0.1, $b->strokeWidth);
        $this->assertSame('#fafafa', $c->fillColor);
        $this->assertSame('#111111', $c->textColor);
        $this->assertFalse($c->dashed);
        $divider = MermaidOutline::marks($b, $scene->shapes[$b->id])[1];
        $this->assertSame('#0000ff', $divider->stroke);
        $this->assertSame(0.1, $divider->strokeWidth);
        $this->assertTrue($divider->dashed);
        $this->assertCount(3, $scene->elements);
    }

    public function testRelationshipEndpointClassesAndQuotedUnicodeNames(): void
    {
        $source =
            'erDiagram'
            ."\n"
            .'"Customer account":::left ||--o{ 訂單-行:::right, wide : orders'
            ."\n"
            .'"Customer account":::right'
            ."\nclassDef left fill:red,stroke:#aa0011\nclassDef right fill:blue\nclassDef wide stroke-width:3px\nclass 訂單-行 left\n";
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame('#0000ff', $nodes['Customer account']->fillColor);
        $this->assertSame('#aa0011', $nodes['Customer account']->color);
        $this->assertSame('#ff0000', $nodes['訂單-行']->fillColor);
        $this->assertSame(0.3, $nodes['訂單-行']->strokeWidth);
        $this->assertSame([0 => 1, 1 => 1], $scene->elements);
        $this->assertGreaterThan(1, \count($scene->graph->edges));
    }

    public function testStyledExportsUseCardAndTextColors(): void
    {
        $source = "erDiagram\nA:::skin { int id PK }\nA ||--o{ B : owns\nclassDef skin fill:#123456,stroke:#654321,color:#abcdef,stroke-width:2px,stroke-dasharray:3 2";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('fill="#123456"', $svg);
        $this->assertStringContainsString('stroke="#654321"', $svg);
        $this->assertStringContainsString('fill="#abcdef"', $svg);
        $this->assertStringContainsString('stroke-width="0.2"', $svg);
        $this->assertStringContainsString('stroke-dasharray=', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
    }

    public function testStyleDirectiveAndClassExpansionBudgets(): void
    {
        $ids = \implode(',', \array_map(static fn (int $id): string => 'A'.$id, \range(0, end: 15)));
        $classes = \implode(',', \array_map(static fn (int $id): string => 'C'.$id, \range(0, end: 63)));
        $directive = 'class '.$ids.' '.$classes."\n";
        $valid = "erDiagram\nclassDef default fill:red\n".\str_repeat($directive, times: 64);
        $this->assertSame('#ff0000', self::parse($valid)->styles->forNode('A0')['fill']);
        $this->expectExceptionMessageIsOrContains('ER class-assignment limit');
        self::parse($valid.$directive);
    }

    public function testStyleDirectiveLimitIncludesRepeatedDefinitions(): void
    {
        $this->expectExceptionMessageIsOrContains('ER style limit');
        self::parse("erDiagram\n".\str_repeat("classDef repeated fill:red\n", times: 4097));
    }

    #[DataProvider('invalidStyles')]
    public function testUnsupportedStyleInputReportsItsOrigin(string $directive, string $message): void
    {
        try {
            self::parse("erDiagram\n  ".$directive);
            $this->fail('Invalid ER CSS accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame(1, $error->sourceLine);
            $this->assertSame(2, $error->column);
        }
    }

    /** @return iterable<array{string, string}> */
    public static function invalidStyles(): iterable
    {
        yield ['class A', 'Expected ER style or class assignment'];
        yield ['style A fill', 'Expected ER style property'];
        yield ['style A fill:invalid', 'Invalid ER style color'];
        yield ['style A color:none', 'Invalid ER style color'];
        yield ['style A stroke-width:-1', 'ER stroke width'];
        yield ['style A stroke-width:1001', 'ER stroke width'];
        yield ['style A stroke-dasharray:-1', 'ER dash lengths'];
        yield ['style A filter:blur(2px)', 'Unsupported ER CSS property'];
    }

    public function testCancellationPreventsPartialPublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "erDiagram\n".\str_repeat("A ||--o{ B : owns\n", times: 3000),
            static function (): never {
                self::fail('Cancelled ER diagram published');
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
    }
}
