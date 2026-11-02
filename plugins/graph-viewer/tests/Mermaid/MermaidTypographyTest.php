<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidCss;
use Janalis\Viz\Mermaid\MermaidNodeStyles;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Text\Font;
use Janalis\Viz\Text\FontFace;
use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Process\Process;

final class MermaidTypographyTest extends TestCase
{
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
        $node = new GraphNode(0, 'A', 'Styled', 10.0, 10.0, 20.0, 8.0, null, null, dashed: false, font: FontFace::BoldItalic);
        $decorated = $styles->decorate($node);
        $this->assertSame(FontFace::Regular, $decorated->font);
        $this->assertSame([$node->x, $node->y, $node->w, $node->h], [$decorated->x, $decorated->y, $decorated->w, $decorated->h]);
        foreach ([
            "classDiagram\nclass A\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "stateDiagram-v2\nA\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "flowchart LR\nA[Styled]\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
            "erDiagram\nA\nstyle A font-style:italic,font-weight:bold,font-size:32px,border:1px solid red",
        ] as $source) {
            $svg = NativeFixtureExport::render($source, Format::Svg);
            $this->assertStringContainsString('font-style="italic" font-weight="bold"', $svg);
            $this->assertStringContainsString('stroke="#ff0000"', $svg);
            $this->assertStringContainsString('style="font-size:3.2px"', $svg);
        }
    }

    public function testPackagedRasterCancellationClosesEveryLoadedFace(): void
    {
        $project = new TempProject();
        $archive = new \PharData($project->path('fonts.tar'));
        foreach (FontFace::cases() as $face) {
            $archive->addFile(Font::file($face), 'IBMPlexMono-'.$face->value.'.ttf');
        }
        $directory = $project->path('temporary');
        \mkdir($directory);
        $script = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            require $argv[1].'/tests/plugin-autoload.php';
            $marks = [
                new Janalis\Viz\Draw\Mark(Janalis\Viz\Draw\MarkKind::Text, [[10.0, 5.0]], 'Bold', font: Janalis\Viz\Text\FontFace::Bold),
                new Janalis\Viz\Draw\Mark(Janalis\Viz\Draw\MarkKind::Text, [[10.0, 10.0]], 'Italic', font: Janalis\Viz\Text\FontFace::Italic),
            ];
            $work = new Janalis\Viz\Draw\PngBackend(font: $argv[2])->render(new Janalis\Viz\Draw\Drawing(30, 15, $marks));
            $work->current();
            $work->next();
            $during = count(glob(sys_get_temp_dir().'/viz-mermaid-font-*'));
            $work = null;
            echo json_encode([$during, count(glob(sys_get_temp_dir().'/viz-mermaid-font-*'))]);
            PHP;
        $process = new Process([\PHP_BINARY, '-d', 'sys_temp_dir='.$directory, '-r', $script, \dirname(__DIR__, levels: 4), 'phar://'.$project->path('fonts.tar').'/IBMPlexMono-Regular.ttf']);
        $this->assertSame(0, $process->run(), $process->getErrorOutput());
        $this->assertSame('[2,0]', $process->getOutput());
        $this->assertSame([], \glob($directory.'/viz-mermaid-font-*'));
    }
}
