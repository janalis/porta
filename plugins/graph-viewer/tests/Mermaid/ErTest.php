<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\ErDiagram;
use Janalis\Viz\Mermaid\ErLayout;
use Janalis\Viz\Mermaid\ErLexer;
use Janalis\Viz\Mermaid\ErParser;
use Janalis\Viz\Mermaid\MermaidDrawing;
use Janalis\Viz\Mermaid\MermaidMark;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;

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

    public function testLongFieldsWrapAndNativeExportsEscapeMarkup(): void
    {
        $source = "erDiagram\nA[\"<script>&amp;\"] { string name \"".\str_repeat('日本', times: 80)."\" }\nA ||..o{ B : owns";
        $scene = self::scene($source);
        $this->assertStringContainsString("\n", $scene->graph->nodes[0]->label);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
        $png = NativeFixtureExport::render($source, Format::Png);
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

    public function testGraphicExportsContainOptionalCirclesAndCompartmentLines(): void
    {
        $source = "erDiagram\ndirection LR\nA { int id PK string name }\nA ||--o{ B : owns";
        self::scene($source);
        $drawing = MermaidDrawing::scene(NativeFixtureScene::render($source));
        \iterator_to_array($drawing);
        $marks = $drawing->getReturn()->marks;
        // The ring's inner disc is the entity background, white under the default redux-color theme.
        $this->assertNotEmpty(\array_filter($marks, static fn (MermaidMark $mark): bool => 'polygon' === $mark->kind && 32 === \count($mark->points) && '#ffffff' === $mark->fill));
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('>PK</text>', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
    }

    public function testStyledExportsUseCardAndTextColors(): void
    {
        $source = "erDiagram\nA:::skin { int id PK }\nA ||--o{ B : owns\nclassDef skin fill:#123456,stroke:#654321,color:#abcdef,stroke-width:2px,stroke-dasharray:3 2";
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('fill="#123456"', $svg);
        $this->assertStringContainsString('stroke="#654321"', $svg);
        $this->assertStringContainsString('fill="#abcdef"', $svg);
        $this->assertStringContainsString('stroke-width="0.2"', $svg);
        $this->assertStringContainsString('stroke-dasharray=', $svg);
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
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
