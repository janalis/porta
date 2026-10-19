<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\MermaidCss;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class MermaidDashStylesTest extends TestCase
{
    public function testEscapedCommaListsKeepFollowingPropertiesAndZeroListsSolid(): void
    {
        $statement = new MermaidStatement('style A', offset: 0, line: 3, column: 2);
        $css = new MermaidCss('flowchart');
        $style = $css->parse('stroke-dasharray:5\, 0.5\,2,stroke:red,fill:blue;', $statement);
        $this->assertSame(['dash-pattern' => '5 0.5 2', 'stroke-dasharray' => true, 'stroke' => '#ff0000', 'fill' => '#0000ff'], $style);
        $this->assertSame([0.5, 0.05, 0.2, 0.5, 0.05, 0.2], MermaidCss::dashes($style, current: null), 'an odd list repeats, as SVG does');
        $this->assertSame(['dash-pattern' => '0 0.0', 'stroke-dasharray' => false], $css->parse('stroke-dasharray:0\,0.0', $statement));
        $none = $css->parse('stroke-dasharray: NONE ', $statement);
        $this->assertSame(['dash-pattern' => 'NONE', 'stroke-dasharray' => false], $none);
        $this->assertNull(MermaidCss::dashes($none, current: [1.0, 1.0]), 'none drops an earlier pattern');
        $this->assertSame([1.0, 1.0], MermaidCss::dashes(['stroke' => '#ff0000'], current: [1.0, 1.0]), 'a style without a pattern keeps the current one');
        $this->assertSame([0.4, 0.2], MermaidCss::dashes($css->parse('stroke-dasharray:4\,2', $statement), current: null));
        $border = $css->parse('border:2px dashed red', $statement);
        $this->assertNull(MermaidCss::dashes($border, current: [1.0, 1.0]), 'a dashed border draws the default dash');
    }

    public function testNodeGroupAndEdgeOverridesReachNativeExportsAndCells(): void
    {
        $source = <<<'MMD'
            flowchart TD
            subgraph G[Group]
            A[Dashed]:::frame --> B[Solid]:::frame
            end
            B -.-> A
            classDef frame stroke-dasharray:5\,5,stroke:red
            style B stroke-dasharray:none
            style G stroke-dasharray:3\,2,stroke:blue
            linkStyle default stroke-dasharray:4\,2,stroke:green
            linkStyle 1 stroke-dasharray:NONE
            MMD;
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertSame(3, \substr_count($svg, needle: 'stroke-dasharray='));
        foreach (['stroke-dasharray="0.5 0.5"', 'stroke-dasharray="0.3 0.2"', 'stroke-dasharray="0.4 0.2"'] as $pattern) {
            $this->assertStringContainsString($pattern, $svg, 'authored lengths reach the picture');
        }
        foreach (['#ff0000', '#0000ff', '#008000', '>Dashed</text>', '>Solid</text>'] as $needle) {
            $this->assertStringContainsString($needle, $svg);
        }
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $rows = NativeFixtureRows::render($source, width: 30);
        $this->assertStringContainsString('Dashed', \implode("\n", $rows->rows));
        $this->assertContains(2, $rows->origins);
    }

    public function testRasterDashesFollowTheAuthoredLengths(): void
    {
        $line = static fn (?array $dashes): MermaidMark => new MermaidMark('polyline', [[0.0, 1.0], [10.0, 1.0]], stroke: '#000000', dashed: true, strokeWidth: 0.1, dashes: $dashes);
        $work = new MermaidPng()->render(new MermaidDrawing(10.0, 2.0, [$line([2.0, 1.0])]));
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        // Twenty pixels drawn, then ten left blank, from the line's start.
        $this->assertSame([0x00_0000, 0x00_0000, 0xff_ffff, 0x00_0000], [
            \imagecolorat($image, x: 1, y: 10),
            \imagecolorat($image, x: 18, y: 10),
            \imagecolorat($image, x: 25, y: 10),
            \imagecolorat($image, x: 31, y: 10),
        ]);
        $work = new MermaidPng()->render(new MermaidDrawing(10.0, 2.0, [$line([0.0, 0.0])]));
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame([0x00_0000, 0xff_ffff], [\imagecolorat($image, x: 0, y: 10), \imagecolorat($image, x: 1, y: 10)], 'zero lengths still draw a pixel each');
        $work = new MermaidPng()->render(new MermaidDrawing(10.0, 2.0, [$line([])]));
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertSame([0x00_0000, 0x00_0000], [\imagecolorat($image, x: 25, y: 10), \imagecolorat($image, x: 75, y: 10)], 'an empty pattern draws solid');
    }

    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        yield 'state' => ["stateDiagram-v2\n  A --> B\n  classDef d stroke-dasharray:5\\,5\n  class A d"];
        yield 'class' => ["classDiagram\n  class A\n  A --> B\n  classDef d stroke-dasharray:5\\,5\n  class A:::d"];
        yield 'er' => ["erDiagram\n  A ||--o{ B : has\n  classDef d stroke-dasharray:5\\,5\n  class A d"];
        yield 'flowchart glyphs' => ["flowchart LR\n  A@{shape: cyl}:::d --> B@{shape: hex}:::d\n  classDef d stroke-dasharray:5\\,5"];
        yield 'treemap' => ["treemap-beta\n\"A\"\n  \"B\": 10:::d\nclassDef d stroke-dasharray:5 5"];
    }

    #[DataProvider('families')]
    public function testAuthoredLengthsSurviveEachFamilysLayout(string $source): void
    {
        $this->assertStringContainsString('stroke-dasharray="0.5 0.5"', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'negative' => ['stroke-dasharray:5\,-1'];
        yield 'bad component' => ['stroke-dasharray:5\,oops'];
        yield 'dangling escape' => ['stroke-dasharray:5\\'];
        yield 'empty component' => ['stroke-dasharray:5\,\,2'];
        yield 'unescaped comma' => ['stroke-dasharray:5,5'];
        yield 'empty property' => ['stroke-dasharray:5,,stroke:red'];
    }

    #[DataProvider('invalid')]
    public function testMalformedListsRetainLocatedErrors(string $style): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Line 4, column 3:');
        new MermaidCss('flowchart')->parse($style, new MermaidStatement('style A', offset: 20, line: 3, column: 2));
    }
}
