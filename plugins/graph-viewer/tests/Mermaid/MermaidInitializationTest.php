<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidFrontMatter;
use Porta\GraphViewer\Mermaid\MermaidInitialization;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSource;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\PieLayout;
use Porta\GraphViewer\Mermaid\PieParser;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;
use Porta\Tests\Support\FakeScheduler;

final class MermaidInitializationTest extends TestCase
{
    private static function initialize(string $source, MermaidConfiguration $configuration = new MermaidConfiguration()): MermaidInitialization
    {
        $work = MermaidInitialization::parse($source, $configuration);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testMirroredParticipantsPreserveLifetimesActivationAndSelection(): void
    {
        $configuration = self::initialize('%%{init: {sequence: {mirrorActors: true}}}%%')->configuration;
        $parser = new SequenceParser();
        $source = "sequenceDiagram\nparticipant A as Alice\nparticipant B as Bob\nA->>+B: Start\ncreate participant C as Carol\nB->>C: Hello\ndestroy B\nB->>A: Goodbye";
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $work = new SequenceLayout($configuration)->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $names = \array_count_values(\array_column($scene->graph->nodes, 'name'));
        $this->assertSame(2, $names['A']);
        $this->assertSame(2, $names['B'], 'a destroyed participant gets its footer where it dies');
        $this->assertSame(2, $names['C']);
        $this->assertSame(1, $names['activation0']);
        $bottom = 0.0;
        foreach ($scene->graph->nodes as $node) {
            $bottom = \max($bottom, $node->y + ($node->h / 2));
            if ('A' === $node->name || 'C' === $node->name) {
                $this->assertSame('A' === $node->name ? 1 : 4, $scene->origin($node->id));
            }
        }
        $this->assertGreaterThanOrEqual($bottom, $scene->graph->height);
        $this->assertSame(1, \array_last($scene->origins));
        foreach (['A', 'C'] as $actor) {
            $headers = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => $actor === $node->name));
            $this->assertSame($headers[0]->x, $headers[1]->x);
            $this->assertSame($headers[0]->h, $headers[1]->h);
            $this->assertGreaterThan($headers[0]->y, $headers[1]->y);
        }
        $parser->accept(new MermaidStatement('link A: Docs @ https://example.test', offset: 0, line: 8, column: 0));
        $work = new SequenceLayout($configuration)->layout($parser->finish());
        \iterator_to_array($work);
        $withMenu = $work->getReturn();
        $panel = \array_last($withMenu->graph->nodes);
        $this->assertGreaterThanOrEqual($bottom, $panel->y - ($panel->h / 2));
        $this->assertSame(8, $withMenu->origin($panel->id));

