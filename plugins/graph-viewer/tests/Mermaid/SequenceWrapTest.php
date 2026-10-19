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
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceDiagram;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;
use Porta\GraphViewer\Mermaid\SequenceText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class SequenceWrapTest extends TestCase
{
    private static function parse(string $source, MermaidConfiguration $configuration = new MermaidConfiguration()): SequenceDiagram
    {
        $parser = new SequenceParser($configuration);
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testPrefixesOverrideConfigurationBeforeEntityDecoding(): void
    {
        $label = \trim(\str_repeat('word ', times: 20));
        $wrapped = \trim(\str_repeat('word ', times: 12))."\n".\trim(\str_repeat('word ', times: 8));
        $enabled = new MermaidConfiguration(sequenceWrap: true);
        $this->assertSame($label, SequenceText::parse($label, new MermaidConfiguration()));
        $this->assertSame($wrapped, SequenceText::parse($label, $enabled));
        $this->assertSame($wrapped, SequenceText::parse('wrap: '.$label, new MermaidConfiguration()));
        $this->assertSame($wrapped, SequenceText::parse(':wrap: '.$label, new MermaidConfiguration(sequenceWrap: false)));
        $this->assertSame($label, SequenceText::parse('nowrap: '.$label, $enabled));
        $this->assertSame($label, SequenceText::parse(':nowrap: '.$label, $enabled));
        $this->assertSame('wrap: '.$label, SequenceText::parse('#119;rap: '.$label, new MermaidConfiguration()));
        $this->assertSame("alpha\n\nbeta", SequenceText::parse('wrap: alpha<br><br/>beta', $enabled));
        $this->assertSame('', SequenceText::parse('wrap:', $enabled));
        $left = \str_repeat('x', times: 50);
        $right = \str_repeat('y', times: 50);
        $this->assertSame($left."\n".$right, SequenceText::parse($left.'<br>'.$right, $enabled));
    }

    public function testUnicodeAndLongWordsUseDisplayColumnsWithoutSplittingGraphemes(): void
    {
        $enabled = new MermaidConfiguration(sequenceWrap: true);
        foreach ([\str_repeat('界', times: 61), \str_repeat("e\u{0301}", times: 121), \str_repeat('x', times: 121)] as $label) {
            $wrapped = SequenceText::parse($label, $enabled);
            $this->assertSame($label, \str_replace("\n", replace: '', subject: $wrapped));
            $this->assertCount(3, \explode("\n", $wrapped));
            foreach (\explode("\n", $wrapped) as $line) {
                $this->assertLessThanOrEqual(60, AnsiUtils::visibleWidth($line));
                $this->assertFalse(\str_starts_with($line, "\u{0301}"));
            }
        }
    }

    public function testParticipantAliasesNotesGroupsControlsAndNumberingWrapInTheScene(): void
    {
        $label = \trim(\str_repeat('word ', times: 20));
        $source =
            "sequenceDiagram\nbox "
            .$label
            ."\nparticipant A as wrap: "
            .$label
            ."\nparticipant B as nowrap: "
            .$label
            ."\nend\nautonumber\nloop wrap: "
            .$label
            ."\nA->>B: wrap: "
            .$label
            ."\nNote over A,B: nowrap: "
            .$label
            ."\nend\ncreate participant C as wrap: "
            .$label
            ."\nB->>C: Short";
        $diagram = self::parse($source, new MermaidConfiguration(sequenceWrap: true));
        $this->assertStringContainsString("\n", $diagram->participants['A']['label']);
        $this->assertSame($label, $diagram->participants['B']['label']);
        $this->assertStringContainsString("\n", $diagram->participants['C']['label']);
        $this->assertStringContainsString("\n", $diagram->groups[0]['label']);
        $this->assertStringContainsString("\n", $diagram->frames[0]['label']);
        $this->assertStringStartsWith('1. ', $diagram->events[1]['label']);
        $this->assertSame($label, $diagram->events[2]['label']);
        $this->assertSame(7, $diagram->events[1]['origin']);
        $work = new SequenceLayout(new MermaidConfiguration(mirrorActors: true))->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $headers = \array_values(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertCount(2, $headers);
        $this->assertSame($headers[0]->h, $headers[1]->h);
        $this->assertGreaterThan(6, $headers[0]->h);
        $this->assertSame(2, $scene->origin($headers[1]->id));
        $this->assertContains(7, $scene->origins);
    }

    public function testMetadataAliasesAndImplicitParticipantsAreDecodedOnlyOnce(): void
    {
        $long = \str_repeat('A', times: 61);
        $source =
            'sequenceDiagram; participant A@{"alias":"&amp;lt;'.\str_repeat('X', times: 61).'"}; create participant B@{"alias":"'.\str_repeat('Y', times: 61).'"}; A->>B: Hi; '.$long.'->>A: Hello';
        $diagram = self::parse($source, new MermaidConfiguration(sequenceWrap: true));
        $this->assertStringStartsWith('&lt;', $diagram->participants['A']['label']);
        foreach (['A', 'B', $long] as $actor) {
            $this->assertStringContainsString("\n", $diagram->participants[$actor]['label']);
        }
        $short = self::parse('sequenceDiagram; participant A@{"alias":"&amp;lt;"}; create participant B@{"alias":"&amp;lt;"}; A->>B: Hello');
        $this->assertSame('&lt;', $short->participants['A']['label']);
        $this->assertSame('&lt;', $short->participants['B']['label']);
    }

    public function testFrontMatterDirectiveOverridesRowsAndExports(): void
    {
        $label = \trim(\str_repeat('word ', times: 20));
        $front = "---\nconfig: {sequence: {wrap: true}}\n---\n";
        $body = "sequenceDiagram\nparticipant A as ".$label."\nA->>B: ".$label;
        $rows = NativeFixtureRows::render($front.$body, width: 70);
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
        $svg = NativeFixtureExport::render($front.$body, GraphExportFormat::Svg);
        $this->assertStringNotContainsString('>'.$label.'<', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($front.$body, GraphExportFormat::Png));
        $disabled = $front."%%{init: {sequence: {wrap: false}}}%%\n".$body;
        $this->assertStringContainsString('>'.$label.'<', NativeFixtureExport::render($disabled, GraphExportFormat::Svg));
        $metadata = MermaidFrontMatter::parse($front.$body);
        $work = MermaidInitialization::parse('%%{init: {sequence: {showSequenceNumbers: true}}}%%', $metadata->configuration);
        \iterator_to_array($work);
        $this->assertTrue($work->getReturn()->configuration->sequenceWrap);
        $this->assertTrue($work->getReturn()->configuration->sequenceNumbers);
        $this->expectException(MermaidSyntaxError::class);
        $metadata->configuration->validateFamily('pie');
    }

    public function testRootWrapPrecedesFamilyDefaultsAcrossEveryLabelPath(): void
    {
        $label = \str_repeat('x', times: 61);
        $id = \str_repeat('Z', times: 61);
        $source =
            'sequenceDiagram; box '
            .$label
            .'; participant A as '
            .$label
            .'; participant B@{"alias":"'
            .$label
            .'"}; end; create participant C@{"alias":"'
            .$label
            .'"}; A->>C: '
            .$label
            .'; '
            .$id
            .'->>B: nowrap: '
            .$label
            .'; Note over A: '
            .$label;
        $enabled = self::parse($source, new MermaidConfiguration(sequenceWrap: false, rootWrap: true));
        foreach (['A', 'B', 'C', $id] as $actor) {
            $this->assertStringContainsString("\n", $enabled->participants[$actor]['label']);
        }
        $this->assertStringContainsString("\n", $enabled->groups[0]['label']);
        $this->assertStringContainsString("\n", $enabled->events[1]['label']);
        $this->assertSame($label, $enabled->events[2]['label']);
        $this->assertStringContainsString("\n", $enabled->events[3]['label']);
        $disabled = self::parse($source, new MermaidConfiguration(sequenceWrap: true, rootWrap: false));
        foreach (['A', 'B', 'C'] as $actor) {
            $this->assertSame($label, $disabled->participants[$actor]['label']);
        }
        $this->assertSame($id, $disabled->participants[$id]['label']);
        $this->assertSame($label, $disabled->groups[0]['label']);
        $this->assertSame($label, $disabled->events[1]['label']);
        $this->assertSame($label, $disabled->events[3]['label']);
        $this->assertStringContainsString("\n", SequenceText::parse('wrap: '.$label, new MermaidConfiguration(rootWrap: false)));
        $this->assertSame($label, SequenceText::parse('nowrap: '.$label, new MermaidConfiguration(rootWrap: true)));
        $this->assertNull(new MermaidConfiguration()->wrapping());
    }

    public function testRootAndFamilyOptionsMergeIndependentlyAcrossDirectives(): void
    {
        $front = MermaidFrontMatter::parse("---\nconfig: {wrap: true, sequence: {wrap: false}}\n---\nsequenceDiagram");
        $work = MermaidInitialization::parse('%%{init: {sequence: {wrap: true}}}%%', $front->configuration);
        \iterator_to_array($work);
        $first = $work->getReturn()->configuration;
        $this->assertTrue($first->rootWrap);
        $this->assertTrue($first->sequenceWrap);
        $work = MermaidInitialization::parse('%%{initialize: {wrap: false}}%%%%{init: {sequence: {wrap: true}}}%%%%{init: {}}%%', $first);
        \iterator_to_array($work);
        $last = $work->getReturn()->configuration;
        $this->assertFalse($last->rootWrap);
        $this->assertTrue($last->sequenceWrap);
        $this->assertFalse($last->wrapping());
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply to this diagram family');
        new MermaidConfiguration(rootWrap: false)->validateFamily('pie');
    }

    public function testRootWrappingFlowsThroughNativeRowsAndExports(): void
    {
        $label = \str_repeat('x', times: 61);
        $body = "sequenceDiagram\nparticipant A as ".$label."\nA->>B: ".$label;
        $front = "---\nconfig: {wrap: true, sequence: {wrap: false}}\n---\n";
        $rows = NativeFixtureRows::render($front.$body, width: 70);
        $this->assertContains(4, $rows->origins);
        $this->assertContains(5, $rows->origins);
        $svg = NativeFixtureExport::render($front.$body, GraphExportFormat::Svg);
        $this->assertStringNotContainsString('>'.$label.'<', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($front.$body, GraphExportFormat::Png));
        $override = $front."%%{init: {wrap: false, sequence: {wrap: true}}}%%\n".$body;
        $this->assertStringContainsString('>'.$label.'<', NativeFixtureExport::render($override, GraphExportFormat::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRootWrap(): iterable
    {
        yield 'null' => ['null'];
        yield 'string' => ['"false"'];
        yield 'integer' => ['1'];
        yield 'mapping' => ['{}'];
        yield 'list' => ['[]'];
    }

    #[DataProvider('invalidRootWrap')]
    public function testRootWrappingRejectsNonBooleanValues(string $value): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Line 3, column 1: Mermaid wrap must be boolean');
        $work = MermaidInitialization::parse("%% comment\n\n%%{init: {wrap: ".$value.'}}%%');
        \iterator_to_array($work);
    }

    public function testWrappingRejectsNonBooleanConfiguration(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Sequence wrap must be boolean');
        MermaidFrontMatter::parse("---\nconfig: {sequence: {wrap: 1}}\n---\nsequenceDiagram");
    }
}
