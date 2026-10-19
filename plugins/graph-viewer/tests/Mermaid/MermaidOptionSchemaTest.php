<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\ArchitectureConfiguration;
use Porta\GraphViewer\Mermaid\FlowchartConfiguration;
use Porta\GraphViewer\Mermaid\GitConfiguration;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidOptions;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\PieConfiguration;

/** Every pinned option is accepted with its own kind; a wrong kind is a located error, an unknown key ignored, as pinned. */
final class MermaidOptionSchemaTest extends TestCase
{
    public function testPinnedOptionsOfEveryKindAreAccepted(): void
    {
        $configuration = self::parse([
            'fontSize' => 12,
            'securityLevel' => 'loose',
            'gantt' => ['axisFormat' => '%d', 'barHeight' => 30],
            'journey' => ['actorColours' => ['red']],
            'sankey' => ['nodeColors' => ['A' => 'red']],
            'xyChart' => ['xAxis' => ['showLabel' => false]],
            'c4' => ['personFontSize' => '14px', 'systemFontSize' => 14],
            // Keys a family parser leaves to the schema pass through untouched.
            'pie' => ['useMaxWidth' => true, 'textPosition' => 0.5],
            'architecture' => ['padding' => 10],
            'gitGraph' => ['titleTopMargin' => 5],
            'flowchart' => ['wrappingWidth' => 100],
        ]);
        $this->assertCount(1, $configuration->inits, 'the raw directive is kept for MermaidOptions');
        $this->assertInstanceOf(PieConfiguration::class, $configuration->pie);
        $this->assertInstanceOf(ArchitectureConfiguration::class, $configuration->architecture);
        $this->assertInstanceOf(GitConfiguration::class, $configuration->gitGraph);
        $this->assertInstanceOf(FlowchartConfiguration::class, $configuration->flowchart);
        $this->assertFalse($configuration->flowchart->configured(), 'wrappingWidth is no flowchart option the parser reads');
    }

    public function testUnknownKeysAreIgnoredAndNumericTextCountsAsANumber(): void
    {
        // Pinned configuration is a plain object: keys the build does not define change nothing.
        $configuration = self::parse([
            'loglevel' => 1,
            'renderer' => 'x',
            'gantt' => ['nope' => 1],
            'xyChart' => ['xAxis' => ['nope' => true, 'labelFontSize' => '20']],
            'flowchart' => ['rankSpacing' => '100'],
        ]);
        $this->assertSame(10.0, $configuration->flowchart?->spacing('rankSpacing', default: 0.0), 'JavaScript arithmetic reads `"100"` as 100 pixels, ten units');
        $this->assertSame([100, 1.5, 'fifty', true], [MermaidOptions::numeric('100'), MermaidOptions::numeric(' 1.5 '), MermaidOptions::numeric('fifty'), MermaidOptions::numeric(true)]);
    }

    #[DataProvider('invalid')]
    public function testWrongKindsAreLocated(array $config, string $message): void
    {
        try {
            self::parse($config);
            $this->fail('Accepted '.\json_encode($config, \JSON_THROW_ON_ERROR));
        } catch (MermaidSyntaxError $error) {
            $this->assertSame(3, $error->sourceLine);
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'root kind' => [['fontSize' => true], 'fontSize must be a number'];
        yield 'section' => [['gantt' => 3], 'Mermaid configuration sections must be mappings'];
        yield 'boolean' => [['gantt' => ['topAxis' => 'yes']], 'gantt.topAxis must be true or false'];
        yield 'number' => [['gantt' => ['barHeight' => 'tall']], 'gantt.barHeight must be a number'];
        yield 'size' => [['c4' => ['personFontSize' => true]], 'c4.personFontSize must be a number or text'];
        yield 'text' => [['gantt' => ['axisFormat' => 3]], 'gantt.axisFormat must be text'];
        yield 'list' => [['journey' => ['actorColours' => 'red']], 'journey.actorColours must be a list'];
        yield 'map' => [['sankey' => ['nodeColors' => 'red']], 'sankey.nodeColors must be a mapping'];
        yield 'enum' => [['gantt' => ['displayMode' => 'tight']], 'gantt.displayMode must be one of "", "compact"'];
        yield 'nested object' => [['xyChart' => ['xAxis' => 5]], 'xyChart.xAxis must be a mapping'];
    }

    public function testOptionsFallBackToThePinnedDefaults(): void
    {
        $options = MermaidOptions::defaults();
        $this->assertSame('%Y-%m-%d', $options->text('gantt', 'axisFormat'));
        $this->assertTrue($options->flag('sankey', 'showValues'));
        $this->assertSame(5.0, $options->number('quadrantChart', 'pointRadius'));
        $this->assertSame(7.0, $options->number('gantt', 'noSuchOption', fallback: 7.0));
        $this->assertSame('', $options->text('gantt', 'barHeight'));
        $this->assertTrue($options->nested('xyChart', 'xAxis', 'showLabel'));
        $this->assertFalse($options->nested('xyChart', 'xAxis', 'noSuchKey'));
        $this->assertFalse(new MermaidOptions()->flag('sankey', 'showValues'), 'without a schema nothing defaults');
    }

    /** @param array<string, mixed> $config */
    private static function parse(array $config): MermaidConfiguration
    {
        $object = \json_decode(\json_encode($config, \JSON_THROW_ON_ERROR), associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $object);

        return MermaidConfiguration::parse($object, new MermaidStatement('', offset: 0, line: 3, column: 0));
    }
}
