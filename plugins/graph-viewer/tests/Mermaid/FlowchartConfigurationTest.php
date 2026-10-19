<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\FlowchartConfiguration;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidGraph;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidNodeMetrics;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\MermaidTitle;
use Porta\Tests\Support\FakeScheduler;

final class FlowchartConfigurationTest extends TestCase
{
    #[DataProvider('directions')]
    public function testRequestedRankAndNodeSpacingKeepAllDirectionsAndSourceOrigins(string $direction): void
    {
        $body = 'flowchart '.$direction."\nA-->B\nA-->C";
        $small = NativeFixtureScene::render(self::source(['rankSpacing' => 0, 'nodeSpacing' => 0], $body));
        $large = NativeFixtureScene::render(self::source(['rankSpacing' => 100, 'nodeSpacing' => 100], $body));
        $horizontal = \in_array($direction, ['LR', 'RL'], strict: true);
        foreach ([[$small, 0.0], [$large, 10.0]] as [$scene, $gap]) {
            $a = self::node($scene, 'A');
            $b = self::node($scene, 'B');
            $c = self::node($scene, 'C');
            $rank = $horizontal ? \abs($a->x - $b->x) - (($a->w + $b->w) / 2) : \abs($a->y - $b->y) - (($a->h + $b->h) / 2);
            $node = $horizontal ? \abs($b->y - $c->y) - (($b->h + $c->h) / 2) : \abs($b->x - $c->x) - (($b->w + $c->w) / 2);
            $this->assertEqualsWithDelta($gap, $rank, delta: 0.000_01);
            $this->assertEqualsWithDelta($gap, $node, delta: 0.000_01);
            $this->assertSame(5, $scene->origin($b->id));
        }
    }

    public static function directions(): iterable
    {
        foreach (['TD', 'BT', 'LR', 'RL'] as $direction) {
            yield $direction => [$direction];
        }
    }

    #[DataProvider('inheritance')]
    public function testNestedSubgraphsUseTheRequestedGlobalOrDefaultDirection(bool $inherit, string $root, int $dx, int $dy): void
    {
        $body = 'flowchart '.$root."\nsubgraph Outer\ndirection BT\nsubgraph Inner\nA-->B\nend\nend";
        $scene = NativeFixtureScene::render(self::source(['inheritDir' => $inherit], $body));
        $a = self::node($scene, 'A');
        $b = self::node($scene, 'B');
        $this->assertSame($dx, ($b->x - $a->x) <=> 0);
        $this->assertSame($dy, ($b->y - $a->y) <=> 0);
    }

    public static function inheritance(): iterable
    {
        yield 'default LR' => [false, 'LR', 0, 1];
        yield 'default RL' => [false, 'RL', 0, 1];
        yield 'default TD' => [false, 'TD', 0, 1];
        yield 'default BT' => [false, 'BT', 0, 1];
        yield 'global LR' => [true, 'LR', 1, 0];
        yield 'global RL' => [true, 'RL', -1, 0];
        yield 'global TD' => [true, 'TD', 0, 1];
        yield 'global BT' => [true, 'BT', 0, -1];
    }

    public function testExplicitAndExternallyLinkedSubgraphDirectionsRetainTheirPrecedence(): void
    {
        foreach ([false, true] as $inherit) {
            $explicit = NativeFixtureScene::render(self::source(['inheritDir' => $inherit], "flowchart TD\nsubgraph Team\ndirection LR\nA-->B\nend"));
            $this->assertSame(self::node($explicit, 'A')->y, self::node($explicit, 'B')->y);
            $this->assertGreaterThan(self::node($explicit, 'A')->x, self::node($explicit, 'B')->x);
            $linked = NativeFixtureScene::render(self::source(['inheritDir' => $inherit], "flowchart LR\nsubgraph Team\nA-->B\nend\nB-->X"));
            $this->assertSame(self::node($linked, 'A')->y, self::node($linked, 'B')->y);
            $this->assertGreaterThan(self::node($linked, 'A')->x, self::node($linked, 'B')->x);
        }
    }

