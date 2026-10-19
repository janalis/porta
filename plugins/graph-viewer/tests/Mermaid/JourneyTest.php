<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\JourneyDiagram;
use Porta\GraphViewer\Mermaid\JourneyFaces;
use Porta\GraphViewer\Mermaid\JourneyLayout;
use Porta\GraphViewer\Mermaid\JourneyLexer;
use Porta\GraphViewer\Mermaid\JourneyParser;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidPalette;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class JourneyTest extends TestCase
{
    private static function parse(string $source): JourneyDiagram
    {
        $parser = new JourneyParser();
        foreach (new JourneyLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testChronologyMetadataAndActors(): void
    {
        $diagram = self::parse(
            "%% intro\njourney title Work: day\naccTitle: Literal # ; %%\naccDescr {\nLong description\n}section Morning\nWake: 5: Me, Cat\nsection Morning\nTravel: 2\nsection Empty\nsection Evening\nCook: 3.5: Me,,Me\nSleep: 5:",
        );
        $this->assertSame('Work: day', $diagram->metadata['title']['label']);
        $this->assertSame('Literal # ; %%', $diagram->metadata['acctitle']['label']);
        $this->assertSame('Long description', $diagram->metadata['accdescr']['label']);
        $this->assertSame(['Morning', 'Morning', 'Empty', 'Evening'], \array_column($diagram->sections, 'label'));
        $this->assertSame([['Me', 'Cat'], [], ['Me', '', 'Me'], ['']], \array_column($diagram->tasks, 'actors'));
        $this->assertSame([0, 1, 3, 3], \array_column($diagram->tasks, 'section'));
        $this->assertSame([5.0, 2.0, 3.5, 5.0], \array_column($diagram->tasks, 'score'));
        $this->assertSame([6, 8, 11, 12], \array_column($diagram->tasks, 'origin'));
        $work = new JourneyLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn()->themed(MermaidPalette::defaults('journey'));
        $nodes = \array_column($scene->graph->nodes, null, 'name');
        // Pinned columns: 150-pixel boxes 200 apart from `leftMargin`, sections over their tasks.
        $this->assertSame([22.5, 42.5, 62.5, 82.5], [$nodes['task:0']->x, $nodes['task:1']->x, $nodes['task:2']->x, $nodes['task:3']->x]);
        $this->assertSame(['Morning', 'Morning', 'Evening'], [$nodes['section:0']->label, $nodes['section:1']->label, $nodes['section:3']->label]);
        $this->assertArrayNotHasKey('section:2', $nodes, 'a section without tasks draws nothing');
        $this->assertSame([35.0, 15.0], [$nodes['section:3']->w, $nodes['section:0']->w], 'a section spans its tasks');
        // Faces sit 30 pixels lower per point below five; a score of 3.5 smiles, as above 3.
        $this->assertSame([32.5, 41.5, 37.0, 32.5], [$nodes['face:0']->y, $nodes['face:1']->y, $nodes['face:2']->y, $nodes['face:3']->y]);
        $this->assertSame(['journey-face:5', 'journey-face:3.5'], [$scene->shapes[$nodes['face:0']->id], $scene->shapes[$nodes['face:2']->id]]);
        $this->assertSame([2.0, 'Work: day'], [$nodes['title']->fontScale, $nodes['title']->label]);
        $this->assertSame(['#8fbc8f', '#7cfc00'], [$nodes['dot:Cat']->fillColor, $nodes['dot:Me']->fillColor], 'actors take their colors in sorted order');
        $arrow = \array_find($scene->graph->edges, static fn ($edge): bool => 0.4 === $edge->strokeWidth);
        $this->assertSame([15.0, 94.6], [$arrow?->points[0][0], $arrow?->arrowTip[0]]);
        $view = new ZoomPanModel(124, (int) $scene->graph->height, [1.0]);
        $this->assertNotEmpty(GraphRenderer::render($scene->graph, $view, null, 124, (int) \ceil($scene->graph->height / 2), Styles::dark()));

        $this->assertSame('1. Wake | score: 5/5 | actors: ["Me","Cat"]', $scene->legend[4]['text']);
        $this->assertSame(6, $scene->legend[4]['origin']);
        $this->assertCount((int) \ceil($scene->graph->height / 2), $scene->origins);
    }

    public function testEmptyTrailingSectionsAndLiteralNames(): void
    {
        $diagram = self::parse("JOURNEY\naccDescr: details\n\"漢字 [box]\": 1\nsection Final\n#comment");
        $this->assertSame('"漢字 [box]"', $diagram->tasks[0]['label']);
        $this->assertNull($diagram->tasks[0]['section']);
        $work = new JourneyLayout()->layout($diagram);
        \iterator_to_array($work);
        $this->assertSame('section: Final', $work->getReturn()->legend[3]['text']);
        $work = new JourneyLayout()->layout(self::parse('journey'));
        \iterator_to_array($work);
        $this->assertSame('title: Journey', $work->getReturn()->legend[0]['text']);
    }

    public function testScheduledCancellationAndNarrowRows(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $result = null;
        $error = null;
        $success = static function ($value) use (&$result): void {
            $result = $value;
        };
        $failure = static function (string $value) use (&$error): void {
            $error = $value;
        };
        $cancel = $renderer->render("journey\n".\str_repeat('漢', times: 15_000).':5', $success, $failure);
        $scheduler->fire();
        $this->assertNull($result);
        $this->assertNotEmpty($scheduler->scheduled);
        $cancel();
        $this->assertSame([], $scheduler->scheduled);
        $renderer->rows("journey\nTask:5:A", width: 1, success: $success, error: $failure);
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertNull($error);
        $this->assertStringContainsString('Task|score:5/5|actors:["A"]', \implode('', $result->rows));
        $this->assertContains(1, $result->origins);
    }

    #[DataProvider('invalidSources')]
    public function testInvalidSyntax(string $source, string $message): void
    {
        $this->expectException(MermaidSyntaxError::class);
        $this->expectExceptionMessageIsOrContains($message);
        self::parse($source);
    }

    public static function invalidSources(): iterable
    {
        yield ['', 'Expected journey'];
        yield ['wrong', 'Expected journey'];
        foreach (['Task', 'Task: bad', ':5', 'Task: 5: A: B', 'section A:5', 'Task:5;'] as $task) {
            yield ["journey\n".$task, 'Expected journey task'];
        }
        foreach (['0', '6', '1e999'] as $score) {
            yield ["journey\nTask: ".$score, 'Journey score'];
        }
        yield ["journey\n".\str_repeat('x', times: 65_537), 'Statement exceeds'];
        yield ["journey\n".\str_repeat("Task:5\n", times: 4097), 'journey task limit'];
        yield ["journey\n".\str_repeat("section A\n", times: 4097), 'journey section limit'];
        yield ["journey\nTask:5:".\str_repeat('a,', times: 4096), 'journey actor limit'];
    }

    public function testFacesSmileFrownOrLevelAsPinned(): void
    {
        $node = new GraphNode(0, 'face', '', 10.0, 10.0, 3.0, 3.0, '#999999', '#ffffff', dashed: false);
        $mouth = static fn (float $score): MermaidMark => \array_last(JourneyFaces::marks($node, JourneyFaces::shape($score)));
        $this->assertCount(4, JourneyFaces::marks($node, JourneyFaces::shape(5.0)), 'a face, two eyes and a mouth');
        // The smile's middle hangs below its ends; the frown's rises above them; 3 is level.
        $smile = $mouth(4.0)->points;
        $this->assertGreaterThan($smile[0][1], $smile[12][1]);
        $frown = $mouth(2.0)->points;
        $this->assertLessThan($frown[0][1], $frown[12][1]);
        $this->assertSame([[9.5, 10.7], [10.5, 10.7]], $mouth(3.0)->points);
        $this->assertSame([[9.5, 10.7], [10.5, 10.7]], \array_last(JourneyFaces::marks($node, 'journey-face:x'))->points, 'a malformed score reads as level');
        $face = JourneyFaces::marks($node, 'journey-face:1')[0];
        $this->assertSame(['#999999', '#ffffff', [10.0, 8.5]], [$face->stroke, $face->fill, $face->points[0]], 'a 15-pixel face in its theme color');
    }

    public function testTitleAndTaskSizesFollowTheirOptions(): void
    {
        $body = "journey\ntitle T\nsection S\nA: 5: Me\n";
        $nodes = static fn (MermaidScene $scene): array => \array_column($scene->graph->nodes, null, 'name');
        $sized = $nodes(NativeFixtureScene::render("---\nconfig:\n  journey:\n    titleFontSize: '48px'\n    taskFontSize: '28px'\n---\n".$body));
        $this->assertSame([3.0, 2.0, 2.0], [$sized['title']->fontScale, $sized['task:0']->fontScale, $sized['section:0']->fontScale]);
        $this->assertSame(6.0, $sized['task:0']->h, 'a larger task text grows its box');
        $ex = $nodes(NativeFixtureScene::render("---\nconfig:\n  journey:\n    titleFontSize: 6ex\n---\n".$body));
        $this->assertSame(3.0, $ex['title']->fontScale);
        $this->assertSame(2.0, $nodes(NativeFixtureScene::render("---\nconfig:\n  journey:\n    titleFontSize: nonsense\n---\n".$body))['title']->fontScale);
        // Pinned CSS fills a face with `faceColor`, which no theme sets and authors cannot: cornsilk.
        $this->assertSame('#fff8dc', $nodes(NativeFixtureScene::render($body))['face:0']->fillColor);
        $this->assertSame('#fff8dc', $nodes(NativeFixtureScene::render("---\nconfig:\n  theme: base\n  themeVariables:\n    faceColor: '#123456'\n---\n".$body))['face:0']->fillColor);
        // A box tall enough to reach its face leaves only the line beneath the face.
        $high = NativeFixtureScene::render("---\nconfig:\n  journey:\n    taskFontSize: 56\n---\njourney\nsection S\naaa bbb ccc: 5: Me\n");
        $this->assertCount(2, $high->graph->edges, 'one task line piece and the timeline');
    }
}
