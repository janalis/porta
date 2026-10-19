<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\UsecaseConfiguration;
use Porta\GraphViewer\Mermaid\UsecaseCursor;
use Porta\GraphViewer\Mermaid\UsecaseDiagram;
use Porta\GraphViewer\Mermaid\UsecaseJson;
use Porta\GraphViewer\Mermaid\UsecaseLayout;
use Porta\GraphViewer\Mermaid\UsecaseLexer;
use Porta\GraphViewer\Mermaid\UsecaseParser;
use Porta\GraphViewer\Mermaid\UsecaseStyles;

final class UsecaseTest extends TestCase
{
    private static function parse(string $source): UsecaseDiagram
    {
        $parser = new UsecaseParser();
        foreach (new UsecaseLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public static function fixtures(): iterable
    {
        $expected = \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/usecase-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach (\glob(__DIR__.'/../fixtures/mermaid/usecase/*.mmd') as $path) {
            yield \basename($path) => [$path, $expected[\basename($path)]];
        }
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesMatchTheUpstreamSemanticModel(string $path, array $expected): void
    {
        $source = \preg_replace('/\A---\R.*?\R---\R/s', replacement: '', subject: \file_get_contents($path));
        $diagram = self::parse($source);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        $this->assertSame('TD' === $expected['direction'] ? 'TB' : $expected['direction'], $diagram->graph->direction);
        $actual = ['actor' => [], 'usecase' => [], 'boundary' => [], 'json' => []];
        foreach ($diagram->elements as $element) {
            if ('edge' === $element->kind) {
                continue;
            }
            $actual[$element->kind][] = $element->id;
        }
        foreach (['actor' => 'actors', 'usecase' => 'usecases', 'boundary' => 'boundaries'] as $role => $key) {
            $this->assertEqualsCanonicalizing(\array_column($expected[$key], 'id'), $actual[$role]);
            foreach ($expected[$key] as $element) {
                $native = $diagram->elements[$element['id']];
                $this->assertSame($element['label'], $native->raw);
                $this->assertSame($element['stereotype'] ?? null, $native->stereotype);
                $this->assertSame($element['business'] ?? false, $native->metadata['business'] ?? false);
                if ('actor' === $role) {
                    $icon = $native->metadata['icon'] ?? '';
                    $this->assertSame($element['type'], '' !== $icon ? 'icon' : $native->metadata['type'] ?? 'normal');
                }
                if ('usecase' === $role) {
                    $this->assertSame($element['shape'], $native->shape);
                }
                if ('boundary' === $role) {
                    $this->assertSame($element['type'], $native->metadata['type'] ?? 'rect');
                    $members = [];
                    foreach ($diagram->elements as $member) {
                        if ($member->parent !== $native->id) {
                            continue;
                        }
                        $members[] = $member->id;
                    }
                    $this->assertEqualsCanonicalizing($element['members'], $members);
                }
            }
        }
        $this->assertEqualsCanonicalizing($expected['json'], $actual['json']);
        $this->assertCount(\count($expected['relations']), $diagram->relations);
        foreach ($expected['relations'] as $index => $relationship) {
            $native = $diagram->relations[$index];
            $this->assertSame($relationship['from'], $native->from);
            $this->assertSame($relationship['to'], $native->to);
            $this->assertSame($relationship['kind'], $native->kind);
            $this->assertSame($relationship['id'], $native->id);
            $this->assertSame($relationship['label'], $native->label);
        }
        $this->assertCount(\count($expected['notes']), $diagram->notes);
    }

    public static function invalidBodies(): iterable
    {
        foreach ([
            'class',
            'style',
            'classDef',
            'package',
            'A@{business:actor}',
            'A@{business:"`yes`"}',
            'end',
            'direction UP',
            'systemBoundary B\nA',
            'systemBoundary B\ndirection LR\nend',
            'systemBoundary B\nA --> B\nend',
            'systemBoundary B\nA@{business:true}\nend',
            'actor A[Wrong]',
            'A()',
            'A(unterminated',
            'A("quoted" trailing)',
            'actor !',
            'note A "bad"',
            'note for A unquoted',
            'note for B "unknown"',
            'A <<',
            'A <<>>',
            'A:::red --> B',
            'A --> B trailing',
            'A ..>: unknown B',
            'A ~~ B',
            'A <-- label --> B',
            'A o-- label --- B',
            'actor A\nA ..>: include B',
            'actor A\nA --|> B',
            'systemBoundary B\nend\nA --> B',
            'json J @{}\nJ --o A',
            'json J @{"bad":}',
            'json J @{}\njson J @{}',
            'json J(x) @{}',
            'A@{business:true}',
            'A@{business: true type: normal}',
            'json J @{}\nJ@{business:true}',
            'A@{123}',
            'actor A@{unknown:true}',
            'actor A@{type:wrong}',
            'actor A@{type:hollow,icon:"x"}',
            'actor A@{type:awesome,business:true}',
            'A[Rect]@{business:true}',
            'A@{business:"true"}\nA',
            'A\nactor A',
            '"same"\n"same"',
            'A(one)\nA(two)',
            'A(one)\nA[one]',
            'A <<one>>\nA <<two>>',
            'actor A@{business:true}\nactor A@{business:false}',
            'systemBoundary B\nA\nend\nsystemBoundary C\nA\nend',
            'A e@--> B\nA e@--> B',
            'A e@--> B\ne --> A',
            'style A fill:red;',
            'accTitle invalid',
            'accDescr {never closed',
            'A("plain\nline")',
            'A("unclosed)',
            'A }',
        ] as $body) {
            yield $body => [\str_replace('\n', replace: "\n", subject: $body)];
        }
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidDeclarations(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("usecase-beta\n".$body);
    }

    public function testJsonRowsPreserveOrderingPathsAndJavascriptNumbers(): void
    {
        $statement = new MermaidStatement(
            '{"2":2,"1":1,"values":[true,false,null,-0,9007199254740993,1e-6,1e-7,1e20,1e21,1e400,-1e400],"nested":[{"a":[]},{}]}',
            offset: 0,
            line: 0,
            column: 0,
        );
        $work = UsecaseJson::rows(UsecaseJson::decode($statement), $statement);
        $this->assertNotEmpty(\iterator_to_array($work));
        $rows = $work->getReturn();
        $this->assertSame(['2', '1', 'values', '', '', '', '', '', '', '', '', '', '', 'nested[0].a', 'nested[1]'], \array_column($rows, 'key'));
        $this->assertSame(
            ['2', '1', 'true', 'false', 'null', '0', '9007199254740992', '0.000001', '1e-7', '100000000000000000000', '1e+21', 'Infinity', '-Infinity', '[]', '{}'],
            \array_column($rows, 'value'),
        );
    }

    public function testGeneratedIdsUseAsciiWordsAndUtf16Units(): void
    {
        $diagram = self::parse("usecase-beta\nactor \"é😀\"\n");
        $this->assertArrayHasKey('___', $diagram->elements);
    }

    #[DataProvider('fixtures')]
    public function testPinnedBodiesExportNativeSvg(string $path, array $expected): void
    {
        $svg = NativeFixtureExport::render(\file_get_contents($path), GraphExportFormat::Svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertNotEmpty($expected);
    }

    public static function invalidStyles(): iterable
    {
        yield ['style Unknown fill:red'];
        yield ['style A,B fill:red'];
        yield ['classDef red'];
    }

    #[DataProvider('invalidStyles')]
    public function testRejectsInvalidStyleTargets(string $style): void
    {
        $diagram = self::parse("usecase-beta\nA\nB\n".$style);
        $work = UsecaseStyles::apply($diagram, $diagram->graph);
        $this->expectException(MermaidSyntaxError::class);
        \iterator_to_array($work);
    }

    public static function boundedBodies(): iterable
    {
        yield 'symbols' => [\implode("\n", \array_map(static fn (int $i): string => 'A'.$i, \range(0, end: 4096)))];
        yield 'relations' => [\str_repeat("A --> B\n", times: 4097)];
        yield 'declarations' => [\str_repeat("A\n", times: 16_384)];
        yield 'statement' => ['A('.\str_repeat('x', times: 65_537).')'];
        yield 'depth' => ['json J @'.\str_repeat('{', times: 129).\str_repeat('}', times: 129)];
    }

    #[DataProvider('boundedBodies')]
    public function testBoundsRejectExcessWork(string $body): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse("usecase-beta\n".$body);
    }

    public function testEmptyAndIncorrectHeadersFail(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse('');
    }

    public function testIncorrectHeaderFails(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse('usecase');
    }

    public function testJsonRootsMustBeObjects(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        UsecaseJson::decode(new MermaidStatement('[]', offset: 0, line: 0, column: 0));
    }

    public function testJsonTableBounds(): void
    {
        $statement = new MermaidStatement('{}', offset: 0, line: 0, column: 0);
        $work = UsecaseJson::rows((object) ['values' => \range(0, end: 4096)], $statement);
        $this->expectException(MermaidSyntaxError::class);
        \iterator_to_array($work);
    }

    public function testActorListsMetadataAndInlineClassesRemainOrdered(): void
    {
        $diagram = self::parse("usecase-beta\r\n%% apostrophe ' ignored\r\nactor A:::red,blue\nactor B\r\nA@{\n business: true\n}\nactor A\n");
        $this->assertSame(['red', 'blue'], $diagram->elements['A']->classes);
        $this->assertArrayHasKey('B', $diagram->elements);
        $this->assertTrue($diagram->elements['A']->metadata['business']);
    }

    public function testCursorRejectsUnclosedMetadataWithoutLexer(): void
    {
        $cursor = new UsecaseCursor(new MermaidStatement('@{type:normal', offset: 0, line: 0, column: 0));
        $this->expectException(MermaidSyntaxError::class);
        $cursor->metadata();
    }

    public function testActorListsAndEmptyMultilineMetadata(): void
    {
        $diagram = self::parse("usecase-beta\nactor A, B, C\nA@{\n  }\n");
        $this->assertSame(['A', 'B', 'C'], \array_keys($diagram->elements));
    }

    public function testConfigurationOverlaysPreserveExplicitZeroAndFalse(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $first = MermaidConfiguration::parse((object) ['usecase' => (object) ['rankSpacing' => 60, 'useMaxWidth' => true]], $statement);
        $later = MermaidConfiguration::parse((object) ['usecase' => (object) ['rankSpacing' => 0, 'useMaxWidth' => false, 'actorFontSize' => 20.5]], $statement);
        $merged = $first->overlay($later);
        $this->assertSame(0.0, $merged->usecase->spacing('rankSpacing', default: 8.0));
        $this->assertSame('font-size:20.5', $merged->usecase->css('actor'));
        $this->assertTrue($merged->usecase->configured());
        $merged->validateFamily('usecase');
        $this->expectException(MermaidSyntaxError::class);
        $merged->validateFamily('sequence');
    }

    public static function invalidConfigurations(): iterable
    {
        foreach ([
            ['rankSpacing' => -1],
            ['actorFontSize' => \INF],
            ['actorFontFamily' => []],
            ['usecaseFontFamily' => 'a;b'],
            ['useMaxWidth' => 0],
            ['actorFontWeight' => 'bold;fill:red'],
            ['actorFontWeight' => 'bold,x'],
        ] as $options) {
            yield [$options];
        }
    }

    public function testFontFamiliesTakeCssListsLikeThePinnedDefault(): void
    {
        $configuration = UsecaseConfiguration::parse(
            (object) ['actorFontFamily' => '"Open Sans", sans-serif', 'usecaseFontFamily' => 'serif'],
            new MermaidStatement('', offset: 0, line: 0, column: 0),
        );
        $this->assertTrue($configuration->configured());
    }

    #[DataProvider('invalidConfigurations')]
    public function testConfigurationRejectsInvalidValues(array $options): void
    {
        $this->expectException(MermaidSyntaxError::class);
        UsecaseConfiguration::parse((object) $options, new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testConfigurationRequiresMapping(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        MermaidConfiguration::parse((object) ['usecase' => true], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }

    public function testNativeSceneRetainsSourceRolesStylesAndImmutableModel(): void
    {
        $diagram = self::parse("usecase-beta\nactor A\nactor B\nA e@--|> B\nclassDef strong stroke:red,stroke-width:4px,color:green,font-size:32px\nclass e strong\n");
        $before = \serialize($diagram);
        $work = new UsecaseLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertSame($before, \serialize($diagram));
        $this->assertCount(1, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        foreach ($scene->graph->edges as $edge) {
            $this->assertSame('#ff0000', $edge->color);
            $this->assertSame(0.4, $edge->strokeWidth);
        }
        $this->assertSame('#008000', $scene->graph->edges[0]->textColor);
        $this->assertSame(2.0, $scene->graph->edges[0]->fontScale);
        foreach ($scene->graph->nodes as $node) {
            $this->assertSame('uc-actor:normal:0', $scene->shapes[$node->id]);
            $this->assertSame($diagram->elements[$node->name]->statement->line, $scene->elements[$node->id]);
        }
    }

    public function testPngIncludesNativeGlyphsAndTable(): void
    {
        $png = NativeFixtureExport::render("usecase-beta\nactor A\nA --> B(Checkout)\njson J @{\"total\":42}\nB --> J", GraphExportFormat::Png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $image = \imagecreatefromstring($png);
        $this->assertGreaterThan(20, \imagesx($image));
        $this->assertGreaterThan(20, \imagesy($image));
    }

    public function testMetadataRejectsMarkdownEvenWhenItsTextIsAValidEnum(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Use-case metadata requires plain strings');
        self::parse('usecase-beta'."\n".'actor A@{type:"`normal`"}');
    }

    public function testConfigurationChangesSpacingBeforeEdgesAreRouted(): void
    {
        $diagram = self::parse("usecase-beta\nactor A, B\nA --> C(Work)\nB --> C");
        $plain = new UsecaseLayout()->layout($diagram);
        \iterator_to_array($plain);
        $configuration = UsecaseConfiguration::parse(
            (object) ['rankSpacing' => 300, 'nodeSpacing' => 200, 'diagramPadding' => 100, 'actorFontSize' => 32, 'actorFontWeight' => 'bold'],
            new MermaidStatement('', offset: 0, line: 0, column: 0),
        );
        $configured = new UsecaseLayout($configuration)->layout($diagram);
        \iterator_to_array($configured);
        $scene = $configured->getReturn();
        $this->assertGreaterThan($plain->getReturn()->graph->width, $scene->graph->width);
        $this->assertGreaterThan($plain->getReturn()->graph->height, $scene->graph->height);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(2.0, $nodes['A']->fontScale);
        $this->assertSame('bold', $nodes['A']->font->weight());
        $this->assertGreaterThan(20.0, $nodes['C']->x - $nodes['A']->x);
        $this->assertGreaterThan(20.0, $nodes['B']->y - $nodes['A']->y);
    }

    public function testBacktickLabelsDrawTheirMarkdownAsPinned(): void
    {
        $scene = NativeFixtureScene::render((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/usecase/04-labels-and-notes.mmd'));
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(
            [[['Formatted label', GraphFont::Bold]], [['with a physical line break', GraphFont::Regular]]],
            $scene->runs['node:'.$nodes['Formatted']->id],
            'the physical break drops its indentation',
        );
        $this->assertSame("Formatted label\nwith a physical line break", $nodes['Formatted']->label);
        $this->assertSame([[['Reviewer', GraphFont::Italic]]], $scene->runs['node:'.$nodes['Reviewer']->id]);
        $this->assertArrayNotHasKey('node:'.$nodes['Literal']->id, $scene->runs, 'a plain string keeps its markers');
        $this->assertSame(
            [
                ['Requires an ',   GraphFont::Regular],
                ['active session', GraphFont::Bold],
            ],
            $scene->runs['node:'.$nodes['@usecase-note:0']->id][0],
        );
        $edge = \array_find_key($scene->graph->edges, static fn ($edge): bool => 'opens review form' === $edge->label);
        $this->assertSame(
            [[
                ['opens ',      GraphFont::Regular],
                ['review form', GraphFont::Bold],
            ]],
            $scene->runs['edge:'.$edge],
        );
        $drawing = MermaidDrawing::scene($scene);
        \iterator_to_array($drawing);
        $reviewer = \array_values(\array_filter($drawing->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && 'Reviewer' === $mark->text));
        $this->assertCount(1, $reviewer, 'an actor draws its caption once, under its glyph');
        $this->assertSame(GraphFont::Italic, $reviewer[0]->font);
        $stereotyped = NativeFixtureScene::render("usecase-beta\n  Quote(\"`**Prepare** quote`\") <<Core>>\n");
        $quote = \array_column($stereotyped->graph->nodes, null, 'name')['Quote'];
        $this->assertSame(
            [
                [['«Core»', GraphFont::Regular]],
                [
                    ['Prepare', GraphFont::Bold],
                    [' quote',  GraphFont::Regular],
                ],
            ],
            $stereotyped->runs['node:'.$quote->id],
        );
    }
}