    public function testLabelPaddingMinimumWidthsShapeFittingAndExplicitSizesShareOneMeasurement(): void
    {
        $small = NativeFixtureScene::render(self::source(['padding' => 0, 'minNodeWidth' => 0], "flowchart TD\nA[Label]"));
        $large = NativeFixtureScene::render(self::source(['padding' => 40, 'minNodeWidth' => 200], "flowchart TD\nA[Label]"));
        $this->assertSame([5.0, 2.0], [self::node($small, 'A')->w, self::node($small, 'A')->h]);
        $this->assertSame([28.0, 10.0], [self::node($large, 'A')->w, self::node($large, 'A')->h]);
        $circle = NativeFixtureScene::render(self::source(['padding' => 40, 'minNodeWidth' => 200], "flowchart TD\nA((Label))"));
        $this->assertSame([28.0, 28.0], [self::node($circle, 'A')->w, self::node($circle, 'A')->h]);
        $hidden = NativeFixtureScene::render(self::source(['padding' => 1000, 'minNodeWidth' => 1000], "flowchart TD\nA@{shape: sm-circ}"));
        $this->assertSame([2.0, 2.0], [self::node($hidden, 'A')->w, self::node($hidden, 'A')->h]);
        $configuration = FlowchartConfiguration::parse((object) ['padding' => 40, 'minNodeWidth' => 200], new MermaidStatement('', offset: 0, line: 0, column: 0));
        $graph = new MermaidGraph(flowchart: $configuration);
        $graph->node('A', 'Label', new MermaidStatement('', offset: 0, line: 0, column: 0), []);
        $graph->sizes['A'] = [7.0, 8.0];
        $this->assertSame([7.0, 8.0], MermaidNodeMetrics::measure($graph, 'A'));
    }

    public function testDiagramPaddingMovesTheEntireGraphInsideItsCanvas(): void
    {
        $scene = NativeFixtureScene::render(self::source(['diagramPadding' => 100], "flowchart TD\nA[Label]"));
        $a = self::node($scene, 'A');
        $this->assertSame(10.0, $a->x - ($a->w / 2));
        $this->assertSame(10.0, $a->y - ($a->h / 2));
        $this->assertSame($a->w + 20, $scene->graph->width);
        $this->assertSame($a->h + 20, $scene->graph->height);
    }

    public function testSubgraphMarginsMoveTitlesChildrenAndBoundsTogether(): void
    {
        $body = "flowchart TD\nsubgraph Team\nA-->B\nend\nstyle Team font-size:32";
        $base = NativeFixtureScene::render(self::source([], $body));
        $spaced = NativeFixtureScene::render(self::source(['subGraphTitleMargin' => ['top' => 30, 'bottom' => 70]], $body));
        $old = $base->graph->clusters[0];
        $new = $spaced->graph->clusters[0];
        $this->assertSame(10.0, $new->y1 - $new->y0 - ($old->y1 - $old->y0));
        $this->assertSame(10.0, self::node($spaced, 'A')->y - self::node($base, 'A')->y);
        $this->assertSame([($new->x0 + $new->x1) / 2, $new->y0 + 7], $new->labelPos);
        $work = MermaidDrawing::graph($spaced->graph, $spaced->shapes);
        \iterator_to_array($work);
        $labels = \array_filter($work->getReturn()->marks, static fn (MermaidMark $mark): bool => 'text' === $mark->kind && 'Team' === $mark->text);
        $this->assertCount(1, $labels);
        $this->assertSame([$new->labelPos], \array_first($labels)->points);
        foreach ($spaced->graph->nodes as $node) {
            $this->assertGreaterThan($new->y0, $node->y - ($node->h / 2));
            $this->assertLessThan($new->y1, $node->y + ($node->h / 2));
        }
    }

