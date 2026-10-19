<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\SequenceConfiguration;
use Porta\GraphViewer\Mermaid\SequenceText;
use Porta\GraphViewer\Mermaid\SequenceYamlSchema;
use Symfony\Component\Yaml\Yaml;

final class SequenceScalarFallbackTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testScalarFallbacksPreserveVisibleStringsAndKeywordTypes(string $configuration, ?string $alias, ?string $type): void
    {
        $source = "sequenceDiagram\nactor A@".$configuration."\nA->>B: after";
        $scene = NativeFixtureScene::render($source);
        $this->assertSame(['A', $alias, $type], SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 0, line: 0, column: 0)));
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $node);
        $this->assertSame($alias ?? 'A', $node->label);
        $this->assertSame(1, $scene->origin($node->id));
        $this->assertContains('A: '.($alias ?? 'A').' [actor]', \array_column($scene->legend, 'text'));
    }

    public static function accepted(): iterable
    {
        foreach (self::reference() as $index => $case) {
            if (\array_key_exists('error', $case)) {
                continue;
            }
            yield (string) $index => [$case['configuration'], $case['alias'], $case['type']];
        }
    }

    #[DataProvider('rejected')]
    public function testInvalidExplicitScalarValuesRemainLocatedErrors(string $configuration): void
    {
        try {
            SequenceConfiguration::parse('A@'.$configuration, new MermaidStatement('', offset: 0, line: 1, column: 2));
            $this->fail('Invalid explicit scalar value accepted');
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
            yield [$case['configuration']];
        }
    }

    private static function reference(): array
    {
        return \json_decode(\file_get_contents(__DIR__.'/../fixtures/mermaid/sequence-scalar-fallbacks.json'), associative: true, flags: \JSON_THROW_ON_ERROR)['cases'];
    }

    public function testACommentOnlyValueWithoutAFinalNewlineRemainsNull(): void
    {
        $yaml = SequenceYamlSchema::normalize('alias: # comment', new MermaidStatement('', offset: 0, line: 0, column: 0));
        $this->assertSame(['alias' => null], Yaml::parse($yaml));
    }

    public function testUnlabeledRedeclarationsRetainDescriptionsAndTypes(): void
    {
        $source = "sequenceDiagram\nactor A as Name\nparticipant A@{type: database, alias: false}\nA->>B: after";
        $scene = NativeFixtureScene::render($source);
        $this->assertContains('A: Name [actor]', \array_column($scene->legend, 'text'));
        $replacement = NativeFixtureScene::render($source."\nparticipant A@{type: null, alias: \"0\"}");
        $this->assertContains('A: 0', \array_column($replacement->legend, 'text'));
        $this->assertNotContains('A: 0 [actor]', \array_column($replacement->legend, 'text'));
    }

    public function testCreationAndRedeclarationUseNullTypeFallbackAndExplicitOverrides(): void
    {
        $source = "sequenceDiagram\nparticipant A\ncreate actor B@{type: null, alias: false}\nA->>B: create\nactor A@{type: database} as Renamed\nparticipant B as Reset\nA->>B: after";
        $scene = NativeFixtureScene::render($source);
        $text = \array_column($scene->legend, 'text');
        $this->assertContains('A: Renamed [database]', $text);
        $this->assertContains('B: Reset', $text);
        $this->assertNotContains('B: Reset [actor]', $text);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>Reset</text>', $svg);
        $this->assertStringContainsString('>after</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
    }

    #[DataProvider('descriptions')]
    public function testConfiguredAliasesUseThePinnedDescriptionPrecedence(string $description, string $expected): void
    {
        foreach (['actor A@{alias: Configured}', "participant B\ncreate actor A@{alias: Configured}"] as $declaration) {
            $source = "sequenceDiagram\n".$declaration.$description."\nB->>A: after";
            $scene = NativeFixtureScene::render($source);
            $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name);
            $this->assertInstanceOf(GraphNode::class, $node);
            $this->assertSame($expected, $node->label);
            $this->assertContains('A: '.$expected.' [actor]', \array_column($scene->legend, 'text'));
            $this->assertStringContainsString('>'.$expected.'</text>', NativeFixtureExport::render($source, GraphExportFormat::Svg));
        }
    }

    public static function descriptions(): iterable
    {
        yield 'no description' => ['', 'Configured'];
        yield 'same raw name' => [' as A', 'Configured'];
        yield 'different explicit name' => [' as Named', 'Named'];
        yield 'entity equal only after decoding' => [' as #65;', 'A'];
        yield 'wrapped name' => [' as wrap: A', 'Configured'];
        yield 'unwrapped name' => [' as nowrap: A', 'Configured'];
    }

    public function testAMetadataAliasInheritsExplicitDescriptionWrapping(): void
    {
        $alias = \str_repeat('word ', times: 25).'end';
        $configuration = new MermaidConfiguration();
        $this->assertSame($alias, SequenceText::participant('A', $alias, 'nowrap: A', $configuration));
        $this->assertStringContainsString("\n", SequenceText::participant('A', $alias, 'wrap: A', $configuration) ?? '');
        $enabled = new MermaidConfiguration(sequenceWrap: true);
        $this->assertSame($alias, SequenceText::participant('A', $alias, 'nowrap: A', $enabled));
        $this->assertStringContainsString("\n", SequenceText::participant('A', $alias, null, $enabled) ?? '');
        $this->assertNull(SequenceText::participant('A', null, null, $configuration));
        $this->assertSame('Explicit', SequenceText::participant('A', null, 'Explicit', $configuration));
    }
}
