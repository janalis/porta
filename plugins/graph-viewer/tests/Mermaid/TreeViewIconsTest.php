<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\FlowchartShapes;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidOutline;

/** Pinned tree view icons, the implicit root and directory faces, through the native pipeline. */
final class TreeViewIconsTest extends TestCase
{
    /** @return array<string, string> shape by label */
    private static function shapes(string $source): array
    {
        $scene = NativeFixtureScene::render($source);
        $shapes = [];
        foreach ($scene->graph->nodes as $node) {
            $shapes[$node->label] = $scene->shapes[$node->id] ?? 'rect';
        }

        return $shapes;
    }

    public function testIconsResolveAsThePinnedRendererDoes(): void
    {
        $shapes = self::shapes("treeView-beta\nsrc/ icon(folder)\nbell icon(fa:bell)\nplain icon(file)\nother icon(rocket)\nnone icon()\nbare");
        $this->assertStringStartsWith('tv-icon:folder:text|', $shapes['src/']);
        $this->assertStringStartsWith('tv-icon:unknown:text|', $shapes['bell']);
        $this->assertStringStartsWith('tv-icon:file:text|', $shapes['plain']);
        $this->assertStringStartsWith('tv-icon:unknown:text|', $shapes['other'], 'without a default pack a name means the tree view pack, which lacks it');
        $this->assertSame(['text', 'text'], [$shapes['none'], $shapes['bare']], 'no icon by default, and icon() hides one');
        $packed = self::shapes("---\nconfig:\n  treeView:\n    defaultIconPack: logos\n---\ntreeView-beta\nfolder icon(folder)\nreact icon(react)");
        $this->assertStringStartsWith('tv-icon:folder:', $packed['folder'], 'the built-in names stay the tree view\'s own');
        $this->assertStringStartsWith('tv-icon:unknown:', $packed['react']);
        $shown = self::shapes(<<<'MMD'
            ---
            config:
              treeView:
                showIcons: true
                filenameIcons: {Dockerfile: folder, "lib/": file}
                extensionIcons: {.md: none, ts: folder}
            ---
            treeView-beta
            src/
            lib/
            index.js
            Dockerfile
            README.md
            main.ts
            .ts
            explicit.js icon()
            MMD);
        $this->assertStringStartsWith('tv-icon:folder:', $shown['src/']);
        $this->assertStringStartsWith('tv-icon:file:', $shown['index.js'], 'unmapped files take the file icon');
        $this->assertStringStartsWith('tv-icon:folder:', $shown['Dockerfile'], 'by file name');
        $this->assertSame('text', $shown['README.md'], 'an extension mapped to none hides the icon');
        $this->assertStringStartsWith('tv-icon:folder:', $shown['main.ts'], 'an extension without its dot');
        $this->assertStringStartsWith('tv-icon:file:', $shown['.ts'], 'a leading dot is no extension');
        $this->assertStringStartsWith('tv-icon:folder:', $shown['lib/'], 'directories never look their names up');
        $this->assertSame('text', $shown['explicit.js']);
    }

    public function testGlyphsDrawBeforeTheLabelInTheIconColor(): void
    {
        $scene = NativeFixtureScene::render("treeView-beta\nsrc/ icon(folder)\nmain icon(file)\nodd icon(odd)\nplain");
        $drawing = MermaidDrawing::scene($scene);
        \iterator_to_array($drawing);
        [$folder, $file, $odd, $plain] = \array_values(\array_slice($scene->graph->nodes, offset: 0, length: 4));
        $this->assertSame($plain->w + 1.8 - (\mb_strlen('plain') - \mb_strlen('src')), $folder->w, 'the icon widens its row by its span');
        $marks = MermaidOutline::marks($folder, \str_replace('|@treeView.iconColor|=#546e7a', replace: '|#546e7a', subject: $scene->shapes[$folder->id]));
        $glyph = \array_last($marks);
        $this->assertSame(['polygon', '#546e7a', 10], [$glyph->kind, $glyph->fill, \count($glyph->points)]);
        $this->assertEqualsWithDelta(
            $folder->x - ($folder->w / 2) + 1.7,
            (\min(\array_column($glyph->points, 0)) + \max(\array_column($glyph->points, 0))) / 2,
            1e-9,
            'centered one unit in, fourteen pixels wide',
        );
        $this->assertCount(5, \array_last(MermaidOutline::marks($file, $scene->shapes[$file->id]))->points);
        $tile = MermaidOutline::marks($odd, $scene->shapes[$odd->id]);
        $this->assertSame('#087ebf', $tile[0]->fill);
        $this->assertEqualsWithDelta(1.4, \max(\array_column($tile[0]->points, 0)) - \min(\array_column($tile[0]->points, 0)), 1e-9);
        $this->assertSame([0.9 / $folder->w, 0.0], FlowchartShapes::labelOffset($scene->shapes[$folder->id], $folder), 'the label moves past the icon');
        $colors = \array_unique(\array_map(
            static fn (MermaidMark $mark): string => $mark->fill,
            \array_filter($drawing->getReturn()->marks, static fn (MermaidMark $mark): bool => 10 === \count($mark->points)),
        ));
        $this->assertSame(['#546e7a'], \array_values($colors), 'the theme token resolves when drawn');
    }

    public function testEveryTreeHangsFromABoldRootAndDirectoriesDrawBoldWithoutTheirSlash(): void
    {
        $scene = NativeFixtureScene::render("treeView-beta\nsrc/\n  index.js ## entry point\nREADME.md");
        $root = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => 'tree-view-root' === $node->name);
        $this->assertInstanceOf(GraphNode::class, $root);
        $this->assertSame(['/', GraphFont::Bold, true], [$root->label, $root->font, $root->background]);
        $this->assertNull($scene->selectable($root->id), 'the root is no source statement');
        $tops = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $root->id === $edge->tail));
        $this->assertCount(2, $tops);
        $this->assertSame(8.0, $scene->graph->nodes[0]->x - ($scene->graph->nodes[0]->w / 2) - ($root->x - ($root->w / 2)), 'top-level rows indent one level under the root');
        $this->assertSame([['src', GraphFont::Bold]], $scene->runs['node:0'][0]);
        $this->assertSame('src/', $scene->graph->nodes[0]->label, 'search and the legend keep the slash');
        $this->assertSame(
            [
                ['index.js',      GraphFont::Regular],
                ['  entry point', GraphFont::Italic],
            ],
            $scene->runs['node:1'][0],
        );
        $this->assertSame([['README.md', GraphFont::Regular]], $scene->runs['node:2'][0]);
        $wrapped = NativeFixtureScene::render("treeView-beta\n\"two\nlines/\" ## and a note");
        $this->assertSame(
            [
                [['two', GraphFont::Bold]],
                [
                    ['lines',        GraphFont::Bold],
                    ['  and a note', GraphFont::Italic],
                ],
            ],
            $wrapped->runs['node:0'],
            'a name broken across lines keeps its face to its end',
        );
    }
}
