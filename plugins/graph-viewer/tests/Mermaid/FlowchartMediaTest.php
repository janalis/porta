<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\FlowchartShapes;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidSketch;
use Porta\GraphViewer\Mermaid\MermaidText;

/** Flowchart icon and image nodes: the pinned unknown-icon tile and embedded `data:` pictures. */
final class FlowchartMediaTest extends TestCase
{
    /** A 40×20 orange PNG. */
    private static function png(): string
    {
        $canvas = \imagecreatetruecolor(width: 40, height: 20);
        \imagefill($canvas, x: 0, y: 0, color: 0xFF_8000);
        \ob_start();
        \imagepng($canvas);

        return (string) \ob_get_clean();
    }

    private static function uri(): string
    {
        return 'data:image/png;base64,'.\base64_encode(self::png());
    }

    public function testIconNodesDrawTheUnknownIconTileAboveOrBelowTheirLabel(): void
    {
        $scene = NativeFixtureScene::render(
            "flowchart LR\n  A@{ icon: \"fa:book\", label: \"Book\" }\n  B@{ icon: \"fa:book\", label: \"Top\", pos: \"t\", h: 60 }\n  C@{ icon: \"fa:book\" }\n  D@{ icon: \"fa:book\", h: \"wide\" }",
        );
        $this->assertSame(['fc-icon:b:4.8:4.8', 'fc-icon:t:6:6', 'fc-icon:b:4.8:4.8', 'fc-icon:b:4.8:4.8'], \array_values($scene->shapes));
        [$a, $b, $c] = \array_values($scene->graph->nodes);
        $this->assertSame(['Book', 'Top', ''], [$a->label, $b->label, $c->label], 'the icon name is never label text');
        $this->assertSame([MermaidText::box('Book')[0] - 2, 7.8], [$a->w, $a->h], 'a one-line label adds three units beneath');
        $this->assertSame([4.8, 4.8], [$c->w, $c->h]);
        $wide = NativeFixtureScene::render("flowchart LR\n  A@{ icon: \"x\", label: \"A much longer label\" }")->graph->nodes[0];
        $this->assertSame(MermaidText::box('A much longer label')[0] - 2, $wide->w, 'a label wider than the tile widens the node');
        $this->assertSame(4.8, NativeFixtureScene::render("flowchart LR\n  A@{ icon: \"x\", label: \"A\" }")->graph->nodes[0]->w, 'a narrow label leaves the tile\'s width');
        $marks = MermaidOutline::marks($a, $scene->shapes[$a->id]);
        $this->assertSame('#087ebf', $marks[0]->fill);
        $this->assertEqualsWithDelta([[$a->x - 2.4, $a->y - 3.9], [$a->x + 2.4, $a->y - 3.9]], \array_slice($marks[0]->points, offset: 0, length: 2), 1e-9, 'the tile fills the top of the node');
        $this->assertSame('?', \array_find($marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind)->text);
        $band = \array_last($marks);
        $this->assertEqualsWithDelta([$a->y - 3.9 + 4.8, $a->y + 3.9], [$band->points[0][1], $band->points[2][1]], 1e-9, 'the label band lies beneath the tile');
        $this->assertEqualsWithDelta(1.2, \array_find($marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind)->fontScale, 1e-9, 'the eight-unit tile scaled to 4.8');
        $this->assertSame([0.0, 2.4 / 7.8], FlowchartShapes::labelOffset($scene->shapes[$a->id], $a), 'the label centers in its band');
        $this->assertSame([0.0, -3.0 / 9.0], FlowchartShapes::labelOffset($scene->shapes[$b->id], $b));
        $top = MermaidOutline::marks($b, $scene->shapes[$b->id]);
        $this->assertEqualsWithDelta($b->y + 4.5, \max(\array_column($top[0]->points, 1)), 1e-9, 'pos t puts the tile at the foot');
        $this->assertEqualsWithDelta([$b->y - 4.5, $b->y - 1.5], [\array_last($top)->points[0][1], \array_last($top)->points[2][1]], 1e-9);
        $this->assertCount(3, MermaidOutline::marks($c, $scene->shapes[$c->id]), 'no label, no band');
    }