    #[DataProvider('titleMargins')]
    public function testTitleMarginsRetainSmallNodeSourceRowsAndSelection(int $margin): void
    {
        $source = "---\ntitle: Heading\nconfig: {flowchart: {titleTopMargin: ".$margin."}}\n---\nflowchart TD\nA@{shape: sm-circ}\nB[After]\nA-->B";
        $scene = NativeFixtureScene::render($source);
        $title = self::node($scene, 'frontmatter-title');
        $a = self::node($scene, 'A');
        $this->assertEqualsWithDelta($margin / 10, $title->y - ($title->h / 2), delta: 0.000_01);
        $this->assertNull($scene->selectable($title->id));
        $this->assertSame(5, $scene->origin($a->id));
        $this->assertSame(5, $scene->origins[(int) \floor($a->y / 2)]);
    }

    public static function titleMargins(): iterable
    {
        foreach ([0, 1, 3, 7, 10, 1000] as $margin) {
            yield (string) $margin => [$margin];
        }
    }

    public function testFractionalTitleTranslationResamplesAdjacentSourceRows(): void
    {
        $source = new MermaidScene(new GraphLayout(20, 10, [], [], []), [10, 11, 12, 13, 14], []);
        $work = MermaidTitle::apply($source, new MermaidStatement('Heading', offset: 0, line: 1, column: 0), topMargin: 0.1);
        \iterator_to_array($work);
        // The row starting at y=8 samples original y=0.9 after the 7.1-unit translation.
        $this->assertSame([1, 1, 1, 1, 10, 11, 12, 13, 14], $work->getReturn()->origins);
    }

