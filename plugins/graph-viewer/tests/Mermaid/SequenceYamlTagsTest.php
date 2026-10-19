<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceConfiguration;

final class SequenceYamlTagsTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testJsonSchemaTagsPreserveStringsTypesAndSourceOrigins(string $configuration, ?string $alias, ?string $type): void
    {
        $statement = new MermaidStatement('', offset: 17, line: 1, column: 2);
        $source = "sequenceDiagram\n  participant A@".$configuration."\nA->>B: after";
        try {
            $configurationResult = SequenceConfiguration::parse('A@'.$configuration, $statement);
        } catch (\Throwable $error) {
            $this->fail('A supported JSON-schema configuration was rejected: '.$error->getMessage());
        }
        $this->assertSame(['A', $alias, $type], $configurationResult);
        $scene = NativeFixtureScene::render($source);
        $actor = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $actor);
        $this->assertSame($alias ?? 'A', $actor->label);
        $this->assertSame(1, $scene->origin($actor->id));
    }

    public static function accepted(): iterable
    {
        foreach (self::reference() as $case) {
            if (\array_key_exists('error', $case)) {
                continue;
            }
            yield $case['name'] => [$case['configuration'], $case['alias'], $case['type']];
        }
    }

    #[DataProvider('rejected')]
    public function testUnsupportedAndMalformedTagsHaveLocatedErrors(string $configuration): void
    {
        try {
            SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 17, line: 1, column: 2));
            $this->fail('A tag outside the pinned JSON schema was accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame(1, $error->sourceLine);
            $this->assertSame(2, $error->column);
            $this->assertStringContainsString('Invalid sequence participant YAML configuration', $error->getMessage());
        }
    }

    public static function rejected(): iterable
    {
        foreach (self::reference() as $case) {
            if (!\array_key_exists('error', $case)) {
                continue;
            }
            yield $case['name'] => [$case['configuration']];
        }
    }

    #[DataProvider('invalidUtf8Keys')]
    public function testInvalidUtf8KeysHaveLocatedErrors(string $configuration): void
    {
        $statement = new MermaidStatement('', offset: 17, line: 1, column: 2);
        try {
            SequenceConfiguration::parse('A@'.$configuration, $statement);
            $this->fail('An invalid UTF-8 key was accepted');
        } catch (\Throwable $error) {
            $this->assertInstanceOf(MermaidSyntaxError::class, $error);
            $this->assertSame(1, $error->sourceLine);
            $this->assertSame(2, $error->column);
            $this->assertStringContainsString('Invalid sequence participant YAML configuration', $error->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUtf8Keys(): iterable
    {
        yield 'block key' => ["{\n\xFF: Value\nalias: Name\n}"];
        yield 'flow key' => ["{\"\xFF\": Value, alias: Name}"];
        yield 'alias key' => ["{\nignored: &key \"\xFF\"\n*key : Value\nalias: Name\n}"];
    }

    private static function reference(): array
    {
        $cases = [];
        foreach ([
            'sequence-yaml-tags-reference.json',
            'sequence-yaml-directives-reference.json',
            'sequence-yaml-collections-reference.json',
            'sequence-yaml-cyclic-reference.json',
            'sequence-yaml-keys-reference.json',
            'sequence-yaml-flow-keys-reference.json',
            'sequence-yaml-array-keys-reference.json',
            'sequence-yaml-complex-flow-keys-reference.json',
            'sequence-yaml-property-indents-reference.json',
            'sequence-yaml-block-keys-reference.json',
            'sequence-yaml-root-indents-reference.json',
            'sequence-yaml-block-primitive-keys-reference.json',
            'sequence-yaml-root-values-reference.json',
            'sequence-yaml-surrogate-keys-reference.json',
        ] as $fixture) {
            foreach (\json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/'.$fixture), associative: true, flags: \JSON_THROW_ON_ERROR)['cases'] as $case) {
                $case['name'] = $fixture.':'.$case['name'];
                $cases[] = $case;
            }
        }

        return $cases;
    }

    public function testEncodedTagNamesAndLaterKeysReachNativeExports(): void
    {
        $source = "sequenceDiagram\nparticipant A@{alias: !!%73tr First, type: !<tag:yaml.org,2002:%73tr> actor}\nparticipant B@{\n  type: actor\n  !!%73tr alias: Second\n}\nA->>B: after";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>First</text>', $svg);
        $this->assertStringContainsString('>Second</text>', $svg);
    }

    public function testPlainScalarTagSpellingsStayTextAfterPunctuationAndContinuation(): void
    {
        $source = "sequenceDiagram\nparticipant A@{\n  alias: before, !!binary aGk=\n}\nparticipant B@{\n  alias: service:!!binary aGk=\n}\nparticipant C@{\n  alias: before\n    !!binary aGk=\n}\nparticipant D@{\n  alias: before [ !!binary aGk= ]\n}\nA->>B: after";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('before, !!binary aGk=', $svg);
        $this->assertStringContainsString('service:!!binary aGk=', $svg);
        $this->assertStringContainsString('before !!binary aGk=', $svg);
        $this->assertStringContainsString('before [ !!binary aGk= ]', $svg);
    }

    public function testTaggedQuotedAndBlockAliasesReachNativeExportsWithoutLosingPunctuation(): void
    {
        $source = "sequenceDiagram\nparticipant A@{alias: !!str &a \"A } [; # literal\"}\nparticipant B@{\n  alias: !<tag:yaml.org,2002:str> |-\n    !!binary aGk=\n    alias: 2024-01-01\n}\nA->>B: after";
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('A } [; # literal', $svg);
        $this->assertStringContainsString('!!binary aGk=', $svg);
        $this->assertStringContainsString('alias: 2024-01-01', $svg);
        $this->assertStringContainsString('>after</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }
}
