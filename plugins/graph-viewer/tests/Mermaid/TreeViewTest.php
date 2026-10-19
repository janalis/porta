<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\TreeViewDiagram;
use Porta\GraphViewer\Mermaid\TreeViewLayout;
use Porta\GraphViewer\Mermaid\TreeViewLexer;
use Porta\GraphViewer\Mermaid\TreeViewParser;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TreeViewTest extends TestCase
{
    private static function parse(string $source): TreeViewDiagram
    {
        $parser = new TreeViewParser();
        foreach (new TreeViewLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new TreeViewLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testIndentationForestsRepeatedNamesAndLiteralPunctuation(): void
    {
        $source = "%% intro\nTREEVIEW-BETA\n  root/\n    file's [draft];.txt\n      \"same\"\n    same\n  other/\n\tlast";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame(['root/', "file's [draft];.txt", 'same', 'same', 'other/', 'last'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([null, 0, 1, 0, null, null], \array_column($diagram->nodes, 'parent'));
        $this->assertSame([0, 1, 2, 1, 0, 0], \array_column($diagram->nodes, 'depth'));
        $this->assertSame([2, 3, 4, 5, 6, 7], \array_column($diagram->nodes, 'origin'));
        $tokens = \iterator_to_array(new TreeViewLexer($source)->statements());
        $this->assertSame(\strpos($source, needle: "file's"), $tokens[2]->offset);
        $this->assertSame(4, $tokens[2]->column);
        $this->assertSame([], self::parse('treeView-beta')->nodes);
    }

    public function testRowIndentAndLineThicknessFollowThePinnedPixels(): void
    {
        $left = static function (MermaidScene $scene, string $name): float {
            $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->label);
            self::assertInstanceOf(GraphNode::class, $node);

            return $node->x - ($node->w / 2);
        };
        $plain = NativeFixtureScene::render("treeView-beta\n  src\n    a");
        // Pinned rows step `rowIndent` pixels a level: eight units at its default of ten.
        $this->assertSame(8.0, $left($plain, name: 'a') - $left($plain, name: 'src'));
        $this->assertSame([0.1], \array_values(\array_unique(\array_column($plain->graph->edges, 'strokeWidth'))));
        $wide = NativeFixtureScene::render("---\nconfig:\n  treeView:\n    rowIndent: 25\n    lineThickness: 3\n---\ntreeView-beta\n  src\n    a");
        $this->assertSame(20.0, $left($wide, name: 'a') - $left($wide, name: 'src'));
        $this->assertSame([0.3], \array_values(\array_unique(\array_column($wide->graph->edges, 'strokeWidth'))));
        $flat = NativeFixtureScene::render("---\nconfig:\n  treeView:\n    rowIndent: -5\n    lineThickness: -1\n---\ntreeView-beta\n  src\n    a");
        $this->assertSame([0.0, [0.0]], [$left($flat, name: 'a') - $left($flat, name: 'src'), \array_values(\array_unique(\array_column($flat->graph->edges, 'strokeWidth')))]);
    }

    public function testMetadataMultilineQuotedLabelsAndConsecutiveSpaces(): void
    {
        $diagram = self::parse("treeView-beta\ntitle Project\naccTitle: Accessible\naccDescr {\n Description\n}\n'日本  project/ '\n  \"line one\nline two\" :::highlight\n  end");
        $this->assertSame('Project', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame('日本  project/ ', $diagram->nodes[0]['label']);
        $this->assertSame("line one\nline two", $diagram->nodes[1]['label']);
        $this->assertSame(9, $diagram->nodes[2]['origin']);
        $this->assertSame('', self::parse("treeView-beta\ntitle")->metadata['title']['label']);
        $this->assertSame('single', self::parse("treeView-beta\naccDescr: single")->metadata['accdescr']['label']);
        $scene = self::scene("treeView-beta\n\"日本  file\"\ntwo ## details");
        $this->assertSame('日本  file', $scene->graph->nodes[0]->drawLabel);
        $this->assertSame('two — details', $scene->graph->nodes[1]->label);
    }

    public function testAnnotationsAreInertAndDescriptionsOwnRemainingMarkers(): void
    {
        $diagram = self::parse("treeView-beta\nApp.tsx :::first icon(logos:react) :::highlight ## entry :::literal icon(no)\n'src/' icon()\nfile icon(none)\nempty ##\nfile %% hidden");
        $this->assertSame('highlight', $diagram->nodes[0]['class']);
        $this->assertSame('logos:react', $diagram->nodes[0]['icon']);
        $this->assertSame('entry :::literal icon(no)', $diagram->nodes[0]['description']);
        $this->assertSame('none', $diagram->nodes[1]['icon']);
        $this->assertSame('none', $diagram->nodes[2]['icon']);
        $this->assertSame('', $diagram->nodes[3]['description']);
        $this->assertSame('file', $diagram->nodes[4]['label']);
        $scene = self::scene("treeView-beta\nApp.tsx :::highlight icon(logos:react) ## entry\nfile icon()\n\"\"");
        $this->assertSame('tv-icon:unknown:rounded|@treeView.iconColor|=#546e7a', $scene->shapes[0], 'an unregistered pack draws the unknown tile');
        $this->assertSame('@treeView.highlightBg|=#ffc10726', $scene->graph->nodes[0]->fillColor);
        $this->assertSame('App.tsx  entry', $scene->graph->nodes[0]->drawLabel);
        $this->assertSame(
            [[
                ['App.tsx', GraphFont::Regular],
                ['  entry', GraphFont::Italic],
            ]],
            $scene->runs['node:0'],
            'descriptions follow in italics',
        );
        $this->assertSame('[logos:react] App.tsx — entry :::highlight', $scene->legend[0]['text']);
        $this->assertSame('text', $scene->shapes[1]);
        $this->assertSame('file', $scene->graph->nodes[1]->drawLabel);
        $this->assertSame('', $scene->graph->nodes[2]->label);
    }

    public function testBoxDrawingAndHeavyBranchesPreserveOriginalSourceLines(): void
    {
        $source = "treeView-beta\nproject/\n├── src/\n│   ┣━━ App.php :::highlight\n│   ┗━━ Other.php\n│\n└── README.md\nother/";
        $diagram = self::parse($source);
        $this->assertSame(['project/', 'src/', 'App.php', 'Other.php', 'README.md', 'other/'], \array_column($diagram->nodes, 'label'));
        $this->assertSame([null, 0, 1, 1, 0, null], \array_column($diagram->nodes, 'parent'));
        $this->assertSame([1, 2, 3, 4, 6, 7], \array_column($diagram->nodes, 'origin'));
        $tokens = \iterator_to_array(new TreeViewLexer($source)->statements());
        $this->assertSame(\strpos($source, needle: 'App.php'), $tokens[3]->offset);
        $scene = self::scene($source);
        $this->assertSame([0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 6, 5 => 7], $scene->elements);
        $this->assertSame(2, $scene->onLine(3));
        $this->assertNull($scene->onLine(5));
        $this->assertSame([null, 0, 1], \array_column(self::parse("treeView-beta\nroot\n├── child\n│  └── deep")->nodes, 'parent'));
        $this->assertSame([null, null], \array_column(self::parse("treeView-beta\n├── first\n└── last")->nodes, 'parent'));
    }

    public function testCommentsTabsAndMultilineBranches(): void
    {
        $diagram = self::parse("treeView-beta %% header\ntitle Project %% hidden\naccTitle: Accessible %% hidden\nroot/\n├── child\n\t└── deep");
        $this->assertSame('Project', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame([null, 0, 1], \array_column($diagram->nodes, 'parent'));
        $diagram = self::parse("treeView-beta\nroot/\n└── \"first\nsecond\" ## details\nend");
        $this->assertSame("first\nsecond", $diagram->nodes[1]['label']);
        $this->assertSame(4, $diagram->nodes[2]['origin']);
        $this->assertSame('details', $diagram->nodes[1]['description']);
        $this->assertSame('%% literal', self::parse("treeView-beta\n\"%% literal\"")->nodes[0]['label']);
    }

    public function testRowsAndConnectorsFollowHierarchyWithoutArrowsOrOverlap(): void
    {
        $scene = self::scene("treeView-beta\ntitle Project\nroot/\n  first\n    deep\n  last\nother/");
        $this->assertCount(5, $scene->selectionGraph->nodes);
        $this->assertCount(5, $scene->graph->edges, 'the two trees hang from the implicit root');
        [$root, $first, $deep, $last, $other] = \array_values($scene->selectionGraph->nodes);
        $this->assertSame($first->x - ($first->w / 2), $last->x - ($last->w / 2));
        $this->assertSame($root->x - ($root->w / 2), $other->x - ($other->w / 2));
        $this->assertSame(8.0, $deep->x - ($deep->w / 2) - ($first->x - ($first->w / 2)));
        foreach ($scene->graph->edges as $edge) {
            $parent = $scene->graph->nodes[$edge->tail];
            $child = $scene->graph->nodes[$edge->head];
            $this->assertNull($edge->arrowTip);
            $this->assertSame($parent->y + ($parent->h / 2), $edge->points[0][1]);
            $this->assertSame([$child->x - ($child->w / 2), $child->y], $edge->points[2]);
            $this->assertGreaterThanOrEqual($parent->y + ($parent->h / 2), $child->y - ($child->h / 2));
        }
        $this->assertNull($scene->selectable(5));
        $this->assertLessThan($root->y - ($root->h / 2), $scene->graph->nodes[5]->y + ($scene->graph->nodes[5]->h / 2));
        $this->assertContains(1, $scene->origins);
        $this->assertSame(6, $scene->origin(4));
        $this->assertGreaterThan(0, $scene->graph->width);
        $this->assertGreaterThan(0, self::scene('treeView-beta')->graph->height);
    }

    public function testLongWideLabelsWrapWithinGeometryAndKeepTheirSemanticText(): void
    {
        $label = \str_repeat('日本  label ', times: 100);
        $scene = self::scene("treeView-beta\n\"".$label.'"');
        $node = $scene->graph->nodes[0];
        $this->assertSame($label, $node->label);
        $this->assertStringContainsString("\n", $node->drawLabel);
        foreach (\explode("\n", $node->drawLabel) as $line) {
            $this->assertLessThanOrEqual($node->w - 4, AnsiUtils::visibleWidth($line));
        }
    }

    #[DataProvider('invalidSources')]
    public function testMalformedInputAndResourceLimitsAreRejected(string $source, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected treeView-beta'];
        yield ['treeView', 'Expected treeView-beta'];
        yield ["treeView-beta\n\"unclosed", 'Unclosed tree-view label'];
        yield ["treeView-beta\naccDescr {never", 'Unclosed accessibility'];
        yield ["treeView-beta\naccDescr {closed}extra", 'Unclosed accessibility'];
        yield ["treeView-beta\n\"file\"bad", 'Invalid tree-view annotation'];
        yield ["treeView-beta\n:::class", 'Expected a tree-view label'];
        yield ["treeView-beta\nicon(x)", 'Expected a tree-view label'];
        yield ["treeView-beta\nfile icon(bad:)", 'Invalid tree-view annotation'];
        yield ["treeView-beta\nfile\ntitle Late", 'metadata must precede'];
        yield ["treeView-beta\n├──", 'Empty tree-view branch'];
        yield ["treeView-beta\n├── first\n  mixed", 'Unexpected indentation'];
        yield ["treeView-beta\n".\str_repeat('x', times: 65_537), 'size limit'];
        yield ["treeView-beta\n".\str_repeat("file\n", times: 4097), 'node limit'];
        $deep = "treeView-beta\n";
        for ($i = 0; $i < 129; ++$i) {
            $deep .= \str_repeat(' ', $i)."node\n";
        }
        yield [$deep, 'depth limit'];
    }

    #[DataProvider('upstreamExamples')]
    public function testUpstreamExamplesRenderWithoutTheCli(string $file): void
    {
        $source = \file_get_contents($file);
        $this->assertIsString($source);
        $result = NativeFixtureRows::render($source, width: 32);
        $this->assertNotEmpty($result->rows);
        foreach ($result->rows as $row) {
            $this->assertLessThanOrEqual(32, AnsiUtils::visibleWidth($row));
        }
    }

    /** @return iterable<array{string}> */
    public static function upstreamExamples(): iterable
    {
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/tree-view/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationDoesNotPublishPartialRows(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "treeView-beta\n".\str_repeat("file\n", times: 3000),
            static function (): never {
                self::fail('Cancelled tree view published');
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

    #[DataProvider('exportFormats')]
    public function testExportsEscapeLabelsAndRenderHighlightAndConnectors(GraphExportFormat $format): void
    {
        $scheduler = new FakeScheduler();
        $result = null;
        new NativeMermaidRenderer(new MermaidTask($scheduler))->export(
            "treeView-beta\nroot/\n  \"<script>\" :::highlight ## &literal;",
            $format,
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertIsString($result);
        if (GraphExportFormat::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('&amp;literal;', $result);
            $this->assertStringContainsString('#ffc10726', $result);
            $this->assertStringContainsString('<polyline', $result);
            $this->assertStringNotContainsString('<script>', $result);

            return;
        }
        $this->assertStringStartsWith("\x89PNG", $result);
        $image = \imagecreatefromstring($result);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertGreaterThan(0, \imagesx($image));
    }

    /** @return iterable<array{GraphExportFormat}> */
    public static function exportFormats(): iterable
    {
        yield [GraphExportFormat::Svg];
        yield [GraphExportFormat::Png];
    }
}
