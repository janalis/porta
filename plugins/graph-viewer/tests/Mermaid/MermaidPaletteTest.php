<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\GitColors;
use Porta\GraphViewer\Mermaid\GitMarkers;
use Porta\GraphViewer\Mermaid\MermaidColor;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidCurvePath;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidPalette;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStyleSource;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Mermaid\Theme\MermaidDirective;
use Porta\Tests\Support\FakeScheduler;

/** The palette's token grammar and the theme paths no census family reaches. */
final class MermaidPaletteTest extends TestCase
{
    public function testTokensFadeReadNestedListsAndChooseWhichThemeAnswers(): void
    {
        $palette = MermaidPalette::defaults('flowchart');
        $this->assertSame('#ffffff80', $palette->resolve('@mainBkg@50'));
        $this->assertNull($palette->resolve('@noSuchVariable@50'));
        $this->assertSame('none', $palette->resolve('@=none@50'));
        $this->assertSame($palette->resolve('@nodeBorder'), $palette->resolve('@!noSuchVariable|nodeBorder'));
        // The flowchart's redux-color theme lacks a `radar` object; the default theme's answers.
        $this->assertSame('#dedede', $palette->resolve('@radar.graticuleColor'));
        $this->assertNull($palette->resolve('@!radar.graticuleColor'));
        $this->assertSame('#ffffde', $palette->resolve('@^secondBkg'));
        $this->assertNull($palette->resolve('@secondBkg'));
        $plots = MermaidPalette::defaults('xychart-beta');
        $count = \count(\explode(',', (string) $plots->appearance->variables->xyChart->plotColorPalette));
        $this->assertSame($plots->resolve('@xyChart.plotColorPalette[1]'), $plots->resolve('@xyChart.plotColorPalette['.($count + 1).']'));
        $this->assertNotSame($plots->resolve('@xyChart.plotColorPalette[0]'), $plots->resolve('@xyChart.plotColorPalette[1]'));
        $this->assertNull(MermaidColor::css('hsl(240, 100%, NaN%)'));
        $this->assertSame('#ff000080', MermaidColor::css('rgba(255, 0, 0, 0.5)'));
    }

    public function testThemeStrokeWidthsApplyOnlyToDefaultOutlinesOfTheirFamilies(): void
    {
        $graph = new GraphLayout(
            10.0,
            10.0,
            [
                new GraphNode(0, 'a', 'a', 1.0, 1.0, 2.0, 2.0, null, null, dashed: false),
                new GraphNode(1, 'b', 'b', 5.0, 1.0, 2.0, 2.0, null, null, dashed: false, strokeWidth: 0.5),
            ],
            [new GraphEdge(0, 1, [[1.0, 1.0], [5.0, 1.0]], null, 1.0, 1.0, 5.0, 1.0, null, null, null, dashed: false)],
            clusters: [],
        );
        // Redux themes draw 2px outlines; authored widths stay.
        $flowchart = MermaidPalette::defaults('flowchart')->paint($graph);
        $this->assertEqualsWithDelta(0.24, $flowchart->nodes[0]->strokeWidth, 1e-9);
        $this->assertSame(0.5, $flowchart->nodes[1]->strokeWidth);
        $this->assertEqualsWithDelta(0.24, $flowchart->edges[0]->strokeWidth, 1e-9);
        $this->assertSame(0.12, MermaidPalette::defaults('pie')->paint($graph)->nodes[0]->strokeWidth);
        // ER outlines follow the theme only under the neo look.
        $this->assertEqualsWithDelta(0.24, MermaidPalette::defaults('erDiagram')->paint($graph)->nodes[0]->strokeWidth, 1e-9);
        $classic = NativeFixtureScene::render("---\nconfig: {look: classic}\n---\nerDiagram\nA ||--o{ B : has");
        $this->assertSame(0.12, $classic->palette?->paint($graph)->nodes[0]->strokeWidth);
    }

    public function testGitGlyphsResolveTheirTokenAndStayWhiteWithoutOne(): void
    {
        $palette = MermaidPalette::defaults('gitGraph');
        $this->assertSame(['git:MERGE:|#ececff', 'git:NORMAL:'], $palette->shapes(['git:MERGE:|@primaryColor', 'git:NORMAL:']));
        $this->assertSame(['git:MERGE:|#ffffff'], $palette->shapes(['git:MERGE:|@noSuchVariable']));
        $this->assertSame(['MERGE', null, '#ffffff'], GitMarkers::parse('git:MERGE:|@primaryColor'));
        $this->assertSame(['NORMAL', 'HIGHLIGHT', '#123456'], GitMarkers::parse('git:NORMAL:HIGHLIGHT|#123456'));
    }

    public function testGitLanesFollowEachThemeFamily(): void
    {
        $classic = new GitColors();
        $this->assertSame('@git3', $classic->commit(3));
        $this->assertSame('@git1', $classic->commit(9));
        $this->assertSame(['@gitInv0', '@gitInv0'], $classic->highlight(0));
        $this->assertSame(['@git2', 'none', '@gitBranchLabel2'], $classic->branch(2));
        $this->assertSame('@primaryColor', $classic->inner());
        $this->assertSame(['@commitLabelBackground@50', '@commitLabelColor'], $classic->caption());
        $neo = new GitColors('neo');
        $this->assertSame('@nodeBorder', $neo->commit(0));
        $this->assertSame('@git1', $neo->commit(1));
        $this->assertSame(['@gitInv1', '@gitInv1'], $neo->highlight(1));
        $this->assertSame(['@mainBkg', '@nodeBorder', '@nodeBorder'], $neo->branch(0));
        $this->assertSame(['@mainBkg', '@nodeBorder', '@gitBranchLabel1'], $neo->branch(1));
        $this->assertSame(['none', '@nodeBorder'], $neo->caption());
        $redux = new GitColors('redux');
        $this->assertSame('@nodeBorder', $redux->commit(1));
        $this->assertSame(['@nodeBorder', '@nodeBorder'], $redux->highlight(1));
        $this->assertSame('@mainBkg', $redux->inner());
        $color = new GitColors('redux-color');
        $this->assertSame('@slot:stroke:1', $color->commit(1));
        $this->assertSame(['@mainBkg', '@nodeBorder'], $color->highlight(0));
        $this->assertSame(['@slot:stroke:2', '@slot:stroke:2'], $color->highlight(2));
        $this->assertSame(['@slot:stroke:1', '@slot:stroke:1', '@nodeBorder'], $color->branch(1));
        $this->assertSame(['@mainBkg', '@slot:stroke:1', '@nodeBorder'], new GitColors('redux-dark-color')->branch(1));
    }

