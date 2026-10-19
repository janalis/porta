<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\ClassDiagram;
use Janalis\Viz\Mermaid\ClassLayout;
use Janalis\Viz\Mermaid\ClassLexer;
use Janalis\Viz\Mermaid\ClassParser;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassTest extends TestCase
{
    private static function parse(string $source): ClassDiagram
    {
        $parser = new ClassParser();
        foreach (new ClassLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testGenericsLiteralNamesCardinalitiesAndAllEndpointKinds(): void
    {
        $source = <<<'MMD'
            classDiagram
            class List~Item~ {}
            class `User service`
            List~Item~ "1" *-- "many" `User service` : owns
            A o..> B
            A ()--() C
            A <..|> D
            A -- A
            X
            link X "https://example.com"
            callback X "inspect"
            MMD;
        $diagram = self::parse($source);
        $this->assertArrayHasKey('List', $diagram->graph->nodes);
        $this->assertArrayHasKey('User service', $diagram->graph->nodes);
        $this->assertSame('1 owns many', $diagram->graph->edges[0]['label']);
        $this->assertSame('-.-', $diagram->graph->edges[1]['arrow']);
        $work = new ClassLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $this->assertCount(12, $scene->graph->edges);
        $this->assertCount(7, $scene->elements);
        $this->assertSame('er-card:1', $scene->shapes[0], 'a class without members keeps the pinned empty box');
        $this->assertNotNull($scene->graph->edges[5]->ribbon);
        $this->assertTrue($scene->graph->edges[1]->dashed);
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertStringContainsString('owns', \implode("\n", $rows->rows));
        $this->assertStringContainsString('User service', NativeFixtureExport::render($source, Format::Svg));
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
    }

    /** @return iterable<string, array{string}> */
    public static function upstream(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/class/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [(string) \file_get_contents($file)];
        }
    }

    #[DataProvider('upstream')]
    public function testPinnedClassCorpusRendersWithoutCli(string $source): void
    {
        $diagram = self::parse($source);
        $this->assertNotEmpty($diagram->graph->nodes);
        $rows = NativeFixtureRows::render($source, width: 40);
        $this->assertNotEmpty($rows->rows);
        $this->assertCount(\count($rows->rows), $rows->origins);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }
}