    public function testLargeTitleSourceMapsStayCancellable(): void
    {
        $scheduler = new FakeScheduler();
        $scene = new MermaidScene(new GraphLayout(20, 200_000, [], [], []), \array_fill(0, count: 100_000, value: 5), []);
        $work = MermaidTitle::apply($scene, new MermaidStatement('Heading', offset: 0, line: 1, column: 0), topMargin: 0.1);
        $complete = false;
        $cancel = new MermaidTask($scheduler)->run(
            $work,
            static function (MermaidScene $result) use (&$complete): void {
                $complete = true;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $scheduler->fire();
        $this->assertFalse($complete);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertEmpty($scheduler->scheduled);
        $this->assertFalse($complete);
        $this->assertCount(100_000, $scene->origins);
    }

    public function testDocumentOverlaysRetainZeroAndBothSubgraphMargins(): void
    {
        $statement = new MermaidStatement('', offset: 0, line: 2, column: 3);
        $base = MermaidConfiguration::parse((object) ['flowchart' => (object) ['nodeSpacing' => 80, 'subGraphTitleMargin' => (object) ['top' => 30, 'bottom' => 70]]], $statement);
        $later = MermaidConfiguration::parse((object) ['flowchart' => (object) ['nodeSpacing' => 0, 'subGraphTitleMargin' => (object) ['bottom' => 0]]], $statement);
        $merged = $base->overlay($later);
        $this->assertInstanceOf(FlowchartConfiguration::class, $merged->flowchart);
        $this->assertSame(0.0, $merged->flowchart->spacing('nodeSpacing', default: 6.0));
        $this->assertSame(3.0, $merged->flowchart->titleGap());
        $empty = MermaidConfiguration::parse((object) ['flowchart' => new \stdClass()], $statement);
        $this->assertInstanceOf(FlowchartConfiguration::class, $empty->flowchart);
        $this->assertFalse($empty->flowchart->configured());
        $this->assertEquals($base->flowchart, $base->overlay($empty)->flowchart);
        $this->assertSame(0.05, FlowchartConfiguration::parse((object) ['padding' => 0.5], $statement)->spacing('padding', default: 2.0));
    }

    public function testLeadingDirectivesOverlayZeroWithoutLeakingToTheNextDocument(): void
    {
        $body = "flowchart TD\nA-->B\nA-->C";
        $source = self::source(['rankSpacing' => 80, 'nodeSpacing' => 80], "%%{init: {\"flowchart\": {\"rankSpacing\": 0, \"nodeSpacing\": 0}}}%%\n".$body);
        $overlaid = NativeFixtureScene::render($source);
        $a = self::node($overlaid, 'A');
        $b = self::node($overlaid, 'B');
        $this->assertSame(($a->h + $b->h) / 2, $b->y - $a->y);
        $next = NativeFixtureScene::render($body);
        $c = self::node($next, 'A');
        $d = self::node($next, 'B');
        $this->assertSame(8.0, $d->y - $c->y - (($c->h + $d->h) / 2));
    }

    public function testTitleUsesTheEffectiveDirectiveMargin(): void
    {
        $source = "---\ntitle: Heading\nconfig: {flowchart: {titleTopMargin: 10}}\n---\n%%{init: {\"flowchart\": {\"titleTopMargin\": 30}}}%%\nflowchart TD\nA-->B";
        $scene = NativeFixtureScene::render($source);
        $title = self::node($scene, 'frontmatter-title');
        $this->assertSame(3.0, $title->y - ($title->h / 2));
    }

    public function testBrowserSizingAndMarkerFlagsLeaveIntrinsicNativeGraphicsUnchanged(): void
    {
        $body = "flowchart TD\nA-->B";
        $plain = NativeFixtureExport::render($body, GraphExportFormat::Svg);
        foreach ([true, false] as $flag) {
            $source = self::source(['useMaxWidth' => $flag, 'arrowMarkerAbsolute' => $flag], $body);
            $this->assertSame($plain, NativeFixtureExport::render($source, GraphExportFormat::Svg));
        }
    }

    #[DataProvider('invalid')]
    public function testUnsupportedValuesHaveTheirConfigurationOrigin(mixed $options): void
    {
        $statement = new MermaidStatement('', offset: 10, line: 2, column: 3);
        try {
            FlowchartConfiguration::parse($options, $statement);
            $this->fail('Invalid flowchart options were accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame(2, $error->sourceLine);
            $this->assertSame(3, $error->column);
            $this->assertStringContainsString('flowchart', \strtolower($error->getMessage()));
        }
    }

    public static function invalid(): iterable
    {
        foreach ([null, [], true, 1, 'mapping'] as $value) {
            yield 'section '.\get_debug_type($value) => [$value];
        }
        foreach (['nodeSpacing', 'rankSpacing', 'diagramPadding', 'titleTopMargin'] as $key) {
            foreach ([-1, 1001, 0.5, '0.5', 'fifty', true, null, []] as $value) {
                yield $key.' '.\get_debug_type($value).' '.\serialize($value) => [(object) [$key => $value]];
            }
        }
        foreach (['padding', 'minNodeWidth'] as $key) {
            foreach ([-1, 1001, \NAN, \INF, '1001', 'fifty', false, null, []] as $value) {
                yield $key.' '.\serialize($value) => [(object) [$key => $value]];
            }
        }
        foreach (['useMaxWidth', 'arrowMarkerAbsolute', 'inheritDir'] as $key) {
            yield $key => [(object) [$key => 'true']];
        }
        foreach ([null, [], 1, 'mapping'] as $value) {
            yield 'margin section '.\get_debug_type($value) => [(object) ['subGraphTitleMargin' => $value]];
        }
        foreach (['top' => -1, 'bottom' => 1001, 'left' => 0, 'Top' => 0] as $edge => $value) {
            yield 'margin '.$edge => [(object) ['subGraphTitleMargin' => (object) [$edge => $value]]];
        }
        yield 'fractional margin' => [(object) ['subGraphTitleMargin' => (object) ['top' => 0.5]]];
    }

    public function testFamilySpecificGeometryCannotLeakIntoOtherDiagrams(): void
    {
        $statement = new MermaidStatement('', offset: 10, line: 2, column: 3);
        $configuration = MermaidConfiguration::parse((object) ['flowchart' => (object) ['padding' => 0]], $statement);
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains('does not apply to this diagram family');
        $configuration->validateFamily('sequence');
    }

    private static function node(MermaidScene $scene, string $name): GraphNode
    {
        $node = \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        self::assertInstanceOf(GraphNode::class, $node);

        return $node;
    }

    /** @param array<string, mixed> $options */
    private static function source(array $options, string $body): string
    {
        return "---\nconfig:\n  flowchart: ".\json_encode((object) $options, flags: \JSON_THROW_ON_ERROR)."\n---\n".$body;
    }
}
