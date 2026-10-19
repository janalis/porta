<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\CynefinBoundaries;
use Porta\GraphViewer\Mermaid\CynefinDiagram;
use Porta\GraphViewer\Mermaid\CynefinLayout;
use Porta\GraphViewer\Mermaid\CynefinLexer;
use Porta\GraphViewer\Mermaid\CynefinParser;
use Porta\GraphViewer\Mermaid\MermaidOutline;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\Tests\Support\FakeScheduler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CynefinTest extends TestCase
{
    private static function parse(string $source): CynefinDiagram
    {
        $parser = new CynefinParser();
        foreach (new CynefinLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new CynefinLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testDomainsMetadataQuotedItemsAndSourcePositions(): void
    {
        $source = "%% intro\nCYNEFIN-BETA:\ntitle Framework %% hidden\naccTitle: Accessible\naccDescr {\n Description\n}\ncomplex\n  \"One %% literal\" 'Two \\'quotes\\''\ncomplicated\n\"Multiline\nlabel\"\nclear\n\"A &amp; B\"\nchaotic\n\"\"\nconfusion\n\"Unknown\"";
        $diagram = self::parse($source);
        $this->assertSame(1, $diagram->origin);
        $this->assertSame('Framework', $diagram->metadata['title']['label']);
        $this->assertSame('Accessible', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['One %% literal', "Two 'quotes'"], \array_column($diagram->domains['complex']['items'], 'label'));
        $this->assertSame("Multiline\nlabel", $diagram->domains['complicated']['items'][0]['label']);
        $this->assertSame('A & B', $diagram->domains['clear']['items'][0]['label']);
        $this->assertSame('', $diagram->domains['chaotic']['items'][0]['label']);
        $this->assertSame(7, $diagram->domains['complex']['origin']);
        $this->assertSame(10, $diagram->domains['complicated']['items'][0]['origin']);
        $tokens = \iterator_to_array(new CynefinLexer($source)->statements());
        $item = \array_find($tokens, static fn ($statement): bool => \str_starts_with($statement->text, '"One'));
        $this->assertSame(\strpos($source, needle: '"One'), $item->offset);
        $this->assertSame(2, $item->column);
        $this->assertSame('', self::parse("cynefin-beta\ntitle")->metadata['title']['label']);
        $this->assertSame('single', self::parse("cynefin-beta\naccDescr: single")->metadata['accdescr']['label']);
    }

    public function testRepeatedBlocksReplaceContentsAndSelfLoopsAreDropped(): void
    {
        $diagram = self::parse(
            "cynefin-beta\ncomplex \"Old\"\ncomplex\n\"New\"\nclear\ncomplex --> complex: \"Drop\"\ncomplex --> clear : 'Promote'\nclear --> chaotic\nconfusion --> complicated : \"Find\\\"expert\"",
        );
        $this->assertSame(['New'], \array_column($diagram->domains['complex']['items'], 'label'));
        $this->assertSame(2, $diagram->domains['complex']['origin']);
        $this->assertSame([], $diagram->domains['clear']['items']);
        $this->assertCount(3, $diagram->transitions);
        $this->assertSame(['complex', 'clear', 'confusion'], \array_column($diagram->transitions, 'from'));
        $this->assertSame(['clear', 'chaotic', 'complicated'], \array_column($diagram->transitions, 'to'));
        $this->assertSame(['Promote', '', 'Find"expert'], \array_column($diagram->transitions, 'label'));
        $this->assertSame(6, $diagram->transitions[0]['origin']);
    }

    public function testEmptyFrameworkHasFiveSelectableHeadersAndDecorativeRegions(): void
    {
        $scene = self::scene('cynefin-beta');
        $this->assertSame(['Complex', 'Complicated', 'Chaotic', 'Clear', 'Confusion'], \array_column($scene->selectionGraph->nodes, 'label'));
        $this->assertSame([true, true, true], \array_column($scene->graph->edges, 'decoration'), 'the folds and the cliff only decorate');
        $this->assertCount(5, $scene->elements);
        $this->assertNull($scene->selectable(5));
        foreach ($scene->elements as $id => $origin) {
            $this->assertSame(0, $origin);
            $this->assertSame('text', $scene->shapes[$id]);
        }
        $nodes = $scene->graph->nodes;
        $this->assertLessThan($nodes[1]->x, $nodes[0]->x);
        $this->assertLessThan($nodes[2]->y, $nodes[0]->y);
        $this->assertGreaterThan($nodes[0]->x, $nodes[4]->x);
        $this->assertLessThan($nodes[1]->x, $nodes[4]->x);
        $this->assertGreaterThan($nodes[0]->y, $nodes[4]->y);
        $this->assertLessThan($nodes[2]->y, $nodes[4]->y);
        $this->assertSame(220.0, $scene->graph->width);
    }

    public function testItemsExpandEveryDomainAndStayInsideItsRegion(): void
    {
        $source = "cynefin-beta\n";
        foreach (CynefinDiagram::DOMAINS as $domain) {
            $source .= $domain."\n".\str_repeat('"日本 wide and long label"'."\n", times: 8);
        }
        $scene = self::scene($source);
        $this->assertCount(45, $scene->elements);
        foreach (CynefinDiagram::DOMAINS as $domain) {
            $region = \array_find($scene->graph->nodes, static fn ($node): bool => $node->name === 'region-'.$domain);
            $this->assertInstanceOf(GraphNode::class, $region);
            $previous = 0.0;
            foreach ($scene->selectionGraph->nodes as $node) {
                if (!\str_starts_with($node->label, \ucfirst($domain).':')) {
                    continue;
                }
                $this->assertGreaterThanOrEqual($previous, $node->y - ($node->h / 2));
                $previous = $node->y + ($node->h / 2);
                $this->assertGreaterThanOrEqual($region->y - ($region->h / 2), $node->y - ($node->h / 2));
                $this->assertLessThanOrEqual($region->y + ($region->h / 2), $node->y + ($node->h / 2));
                $this->assertTrue(MermaidOutline::contains($region, $scene->shapes[$region->id], $node->x - ($node->w / 2), $node->y - ($node->h / 2)));
                $this->assertTrue(MermaidOutline::contains($region, $scene->shapes[$region->id], $node->x + ($node->w / 2), $node->y + ($node->h / 2)));
            }
        }
        $this->assertSame(2, $scene->origin(7));
    }

    public function testFoldsAndCliffFollowThePinnedSeededPaths(): void
    {
        // The pinned `seededRandom` and `hashString`, checked against the browser's own numbers.
        $this->assertSame([0.266_429_208_684_712_65, 0.614_962_960_127_741_1, 0.034_903_447_143_733_5, 0.429_098_088_527_098_3], [
            CynefinBoundaries::random(0),
            CynefinBoundaries::random(112),
            CynefinBoundaries::random(-5000),
            CynefinBoundaries::random(2_147_483_647),
        ]);
        $this->assertSame([-1_061_188_925, 112], [CynefinBoundaries::hash('my-svg'), CynefinBoundaries::hash('p')]);
        $this->assertSame([-1_061_188_925, 42], [CynefinBoundaries::seed(0.0), CynefinBoundaries::seed(42.0)]);
        $scene = self::scene('cynefin-beta');
        [$fold, $level, $cliff] = $scene->graph->edges;
        $regions = \array_column($scene->graph->nodes, null, 'name');
        $top = $regions['region-complex']->y - ($regions['region-complex']->h / 2);
        $bottom = $regions['region-clear']->y + ($regions['region-clear']->h / 2);
        $cross = $regions['region-confusion']->y;
        // Pinned amplitude 8 of an 800-pixel field, on the native 216-unit one.
        $d = (8 * 216) / 800;
        $this->assertEqualsWithDelta(110 + (CynefinBoundaries::random(-1_061_188_925) * $d * 2) - $d, $fold->points[0][0], 0.000_001);
        $this->assertSame([$top, $bottom], [$fold->points[0][1], \array_last($fold->points)[1]]);
        $this->assertSame([2.0, 218.0], [$level->points[0][0], \array_last($level->points)[0]]);
        $this->assertEqualsWithDelta($cross + (CynefinBoundaries::random(-1_061_188_825) * $d * 2) - $d, $level->points[0][1], 0.000_001);
        $this->assertSame([[110.0, $cross], [110.0, $bottom]], [$cliff->points[0], \array_last($cliff->points)]);
        $this->assertSame(['@cynefin.boundaryColor', true, [0.6, 0.3], 0.2], [$fold->color, $fold->dashed, $fold->dashes, $fold->strokeWidth]);
        $this->assertSame(['@cynefin.cliffColor', false, 0.4], [$cliff->color, $cliff->dashed, $cliff->strokeWidth]);
        $this->assertCount(1 + (7 * 12), $fold->points);
        $this->assertCount(1 + 24, $cliff->points);
        $straight = NativeFixtureScene::render("---\nconfig:\n  cynefin:\n    boundaryAmplitude: 0\n---\ncynefin-beta")->graph->edges[0];
        $this->assertSame([110.0], \array_values(\array_unique(\array_column($straight->points, 0))), 'no amplitude, no wobble');
        $seeded = NativeFixtureScene::render("---\nconfig:\n  cynefin:\n    seed: 42\n---\ncynefin-beta")->graph->edges[0];
        $this->assertEqualsWithDelta(110 + (CynefinBoundaries::random(42) * $d * 2) - $d, $seeded->points[0][0], 0.000_001);
        // A quarter into the second segment, its odd sign bows the fold the other way.
        [$x1, $x2] = [110 + (CynefinBoundaries::random(-1_061_188_925 + 17) * $d * 2) - $d, 110 + (CynefinBoundaries::random(-1_061_188_925 + 34) * $d * 2) - $d];
        $g = -$d * 1.5 * CynefinBoundaries::random(-1_061_188_925 + 31 + 7);
        $this->assertEqualsWithDelta((0.843_75 * $x1) + (0.156_25 * $x2) + (0.281_25 * $g), $fold->points[12 + 3][0], 0.000_001);
        $this->assertSame(
            [$cross, $cross],
            [$regions['region-complex']->y + ($regions['region-complex']->h / 2), $regions['region-chaotic']->y - ($regions['region-chaotic']->h / 2)],
            'the quadrants meet at the fold',
        );
        $this->assertSame(['none', GraphFont::Bold, GraphFont::Italic], [
            $regions['region-complex']->color,
            $regions['complex']->font,
            \array_first(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => \str_starts_with($node->name, 'caption-')))->font,
        ]);
        $middle = $fold->points[6];
        $this->assertNotEqualsWithDelta(110.0, $middle[0], 0.001, 'segments bow between their points');
    }

    public function testTransitionRoutesKeepLabelsOutsideDomainsAndPointToTargets(): void
    {
        $scene = self::scene("cynefin-beta\ncomplex --> clear: \"Promote\"\nclear --> chaotic\nconfusion --> complicated : \"Clarify\"\ncomplex --> chaotic\ncomplicated --> clear");
        $transitions = \array_values(\array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => !$edge->decoration));
        $this->assertCount(5, $transitions);
        $lowest = \max(\array_map(static fn (GraphNode $node): float => $node->y + ($node->h / 2), $scene->selectionGraph->nodes));
        $last = 0.0;
        foreach ($transitions as $edge) {
            $from = $scene->graph->nodes[$edge->tail];
            $to = $scene->graph->nodes[$edge->head];
            $this->assertSame($from->y, $edge->points[0][1]);
            $this->assertSame($to->y, $edge->arrowTip[1]);
            $this->assertSame(\array_last($edge->points), $edge->arrowTip);
            $this->assertGreaterThan($lowest, $edge->points[2][1]);
            $this->assertGreaterThan($last, $edge->points[2][1]);
            $last = $edge->points[2][1];
            foreach ($edge->points as [$x, $y]) {
                $this->assertGreaterThanOrEqual($edge->minX, $x);
                $this->assertLessThanOrEqual($edge->maxX, $x);
                $this->assertGreaterThanOrEqual($edge->minY, $y);
                $this->assertLessThanOrEqual($edge->maxY, $y);
            }
        }
        $this->assertSame('Complex → Clear: Promote', $scene->legend[5]['text']);
        $this->assertSame(1, $scene->legend[5]['origin']);
    }

    public function testLongLabelsWrapWithoutLosingSemanticText(): void
    {
        $label = \str_repeat('日本  label ', times: 100);
        $scene = self::scene("cynefin-beta\nclear\n\"".$label.'"');
        $node = \array_last($scene->selectionGraph->nodes);
        $this->assertSame('Clear: '.$label, $node->label);
        $this->assertStringContainsString("\n", $node->drawLabel);
        foreach (\explode("\n", $node->drawLabel) as $line) {
            $this->assertLessThanOrEqual(32, AnsiUtils::visibleWidth($line));
        }
        $long = \str_repeat('x', times: 60_000);
        $this->assertSame($long, self::parse("cynefin-beta\ncomplex\n\"".$long.'"')->domains['complex']['items'][0]['label']);
    }

    #[DataProvider('invalidSources')]
    public function testMalformedDeclarationsAndResourceLimitsAreRejected(string $source, string $error): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($error);
        self::parse($source);
    }

    /** @return iterable<array{string, string}> */
    public static function invalidSources(): iterable
    {
        yield ['', 'Expected cynefin-beta'];
        yield ['cynefin', 'Invalid Cynefin'];
        yield ["cynefin-beta\n\"Orphan\"", 'inside a Cynefin domain'];
        yield ["cynefin-beta\nunknown", 'Invalid Cynefin'];
        yield ["cynefin-beta\ncomplex\n\"Unclosed", 'Invalid Cynefin'];
        yield ["cynefin-beta\ncomplex --> nowhere", 'Invalid Cynefin'];
        yield ["cynefin-beta\ncomplex --> clear: unquoted", 'Invalid Cynefin'];
        yield ["cynefin-beta\ncomplex\n\"One\"\ncomplex --> clear\n\"Orphan\"", 'inside a Cynefin domain'];
        yield ["cynefin-beta\ncomplex\ntitle Ends block\n\"Orphan\"", 'inside a Cynefin domain'];
        yield ["cynefin-beta\ncomplex\n\"".\str_repeat('x', times: 65_537).'"', 'size limit'];
        yield ["cynefin-beta\ncomplex\n".\str_repeat('"Item"'."\n", times: 4097), 'item limit'];
        yield ["cynefin-beta\n".\str_repeat("complex\n", times: 4097), 'domain-block limit'];
        yield ["cynefin-beta\n".\str_repeat("complex --> complex\n", times: 4097), 'transition limit'];
    }

    public function testParserDirectGuardsRejectWrongHeadersAndUnknownTransitionDomains(): void
    {
        try {
            new CynefinParser()->accept(new MermaidStatement('wrong', offset: 0, line: 0, column: 0));
            $this->fail('Wrong header accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertStringContainsString('Expected cynefin-beta', $error->getMessage());
        }
        $parser = new CynefinParser();
        $parser->accept(new MermaidStatement('cynefin-beta', offset: 0, line: 0, column: 0));
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('Unknown Cynefin transition domain');
        $parser->accept(new MermaidStatement('complex --> wrong', offset: 13, line: 1, column: 0));
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
        foreach (new \GlobIterator(__DIR__.'/../fixtures/mermaid/cynefin/*.mmd') as $file) {
            yield [$file->getPathname()];
        }
    }

    public function testCancellationPreventsPartialPublication(): void
    {
        $scheduler = new FakeScheduler();
        $cancel = new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "cynefin-beta\ncomplex\n".\str_repeat('"Item"'."\n", times: 3000),
            static function (): never {
                self::fail('Cancelled Cynefin published');
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
    public function testNativeExportsEscapeLabelsAndDrawTransitions(GraphExportFormat $format): void
    {
        $result = NativeFixtureExport::render("cynefin-beta\ncomplex\n\"<script>\"\ncomplex --> clear: \"A &amp; B\"", $format);
        if (GraphExportFormat::Svg === $format) {
            $this->assertStringContainsString('&lt;script&gt;', $result);
            $this->assertStringContainsString('A &amp; B', $result);
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
