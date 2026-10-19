<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\ErCard;
use Porta\GraphViewer\Mermaid\MermaidCss;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidFont;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidNodeStyles;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSvg;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

final class MermaidTypographyTest extends TestCase
{
    public function testFacesCascadeResetAndProduceDistinctRasterGlyphs(): void
    {
        $hashes = [];
        foreach (GraphFont::cases() as $face) {
            $style = ['font-weight' => 'bold' === $face->weight(), 'font-style' => 'italic' === $face->style()];
            $this->assertSame($face, GraphFont::Regular->overlay($style));
            $this->assertSame($face, $face->overlay([]));
            $this->assertSame(GraphFont::Regular, $face->overlay(['font-weight' => false, 'font-style' => false]));
            $this->assertFileExists(MermaidFont::file($face));
            $drawing = new MermaidDrawing(60, 12, [new MermaidMark('text', [[30.0, 7.0]], 'Hamburgefontsiv', font: $face)]);
            $png = new MermaidPng()->render($drawing);
            \iterator_to_array($png);
            $this->assertStringStartsWith("\x89PNG", $png->getReturn());
            $hashes[] = \hash('sha256', $png->getReturn());
            $svg = MermaidSvg::render($drawing);
            \iterator_to_array($svg);
            $this->assertStringContainsString('font-style:'.$face->style().';font-weight:'.$face->weight(), $svg->getReturn());
            if (GraphFont::Regular === $face) {
                continue;
            }
            $this->assertStringContainsString('font-style="'.$face->style().'" font-weight="'.$face->weight().'"', $svg->getReturn());
        }
        $this->assertCount(4, \array_unique($hashes));
    }

    public function testCompartmentDividersFollowScaledLabelRows(): void
    {
        $node = new GraphNode(0, 'A', "Header\n\nAttribute", 20.0, 10.0, 30.0, 16.0, color: null, fillColor: null, dashed: false, fontScale: 2.0);
        $marks = ErCard::dividers($node, 'er-card:1');
        $this->assertSame([[5.0, 10.0], [35.0, 10.0]], $marks[0]->points);
    }

    public function testRasterFontSizesChangeGlyphsOnAnIdenticalCanvas(): void
    {
        $hashes = [];
        foreach ([1.0, 2.0] as $scale) {
            $drawing = new MermaidDrawing(60, 16, [new MermaidMark('text', [[30.0, 9.0]], 'Scaled text', fontScale: $scale)]);
            $png = new MermaidPng()->render($drawing);
            \iterator_to_array($png);
            $hashes[] = \hash('sha256', $png->getReturn());
        }
        $this->assertNotSame($hashes[0], $hashes[1]);
    }