        $enabled = "%%{init: {sequence: {mirrorActors: true}}}%%\nsequenceDiagram\nparticipant A as Alice\nA->>A: Hello";
        $svg = NativeFixtureExport::render($enabled, GraphExportFormat::Svg);
        $this->assertSame(2, \substr_count($svg, needle: 'Alice'));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($enabled, GraphExportFormat::Png));
        $disabled = "---\nconfig: {sequence: {mirrorActors: true}}\n---\n%%{init: {sequence: {mirrorActors: false}}}%%\nsequenceDiagram\nparticipant A as Alice";
        $this->assertSame(1, \substr_count(NativeFixtureExport::render($disabled, GraphExportFormat::Svg), needle: 'Alice'));
    }

    public function testMirrorConfigurationMergesIndependentlyAndValidatesFamily(): void
    {
        $first = self::initialize('%%{init: {sequence: {mirrorActors: true}}}%%')->configuration;
        $merged = self::initialize('%%{init: {sequence: {showSequenceNumbers: true}}}%%', $first)->configuration;
        $this->assertTrue($merged->mirrorActors);
        $this->assertTrue($merged->sequenceNumbers);
        $emptyParser = new SequenceParser();
        $emptyParser->accept(new MermaidStatement('sequenceDiagram', offset: 0, line: 0, column: 0));
        $empty = $emptyParser->finish();
        $work = new SequenceLayout($first)->layout($empty);
        \iterator_to_array($work);
        $this->assertSame([], $work->getReturn()->graph->nodes);
        $parser = new SequenceParser();
        foreach (new MermaidLexer('sequenceDiagram; destroy A; A->>A: End', textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $work = new SequenceLayout($first)->layout($parser->finish());
        \iterator_to_array($work);
        $this->assertSame(2, \array_count_values(\array_column($work->getReturn()->graph->nodes, 'name'))['A'], 'its header and the footer where it dies, never a mirrored one');
        $this->expectException(MermaidSyntaxError::class);
        $first->validateFamily('pie');
    }

    public function testMirrorConfigurationRejectsNonBooleanValues(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Sequence mirrorActors must be boolean');
        self::initialize('%%{init: {sequence: {mirrorActors: 1}}}%%');
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
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('2. Second', $svg);
    }

    public function testQuotedTerminatorCommentsAndMultilineInitializationStayInsideTheDirective(): void
    {
        $source = "%%{\ninit: {\npie: { highlightSlice: 'literal }%%; #hash' }\n}\n}%%\npie\n\"literal }%%; #hash\": 1";
        $document = self::initialize($source);
        $this->assertSame('literal }%%; #hash', $document->configuration->pieHighlight);
        $this->assertSame(\strlen($source), \strlen($document->source));
        $this->assertStringEndsWith("pie\n\"literal }%%; #hash\": 1", $document->source);
        $statements = \iterator_to_array(new MermaidLexer($document->source, chart: true)->statements());
        $this->assertSame([5, 6], \array_column($statements, 'line'));
        $this->assertSame('', self::initialize('')->source);
        $this->assertSame('%% ordinary', self::initialize('%% ordinary')->source);
    }

    public function testDirectivesApplyWhereverTheyStandAsPinnedDetectInitFindsThem(): void
    {
        $source = "pie\n\"Dogs\": 3 %%{init: {pie: {highlightSlice: Dogs}}}%%\n\"Cats\": 1\n%%{wrap}%%";
        $document = self::initialize($source);
        $this->assertSame('Dogs', $document->configuration->pieHighlight, 'even mid-line, after the header');
        $this->assertTrue($document->configuration->wrapping(), '`%%{wrap}%%` wraps every label');
        $this->assertSame(
            "pie\n\"Dogs\": 3 ".\str_repeat(' ', \strlen('%%{init: {pie: {highlightSlice: Dogs}}}%%'))."\n\"Cats\": 1\n          ",
            $document->source,
            'each directive is blanked in place',
        );
        $this->assertSame(['pie', '"Dogs": 3', '"Cats": 1'], \array_map(\trim(...), \array_column(\iterator_to_array(new MermaidLexer($document->source, chart: true)->statements()), 'text')));
        $quoted = 'flowchart LR; A["%%{init: invalid}%%"]';
        try {
            self::initialize($quoted);
            $this->fail('a quoted directive is still a directive, as pinned');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame('Line 1, column 18: Expected init or initialize with a configuration mapping', $error->getMessage());
        }
    }

    public function testANestedConfigMapReadsAsRootOptions(): void
    {
        // Pinned directives read `config` inside `init` as root options, the directive's own winning.
        $configuration = self::initialize("%%{init: {'config': {'wrap': true, 'pie': {'highlightSlice': 'A'}}, 'pie': {'highlightSlice': 'B'}}}%%")->configuration;
        $this->assertTrue($configuration->wrapping());
        $this->assertSame('B', $configuration->pieHighlight);
    }

    public function testALeadingByteOrderMarkIsDroppedAsABrowserDropsIt(): void
    {
        $this->assertSame("   ---\n", MermaidSource::unmarked("\u{FEFF}---\n"));
        $this->assertSame("a\u{FEFF}", MermaidSource::unmarked("a\u{FEFF}"));
        $this->assertStringContainsString('>Marked</text>', NativeFixtureExport::render("\u{FEFF}flowchart TD\nA[Marked]", GraphExportFormat::Svg));
        // As pinned, the mark reads as leading space: front matter must open the file.
        $scheduler = new FakeScheduler();
        $error = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "\u{FEFF}---\ntitle: T\n---\nflowchart TD\nA",
            GraphExportFormat::Svg,
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
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('#ececff', $svg);
        $this->assertStringContainsString('opacity="0.7"', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    public function testEmptyMapsAndUnspecifiedOptionsMergeWithoutResettingValues(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 2, column: 4);
        $base = new MermaidConfiguration(sequenceNumbers: true, pieHighlight: 'Dogs');
        $merged = $base->overlay(new MermaidConfiguration());
        $this->assertTrue($merged->sequenceNumbers);
        $this->assertSame('Dogs', $merged->pieHighlight);
        $this->assertSame('', $base->overlay(new MermaidConfiguration(pieHighlight: ''))->pieHighlight);
        foreach (['%%{init: {}}%%', '%%{init: {pie: {}}}%%', '%%{initialize: {sequence: {}}}%%'] as $text) {
            $config = self::initialize($text)->configuration;
            $config->validateFamily('other');
            $this->assertNull($config->sequenceNumbers);
            $this->assertNull($config->pieHighlight);
        }
        $config = MermaidConfiguration::parse((object) ['sequence' => (object) ['showSequenceNumbers' => true]], $statement);
        try {
            $config->overlay(self::initialize('%%{init: {}}%%')->configuration)->validateFamily('pie');
            $this->fail('Wrong family accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([2, 4], [$error->sourceLine, $error->column]);
        }
        $this->expectException(MermaidSyntaxError::class);
        new MermaidConfiguration(pieHighlight: 'Dogs')->validateFamily('sequence');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'unclosed' => ['%%{init: {}', 'Unclosed Mermaid initialization'];
        yield 'size' => ["%%{init: {pie: {highlightSlice: '".\str_repeat('x', times: 65_536)."'}}}%%", 'size limit'];
        yield 'depth' => ['%%{init: '.\str_repeat('[', times: 129).'0'.\str_repeat(']', times: 129).'}%%', 'depth limit'];
        yield 'closing' => ['%%{init: ]}%%', 'Invalid initialization nesting'];
        yield 'yaml' => ['%%{init: {pie: {highlightSlice: "\\q"}}}%%', 'Invalid Mermaid initialization'];
        yield 'empty' => ['%%{}%%', 'Expected one init'];
        yield 'two wrappers' => ['%%{init: {}, initialize: {}}%%', 'Expected one init'];
        yield 'wrapper type' => ['%%{init: []}%%', 'configuration mapping'];
        yield 'wrapper name' => ['%%{wrap: {}}%%', 'Expected init or initialize'];
        yield 'section' => ['%%{init: {pie: false}}%%', 'sections must be mappings'];
        yield 'wrong kind' => ['%%{init: {gantt: {topAxis: 1}}}%%', 'Mermaid configuration option gantt.topAxis must be true or false'];
        yield 'nested' => ['%%{init: {xyChart: {xAxis: {showLabel: yes}}}}%%', 'Mermaid configuration option xyChart.xAxis.showLabel must be true or false'];
        yield 'enum' => ['%%{init: {er: {layoutDirection: up}}}%%', 'must be one of "TB", "BT", "LR", "RL"'];
        yield 'numbering type' => ['%%{init: {sequence: {showSequenceNumbers: "true"}}}%%', 'must be boolean'];
        yield 'highlight type' => ['%%{init: {pie: {highlightSlice: 12}}}%%', 'must be a string'];
        yield 'hover' => ['%%{init: {pie: {highlightSlice: hover}}}%%', 'hover highlighting requires'];
        yield 'alias' => ['%%{init: {pie: {highlightSlice: *missing}}}%%', 'Invalid Mermaid initialization'];
        yield 'defined alias' => ['%%{init: {sequence: &empty {}, pie: *empty}}%%', 'Invalid Mermaid initialization'];
        yield 'tag' => ['%%{init: {pie: {highlightSlice: !php/const PHP_VERSION}}}%%', 'Invalid Mermaid initialization'];
    }

    #[DataProvider('invalid')]
    public function testInvalidDirectivesReportTheirOriginalLocation(string $source, string $message): void
    {
        try {
            self::initialize("%% comment\n  ".$source);
            $this->fail('Invalid initialization accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame([1, 2], [$error->sourceLine, $error->column]);
        }
    }

    public function testDirectiveBudgetAndFrontMatterMappingGuard(): void
    {
        $source = \str_repeat('%%{init: {}}%%', times: 4096);
        $this->assertSame(\strlen($source), \strlen(self::initialize($source)->source));
        try {
            self::initialize($source.'%%{init: {}}%%');
            $this->fail('Directive budget ignored');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('directive limit', $error->getMessage());
        }
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('configuration must be a mapping');
        MermaidFrontMatter::parse("---\nconfig: []\n---\ninfo");
    }

    public function testLaterInvalidDirectiveRetainsItsAbsoluteUnicodeSourcePosition(): void
    {
        $prefix = "%%{init: {}}%%\r\n%%{initialize: {}}%%\n%% 中文\n  ";
        try {
            self::initialize($prefix.'%%{init: {sequence: {showSequenceNumbers: 1}}}%%');
            $this->fail('Invalid late directive accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame(3, $error->sourceLine);
            $this->assertSame(2, $error->column);
            $this->assertSame(\strlen($prefix), $error->offset);
            $this->assertStringContainsString('must be boolean', $error->getMessage());
        }
    }
}
