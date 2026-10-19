<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidFallbackFonts;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidPng;
use Porta\GraphViewer\Mermaid\MermaidScene;

/** Markdown labels size their elements by the wrapped text and draw their styled runs. */
final class MermaidMarkdownLabelsTest extends TestCase
{
    public function testNodesEdgesAndFramesKeepTheirRunsThroughLayoutAndTitles(): void
    {
        $scene = NativeFixtureScene::render("---\ntitle: Runs\n---\nflowchart LR\nsubgraph S[\"`**Team**`\"]\nA[\"`**Bold** and *it*`\"]\nend\nA -->|\"`_edge_`\"| B[plain]\n");
        $node = self::named($scene, 'A');
        $this->assertSame('Bold and it', $node->label);
        $this->assertSame(
            [[
                ['Bold',  GraphFont::Bold],
                [' and ', GraphFont::Regular],
                ['it',    GraphFont::Italic],
            ]],
            $scene->runs['node:'.$node->id],
        );
        $this->assertSame([[['edge', GraphFont::Italic]]], $scene->runs['edge:0']);
        $this->assertSame([[['Team', GraphFont::Bold]]], $scene->runs['cluster:0']);
        $this->assertArrayNotHasKey('node:'.self::named($scene, 'B')->id, $scene->runs);
        $marks = self::marks($scene);
        $bold = self::mark($marks, 'Bold');
        $italic = self::mark($marks, 'it');
        $and = self::mark($marks, 'and');
        $this->assertSame(GraphFont::Bold, $bold->font);
        $this->assertSame(GraphFont::Italic, $italic->font);
        $this->assertSame(GraphFont::Regular, $and->font);
        // Runs sit side by side, one advance per character, centered on the node together.
        $this->assertEqualsWithDelta($bold->points[0][0] + 2 + 1 + 1.5, $and->points[0][0], 1e-9);
        $this->assertEqualsWithDelta($node->x, ($bold->points[0][0] - 2 + $italic->points[0][0] + 1) / 2, 1e-9);
        $this->assertSame(GraphFont::Italic, self::mark($marks, 'edge')->font);
        $this->assertSame(GraphFont::Bold, self::mark($marks, 'Team')->font);
    }

    public function testAutoWrapFollowsTheFamilyWidthUnlessDisabled(): void
    {
        $long = 'a very long markdown string that should wrap automatically';
        $wrapped = self::named(NativeFixtureScene::render("flowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame("a very long\nmarkdown string\nthat should wrap\nautomatically", $wrapped->label);
        $narrow = self::named(NativeFixtureScene::render("---\nconfig: {flowchart: {wrappingWidth: 60}}\n---\nflowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame("a very\nlong\nmarkdown\nstring\nthat\nshould\nwrap\nautomatically", $narrow->label);
        $flat = self::named(NativeFixtureScene::render("---\nconfig: {markdownAutoWrap: false}\n---\nflowchart LR\nA[\"`{$long}`\"]"), 'A');
        $this->assertSame($long, $flat->label);
        $mindmap = NativeFixtureScene::render("---\nconfig: {mindmap: {maxNodeWidth: 75}}\n---\nmindmap\nroot[\"`**Start** of a long idea`\"]");
        $root = \array_find($mindmap->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->label, 'Start'));
        $this->assertSame("Start of a\nlong idea", $root?->label);
        $this->assertNotEmpty($mindmap->runs);
    }

    public function testPngTextFallsBackPerCharacterAndBoxesWhatGdCannotDraw(): void
    {
        if (!\function_exists('imagettftext')) {
            $this->markTestSkipped('GD with FreeType is required');
        }
        $drawing = new MermaidDrawing(40, 10, [new MermaidMark('text', [[20.0, 5.0]], 'A & 漢 😎')]);
        $work = new MermaidPng(fallbacks: new MermaidFallbackFonts(['/nonexistent/font.ttf']))->render($drawing);
        \iterator_to_array($work);
        $image = \imagecreatefromstring($work->getReturn());
        $this->assertInstanceOf(\GdImage::class, $image);
    }

    private static function named(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node);

        return $node;
    }

    /** @return list<MermaidMark> */
    private static function marks(MermaidScene $scene): array
    {
        $work = MermaidDrawing::scene($scene);
        \iterator_to_array($work);

        return $work->getReturn()->marks;
    }

    /** @param list<MermaidMark> $marks */
    private static function mark(array $marks, string $text): MermaidMark
    {
        $mark = \array_find($marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && $text === $mark->text);
        self::assertInstanceOf(MermaidMark::class, $mark, 'No text mark '.$text);

        return $mark;
    }
}