    public function testUnparseableThemeColorsFallBackWithoutFailingThePicture(): void
    {
        // An unchosen default theme keeps authored variables as written, unparseable or not.
        $kanban = NativeFixtureScene::render("---\nconfig: {themeVariables: {cScale2: zzz}}\n---\nkanban\ntodo\n  a");
        $this->assertContains('#f2f4f8', \array_column($kanban->graph->nodes, 'fillColor'));
        $venn = NativeFixtureScene::render("---\nconfig: {venn: {theme: default}, themeVariables: {venn1: zzz}}\n---\nvenn-beta\nset A");
        $this->assertNotEmpty($venn->graph->nodes);
        $neo = NativeFixtureScene::render("---\nconfig: {look: neo}\n---\nmindmap\nroot\n  a");
        $branch = \array_find($neo->graph->nodes, static fn (GraphNode $node): bool => 'a' === $node->label);
        $this->assertSame($neo->palette?->color('cScale1'), $branch?->fillColor);
    }

    public function testAThemeThatCannotDeriveItsColorsIsALocatedError(): void
    {
        $scheduler = new FakeScheduler();
        $errors = [];
        new NativeMermaidRenderer(new MermaidTask($scheduler))->render(
            "---\nconfig: {theme: base, themeVariables: {primaryColor: zzz}}\n---\nflowchart TD\nA",
            static function (): void {},
            static function (string $error) use (&$errors): void {
                $errors[] = $error;
            },
        );
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Mermaid theme configuration cannot be drawn', $errors[0]);
    }

    public function testStyleSourceRemembersTheLastSixteenAppearances(): void
    {
        $source = new MermaidStyleSource();
        $configuration = new MermaidConfiguration();
        $first = $source->appearance($configuration, 'type-0', 'flowchart');
        $this->assertSame($first, $source->appearance($configuration, 'type-0', 'flowchart'));
        $second = $source->appearance($configuration, 'type-1', 'flowchart');
        for ($index = 2; $index <= 16; ++$index) {
            $source->appearance($configuration, 'type-'.$index, 'flowchart');
        }
        $this->assertNotSame($first, $source->appearance($configuration, 'type-0', 'flowchart'));
        $this->assertNotSame($second, $source->appearance($configuration, 'type-1', 'flowchart'));
        $this->assertSame($source->appearance($configuration, 'type-16', 'flowchart'), $source->appearance($configuration, 'type-16', 'flowchart'));
    }

    public function testFamilyLiteralsAndAxesFollowTheirPinnedRoles(): void
    {
        // A horizontal chart's left line is its x axis.
        $source = "---\nconfig: {themeVariables: {xyChart: {xAxisLineColor: '#ff0000', yAxisLineColor: '#0000ff'}}}\n---\nxychart-beta %s\nx-axis [a, b]\nbar [1, 2]";
        $vertical = NativeFixtureScene::render(\sprintf($source, ''));
        $horizontal = NativeFixtureScene::render(\sprintf($source, 'horizontal'));
        $this->assertSame(['#0000ff', '#ff0000'], [$vertical->graph->edges[0]->color, $vertical->graph->edges[1]->color]);
        $this->assertSame(['#ff0000', '#0000ff'], [$horizontal->graph->edges[0]->color, $horizontal->graph->edges[1]->color]);
        // Pinned `build` overlays keep a black outline; other components take the theme's.
        $wardley = NativeFixtureScene::render("wardley-beta\ncomponent A [0.2, 0.2] (build)\ncomponent B [0.6, 0.6]");
        $nodes = \array_column($wardley->graph->nodes, null, 'name');
        $this->assertSame('#000000', $nodes['A']->color);
        $this->assertSame($wardley->palette?->color('wardley.componentStroke'), $nodes['B']->color);
        $this->assertNotSame('#000000', $nodes['B']->color);
    }

    public function testUnthemedScenesDrawAsLaidOut(): void
    {
        $node = new GraphNode(0, 'a', 'a', 2.0, 2.0, 2.0, 2.0, '#123456', '#abcdef', dashed: false);
        $work = MermaidDrawing::scene(new MermaidScene(new GraphLayout(4.0, 4.0, [$node], [], clusters: []), [], [], [], [0 => 'rect']));
        \iterator_to_array($work);
        $this->assertSame('#abcdef', $work->getReturn()->marks[0]->fill);
    }

    public function testDirectiveArraysSanitizeOnlyTheirObjects(): void
    {
        $sanitized = MermaidDirective::bundled()->sanitize((object) ['fontSize' => [1, (object) ['noSuchKey' => 1, 'fontSize' => 2]]]);
        $this->assertEquals([1, (object) ['fontSize' => 2]], $sanitized->fontSize);
    }

    public function testCurvesRejectNonFiniteCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MermaidCurvePath()->line([\NAN, 0.0]);
    }
}