    public function testDataImagesEmbedAtTheirSizeAndOtherSourcesDrawAPlaceholder(): void
    {
        $uri = self::uri();
        foreach ([
            'both sides' => ['w: 60, h: 60', [6.0, 6.0]],
            'width keeps proportions' => ['w: 60', [6.0, 3.0]],
            'height keeps proportions' => ['h: 60', [12.0, 6.0]],
            'own pixels' => ['', [4.0, 2.0]],
            'clamped' => ['w: 5000, h: 0.1', [60.0, 1.0]],
            'not numbers' => ['w: "wide"', [4.0, 2.0]],
            'not positive' => ['w: -50', [4.0, 2.0]],
        ] as $case => [$size, $expected]) {
            $scene = NativeFixtureScene::render("flowchart LR\n  E@{ img: \"{$uri}\"".('' === $size ? '' : ', '.$size).' }');
            $node = $scene->graph->nodes[0];
            $this->assertSame($expected, [$node->w, $node->h], $case);
            $marks = MermaidOutline::marks($node, $scene->shapes[$node->id]);
            $this->assertCount(1, $marks, $case);
            $this->assertSame('image', $marks[0]->kind);
            $this->assertSame($uri, $marks[0]->text);
        }
        $spaced = NativeFixtureScene::render("flowchart LR\n  E@{ img: \"data:image/png;base64,".\chunk_split(\base64_encode(self::png()), length: 20, separator: ' ').'" }');
        $this->assertSame([4.0, 2.0], [$spaced->graph->nodes[0]->w, $spaced->graph->nodes[0]->h]);
        $encoded = NativeFixtureScene::render("flowchart LR\n  E@{ img: \"data:image/png,".\rawurlencode(self::png()).'" }');
        $this->assertSame([4.0, 2.0], [$encoded->graph->nodes[0]->w, $encoded->graph->nodes[0]->h], 'percent-encoded data');
        $bmp = \imagecreatetruecolor(width: 4, height: 4);
        \ob_start();
        \imagebmp($bmp);
        $bitmap = 'data:image/bmp;base64,'.\base64_encode((string) \ob_get_clean());
        foreach ([
            'https://example.test/x.png',
            'https://example.test/?'.self::uri(),
            $bitmap,
            'pictures/x.png',
            'data:image/svg+xml;base64,'.\base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'data:image/png;base64,!!!',
        ] as $source) {
            $scene = NativeFixtureScene::render("flowchart LR\n  E@{ img: \"{$source}\", label: \"Pic\" }");
            $node = $scene->graph->nodes[0];
            $this->assertSame([10.0, 13.0], [$node->w, $node->h], $source.' keeps the default size');
            $marks = MermaidOutline::marks($node, $scene->shapes[$node->id]);
            $this->assertSame(['polygon', 'polyline', 'polyline', 'polygon'], \array_column($marks, 'kind'), $source.' draws a crossed frame');
            $this->assertSame('none', $marks[0]->fill);
        }
    }

    public function testExportsEmbedRebuiltImagesAndSketchesLeaveThemAlone(): void
    {
        $source = "flowchart LR\n  E@{ img: \"".self::uri().'", w: 100, h: 50, label: "Pic" }';
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertMatchesRegularExpression('~<image x="[^"]+" y="[^"]+" width="10" height="5" preserveAspectRatio="none" href="data:image/png;base64,[A-Za-z0-9+/=]+"/>~', $svg);
        $svgInjection = NativeFixtureExport::render("flowchart LR\n  E@{ img: \"data:image/svg+xml;base64,".\base64_encode('<svg onload="x"/>').'" }', GraphExportFormat::Svg);
        $this->assertStringNotContainsString('<image', $svgInjection);
        $this->assertStringNotContainsString('onload', $svgInjection);
        $wide = NativeFixtureScene::render($source)->graph->nodes[0];
        $band = \array_last(MermaidOutline::marks($wide, NativeFixtureScene::render($source)->shapes[$wide->id]));
        $this->assertEqualsWithDelta(MermaidText::box('Pic')[0] - 2, $band->points[1][0] - $band->points[0][0], 1e-9, 'the label band fits the label, not the wider picture');
        $png = NativeFixtureExport::render($source, GraphExportFormat::Png);
        $picture = \imagecreatefromstring($png);
        $this->assertInstanceOf(\GdImage::class, $picture);
        $scene = NativeFixtureScene::render($source);
        $node = $scene->graph->nodes[0];
        $center = \imagecolorat($picture, (int) ($node->x * 10), (int) (($node->y - ($node->h / 2) + 2.5) * 10));
        $this->assertSame(0xFF_8000, $center & 0xFF_FFFF, 'the picture is drawn at its box');
        $image = new MermaidMark('image', [[0.0, 0.0], [1.0, 1.0]], self::uri());
        $this->assertSame([$image], new MermaidSketch(seed: 1)->shape($image));
        $broken = new MermaidMark('image', [[0.0, 0.0], [1.0, 1.0]], 'data:image/png;base64,AAAA');
        $work = new MermaidPng()->render(new MermaidDrawing(2.0, 2.0, [$broken]));
        \iterator_to_array($work);
        $this->assertStringStartsWith("\x89PNG", $work->getReturn(), 'an undecodable picture is skipped');
    }
}
