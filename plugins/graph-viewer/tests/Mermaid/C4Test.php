<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\C4Diagram;
use Janalis\Viz\Mermaid\C4Layout;
use Janalis\Viz\Mermaid\C4Lexer;
use Janalis\Viz\Mermaid\C4Parser;
use Janalis\Viz\Mermaid\MermaidOutline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class C4Test extends TestCase
{
    private static function parse(string $source): C4Diagram
    {
        $parser = new C4Parser();
        foreach (new C4Lexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $layout = new C4Layout()->layout(self::parse($source));
        \iterator_to_array($layout);

        return $layout->getReturn();
    }

    public function testVerticalCellRelationsFollowTheirActualEndpoints(): void
    {
        foreach (['Rel' => '▾', 'Rel_Back' => '▴'] as $macro => $glyph) {
            $source = "C4Context\nUpdateLayoutConfig(1,1)\nSystem(a,\"A\")\nSystem(b,\"B\")\n".$macro.'(a,b,"Calls")';
            $rows = NativeFixtureRows::render($source, width: 80);
            $this->assertStringContainsString($glyph, \implode("\n", $rows->rows));
        }
    }

    public function testPersonQueueDatabaseAndStyleOverridesHaveDistinctOutlines(): void
    {
        $source = <<<'MMD'
            C4Component
            Person(person,"User")
            ContainerQueue(queue,"Queue","AMQP")
            ComponentDb(db,"Database","SQL")
            System(custom,"Custom")
            UpdateElementStyle(person,$bgColor="purple",$fontColor="white",$borderColor="red")
            UpdateElementStyle(custom,$shape="circle")
            MMD;
        $scene = self::scene($source);
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        $this->assertSame(['c4-person', 'c4-queue', 'cylinder', 'circle'], \array_values($scene->shapes));
        $this->assertSame(['#800080', '#ffffff', '#ff0000'], [$nodes['person']->fillColor, $nodes['person']->textColor, $nodes['person']->color]);
        $this->assertTrue(MermaidOutline::contains($nodes['person'], 'c4-person', $nodes['person']->x, $nodes['person']->y - ($nodes['person']->h / 2) + 2));
        $this->assertCount(2, MermaidOutline::marks($nodes['person'], 'c4-person'));
        $this->assertCount(3, MermaidOutline::marks($nodes['queue'], 'c4-queue'));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('#800080', $svg);
        $this->assertStringContainsString('Database', $svg);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 20)->rows);
    }

    public function testAccessibilityTitleOverridesFrontMatterAndBodyMetadataKeepsLocations(): void
    {
        $source = "---\ntitle: Outer\n---\nC4Context\naccTitle: Accessible\nSystem(a,\"A\")";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('Accessible', $svg);
        $this->assertStringNotContainsString('Outer', $svg);
        $rows = NativeFixtureRows::render($source, width: 40);
        $this->assertContains(5, $rows->origins);
        $this->assertStringContainsString('Only title', NativeFixtureExport::render("C4Context\ntitle Only title", Format::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function pinned(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/c4/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            yield \basename($file) => [(string) \file_get_contents($file)];
        }
    }

    #[DataProvider('pinned')]
    public function testPinnedC4ExamplesRenderToCellsAndSvg(string $source): void
    {
        $this->assertNotEmpty(self::parse($source)->nodes);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, Format::Svg));
    }
}
