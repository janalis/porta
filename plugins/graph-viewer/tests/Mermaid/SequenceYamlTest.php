<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidLexer;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceConfiguration;
use Porta\GraphViewer\Mermaid\SequenceDiagram;
use Porta\GraphViewer\Mermaid\SequenceLayout;
use Porta\GraphViewer\Mermaid\SequenceParser;
use Porta\GraphViewer\Mermaid\SequenceYamlSchema;

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

    /** @return iterable<string, array{string, string}> */
    public static function scalars(): iterable
    {
        yield 'date' => ['{alias: 2026-10-17}', '2026-10-17'];
        yield 'timestamp' => ['{alias: 2001-12-15T02:59:43.1200Z}', '2001-12-15T02:59:43.1200Z'];
        yield 'timezone' => ['{alias: 2001-12-15 2:59:43.10 -05:30}', '2001-12-15 2:59:43.10 -05:30'];
        yield 'invalid calendar date' => ['{alias: 2026-99-99}', '2026-99-99'];
        yield 'quoted key' => ['{"alias":2026-10-17}', '2026-10-17'];
        yield 'single quoted key' => ["{'alias':2026-10-17}", '2026-10-17'];
        yield 'date anchor' => ['{alias: &date 2026-10-17}', '2026-10-17'];
        yield 'date block map' => ["{\n  alias: 2026-10-17 # keep date\n  type: actor\n}", '2026-10-17'];
        yield 'anchored literal date' => ["{\n  alias: &text |-\n    alias: 2026-10-17\n}", 'alias: 2026-10-17'];
        yield 'anchored folded date' => ["{\n  alias: &text >-\n    alias: 2026-10-17\n}", 'alias: 2026-10-17'];
        yield 'anchored literal punctuation' => ["{\n  alias: &text |-\n    \"unclosed } [ #literal\n    alias: 2026-10-17\n}", "\"unclosed } [ #literal\nalias: 2026-10-17"];
        yield 'date literal block' => ["{\n  alias: |-\n    alias: 2026-10-17\n}", 'alias: 2026-10-17'];
        yield 'date folded block' => ["{\n  alias: >-\n    alias: 2026-10-17\n}", 'alias: 2026-10-17'];
        yield 'date in quoted text' => ['{alias: "alias: 2026-10-17"}', 'alias: 2026-10-17'];
        yield 'explicit string date' => ['{alias: !!str 2026-10-17}', '2026-10-17'];
        yield 'punctuation' => ['{alias: A (B) > C}', 'A (B) > C'];
        yield 'plain apostrophe' => ["{alias: Don't stop}", "Don't stop"];
        yield 'backslash single' => ["{alias: 'C:\\'}", 'C:\\'];
        yield 'single double quote' => ["{alias: 'say \"hi\"'}", 'say "hi"'];
        yield 'empty' => ["{alias: ''}", 'A'];
        yield 'string zero' => ["{alias: '0'}", '0'];
        yield 'boolean-like string' => ['{alias: no}', 'no'];
        yield 'unicode and entity' => ["{alias: '用户 #35;'}", '用户 #'];
        yield 'hash without whitespace' => ['{alias: service#tag}', 'service#tag'];
        yield 'literal punctuation' => ["{\n  alias: |-\n    \"unclosed } [ #literal\n\n    tail\n}", "\"unclosed } [ #literal\n\ntail"];
        yield 'literal block' => ["{\n  alias: |-\n    first\n    second\n}", "first\nsecond"];
    }

    #[DataProvider('scalars')]
    public function testYamlStringScalars(string $configuration, string $label): void
    {
        $diagram = self::parse('sequenceDiagram; participant A@'.$configuration."\nA->>B: after");
        $this->assertSame($label, $diagram->participants['A']['label']);
        $this->assertSame('after', $diagram->events[0]['label']);
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
        $this->assertStringContainsString('queue', NativeFixtureExport::render($source, GraphExportFormat::Svg));
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
        $this->assertStringContainsString('2001-12-15T02:59:43.1200Z', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    public function testReferencesAreScopedToOneParticipantDeclaration(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML');
        self::parse("sequenceDiagram\nparticipant A@{alias: &label First}\nparticipant B@{alias: *label}");
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'number' => ['{alias: 12}', 'Unsupported sequence participant configuration field'];
        yield 'boolean' => ['{alias: true}', 'Unsupported sequence participant configuration field'];
        yield 'array' => ['{alias: [one, two]}', 'Unsupported sequence participant configuration field'];
        yield 'collection reference label' => ['{ignored: &items [one], alias: *items}', 'Unsupported sequence participant configuration field'];
        yield 'empty tagged sequence' => ['{alias: !!seq }', 'Unsupported sequence participant configuration field'];
        yield 'empty tagged mapping' => ['{alias: !!map }', 'Unsupported sequence participant configuration field'];
        yield 'timestamp type' => ['{type: 2026-10-17}', 'Unsupported sequence participant type'];
        yield 'explicit timestamp' => ['{alias: !!timestamp 2026-10-17}', 'Invalid sequence participant YAML'];
        yield 'custom date tag' => ['{alias: !date 2026-10-17}', 'Invalid sequence participant YAML'];
        yield 'type' => ['{type: service}', 'Unsupported sequence participant type'];
        yield 'duplicate' => ['{alias: First, alias: Second}', 'Invalid sequence participant YAML'];
        yield 'alias' => ['{alias: *missing}', 'Invalid sequence participant YAML'];
        yield 'collection alias' => ['{type: &kind [boundary], alias: *kind}', 'Unsupported sequence participant configuration field'];
        yield 'mapping alias' => ['{type: &kind {alias: boundary}, alias: *kind}', 'Unsupported sequence participant configuration field'];
        yield 'recursive alias' => ['{alias: &self [*self]}', 'Unsupported sequence participant configuration field'];
        yield 'non-string alias' => ['{type: &kind 12, alias: *kind}', 'Unsupported sequence participant configuration field'];
        yield 'object tag' => ["{alias: !php/object 'O:8:\"stdClass\":0:{}'}", 'Invalid sequence participant YAML'];
        yield 'constant' => ['{alias: !php/const PHP_VERSION}', 'Invalid sequence participant YAML'];
        yield 'malformed' => ['{type: Name: Value}', 'Invalid sequence participant YAML'];
    }

    #[DataProvider('invalid')]
    public function testUnsupportedValuesProduceLocatedErrors(string $configuration, string $message): void
    {
        try {
            self::parse("sequenceDiagram\n  participant A@".$configuration);
            $this->fail('Invalid YAML accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame(1, $error->sourceLine);
            $this->assertSame(2, $error->column);
        }
    }

    public function testLoneSurrogateAliasKeysRetainParticipantLabelsAndOrigins(): void
    {
        $diagram = self::parse(<<<'MMD'
            sequenceDiagram
              participant A@{
            ignored: &key "\uD800"
            *key : Value
            alias: Name
            }
            MMD);
        $this->assertSame(['label' => 'Name', 'origin' => 1], $diagram->participants['A']);
    }

    public function testLoneSurrogateLiteralKeysDoNotReplaceTheParticipantId(): void
    {
        $this->assertSame(
            ['A', null, null],
            SequenceConfiguration::parse('A@{"\uD800": Value}', new MermaidStatement('', offset: 0, line: 0, column: 0)),
        );
    }

    public function testAnEmptyListItemAnchorDoesNotCaptureTheFollowingItem(): void
    {
        $diagram = self::parse(<<<'MMD'
            sequenceDiagram
            actor A@{
            ignored:
             - &text
             - alias
            alias: *text
            }
            A->>B: after
            MMD);
        $this->assertSame('A', $diagram->participants['A']['label']);
        $this->assertSame('actor', $diagram->participantTypes['A']);
    }

    public function testConfigurationRequiresBracesAndMapping(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        foreach (['A@type: boundary', 'A@{alias: x', 'A@alias: x}'] as $text) {
            try {
                SequenceConfiguration::parse($text, $statement);
                $this->fail('Unwrapped configuration accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertStringContainsString('Invalid sequence participant YAML', $error->getMessage());
            }
        }
    }

    #[DataProvider('collectionGraphs')]
    public function testCollectionAliasLimitIsEnforcedWithoutExpandingIgnoredGraphs(string $template, string $entry = '*data'): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 1, column: 2);
        $body = \sprintf($template, \implode(', ', \array_fill(0, count: 128, value: $entry)));
        $this->assertSame(['A', 'Name', null], SequenceConfiguration::parse('A@{'.$body.'}', $statement));
        try {
            SequenceConfiguration::parse('A@{'.\str_replace('], alias:', replace: ', *data], alias:', subject: $body).'}', $statement);
            $this->fail('The collection-alias limit was bypassed');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Invalid sequence participant YAML configuration', $error->getMessage());
            $this->assertSame(1, $error->sourceLine);
            $this->assertSame(2, $error->column);
        }
    }

    public static function collectionGraphs(): iterable
    {
        yield 'shared collection' => ['data: &data [one, two], copies: [%s], alias: Name'];
        yield 'authored key anchor with generated reference' => ['&data [ignored]: Value, copies: [%s], alias: Name'];
        yield 'authored comments cannot mark generated references' => [
            "\n{&data [ignored]: Value, copies: [%s], alias: Name}\n",
            "*data #__porta_key_0__\n",
        ];
        yield 'block key anchor with generated reference' => ["\n? &data [ignored]\n: Value\ntail: {copies: [%s], alias: ignored}\nalias: Name\n"];
        yield 'block authored comments cannot mark generated references' => [
            "\n? &data [ignored]\n: Value\ntail: {copies: [%s], alias: ignored}\nalias: Name\n",
            "*data #__porta_block_key_0__\n",
        ];
        yield 'cyclic collection' => ['data: &data [%s], alias: Name'];
        yield 'collection mapping keys' => ['data: &data {one: value}, copies: [%s], alias: Name', '{*data : Value}'];
    }

    public function testDuplicateAnchorHeadersAreRejectedBeforeReferenceProjection(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        SequenceYamlSchema::normalize(
            \str_repeat('&anchor ', times: 4000)."{alias: Name, copy: *anchor}\n",
            new MermaidStatement('', offset: 0, line: 1, column: 2),
        );
    }

    #[DataProvider('keyBudgets')]
    public function testAliasedKeysHaveABoundedExpansionBudget(int $tabs, int $acceptedLong, int $acceptedEmpty, int $rejectedLong, int $rejectedEmpty): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 1, column: 2);
        $accepted = self::expandedKeyMap($tabs, $acceptedLong, $acceptedEmpty);
        $rejected = self::expandedKeyMap($tabs, $rejectedLong, $rejectedEmpty);
        $this->assertLessThanOrEqual(65_536, \strlen($accepted));
        $this->assertLessThanOrEqual(65_536, \strlen($rejected));
        $this->assertSame(['A', 'Name', null], SequenceConfiguration::parse($accepted, $statement));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Invalid sequence participant YAML configuration');
        SequenceConfiguration::parse($rejected, $statement);
    }

    public static function keyBudgets(): iterable
    {
        yield 'exactly 256 KiB' => [32_766, 4, 0, 5, 0];
        yield 'one byte over 256 KiB' => [32_765, 4, 2, 4, 3];
    }

    private static function expandedKeyMap(int $tabs, int $large, int $empty): string
    {
        $body = "A@{\nlong: &long \"x".\str_repeat("\t", $tabs)."\"\nempty: &empty !!str\nmaps:\n";
        foreach (['long' => $large, 'empty' => $empty] as $name => $count) {
            for ($index = 0; $index < $count; ++$index) {
                $body .= '  '.$name.$index.":\n    *".$name." : Value\n";
            }
        }

        return $body."alias: Name\n}";
    }

    public function testIgnoredAliasGraphsStayOutOfNativeScenesAndExports(): void
    {
        $body = "a: &a [one, two]\n";
        $previous = 'a';
        for ($index = 0; $index < 15; ++$index) {
            $name = 'k'.$index;
            $body .= $name.': &'.$name.' ['.\implode(', ', \array_fill(0, count: 8, value: '*'.$previous))."]\n";
            $previous = $name;
        }
        $source = "sequenceDiagram\nparticipant A@{\n".$body."alias: Name\n}\nA->>B: after";
        $scene = NativeFixtureScene::render($source);
        $this->assertContains('A: Name', \array_column($scene->legend, 'text'));
        $this->assertCount(5, $scene->graph->nodes, 'two headers, their mirrored footers and the message');
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>Name</text>', $svg);
        $this->assertStringNotContainsString('>one</text>', $svg);
        $this->assertStringNotContainsString('porta_merge', $svg);
    }
}