    public function testBorderShorthandTypographyAndExplicitResetsPreserveGeometry(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 0, column: 0);
        $css = new MermaidCss('state');
        $style = $css->parse('FONT-STYLE:italic,font-weight:700,border:2px dashed red,stroke:blue', $statement);
        $this->assertSame(['font-style' => true, 'font-weight' => true, 'stroke-width' => 0.2, 'stroke-dasharray' => true, 'dash-pattern' => '', 'stroke' => '#0000ff'], $style);
        $this->assertSame(['stroke' => 'none'], $css->parse('border:none', $statement));
        $this->assertFalse($css->parse('border:1 solid green', $statement)['stroke-dasharray']);
        $this->assertFalse($css->parse('font-weight:400', $statement)['font-weight']);
        $this->assertTrue($css->parse('font-style:oblique', $statement)['font-style']);
        $this->assertStringContainsString('Visible', \implode("\n", NativeFixtureRows::render("graph LR\nA[Visible]\nstyle A stroke:none", width: 30)->rows));
        $styles = new MermaidNodeStyles('state');
        $styles->accept(new MermaidStatement('classDef default font-style:italic,font-weight:bold', offset: 0, line: 0, column: 0));
        $styles->accept(new MermaidStatement('style A font-style:normal,font-weight:normal', offset: 0, line: 0, column: 0));
        $node = new GraphNode(0, 'A', 'Styled', 10.0, 10.0, 20.0, 8.0, null, null, dashed: false, font: GraphFont::BoldItalic);
        $decorated = $styles->decorate($node);
        $this->assertSame(GraphFont::Regular, $decorated->font);
        $this->assertSame([$node->x, $node->y, $node->w, $node->h], [$decorated->x, $decorated->y, $decorated->w, $decorated->h]);
        foreach ([
            "classDiagram\nclass A\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "stateDiagram-v2\nA\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "flowchart LR\nA[Styled]\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "erDiagram\nA\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
        ] as $source) {
            $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
            $this->assertStringContainsString('font-style="italic" font-weight="bold"', $svg);
            $this->assertStringContainsString('stroke="#ff0000"', $svg);
            $this->assertStringContainsString('style="font-size:3.2px"', $svg);
        }
    }

    public function testEdgeCaptionsAndClusterHeadersUseTheirAuthoredFaces(): void
    {
        $svg = NativeFixtureExport::render("graph LR\nA -->|Typed edge| B\nlinkStyle 0 font-style:italic,font-weight:bold", GraphExportFormat::Svg);
        $this->assertStringContainsString('font-style="italic" font-weight="bold">Typed edge</text>', $svg);
        $svg = NativeFixtureExport::render("graph LR\nsubgraph G[Styled group]\nA\nend\nstyle G font-style:italic,font-weight:bold", GraphExportFormat::Svg);
        $this->assertStringContainsString('font-style="italic" font-weight="bold">Styled group</text>', $svg);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'font size syntax' => ['font-size:large', 'Font size'];
        yield 'font size zero' => ['font-size:0', 'Font size'];
        yield 'font size limit' => ['font-size:1001px', 'Font size'];
        yield 'weight' => ['font-weight:heavy', 'font weight'];
        yield 'weight overflow' => ['font-weight:1000', 'font weight'];
        yield 'style' => ['font-style:slanted', 'font style'];
        yield 'border syntax' => ['border:solid red', 'border shorthand'];
        yield 'border width' => ['border:1001px solid red', 'stroke width'];
        yield 'border color' => ['border:1px solid invalid', 'style color'];
    }

    #[DataProvider('invalid')]
    public function testInvalidCssIsLocated(string $css, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        new MermaidCss('state')->parse($css, new MermaidStatement($css, offset: 10, line: 2, column: 3));
    }

    public function testPackagedRasterCancellationClosesEveryLoadedFace(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('fonts.tar'));
        foreach (GraphFont::cases() as $face) {
            $archive->addFile(MermaidFont::file($face), 'IBMPlexMono-'.$face->value.'.ttf');
        }
        $directory = $project->path('temporary');
        \mkdir($directory);
        $script = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            require $argv[1].'/tests/plugin-autoload.php';
            $marks = [
                new Porta\GraphViewer\Mermaid\MermaidMark('text', [[10.0, 5.0]], 'Bold', font: Porta\GraphViewer\Graph\GraphFont::Bold),
                new Porta\GraphViewer\Mermaid\MermaidMark('text', [[10.0, 10.0]], 'Italic', font: Porta\GraphViewer\Graph\GraphFont::Italic),
            ];
            $work = new Porta\GraphViewer\Mermaid\MermaidPng(font: $argv[2])->render(new Porta\GraphViewer\Mermaid\MermaidDrawing(30, 15, $marks));
            $work->current();
            $work->next();
            $during = count(glob(sys_get_temp_dir().'/porta-mermaid-font-*'));
            $work = null;
            echo json_encode([$during, count(glob(sys_get_temp_dir().'/porta-mermaid-font-*'))]);
            PHP;
        $process = new Process([\PHP_BINARY, '-d', 'sys_temp_dir='.$directory, '-r', $script, \dirname(__DIR__, levels: 4), 'phar://'.$project->path('fonts.tar').'/IBMPlexMono-Regular.ttf']);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('[2,0]', $process->getOutput());
        $this->assertSame([], \glob($directory.'/porta-mermaid-font-*'));
    }
}
