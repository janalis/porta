<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\JourneyDiagram;
use Janalis\Viz\Mermaid\JourneyLayout;
use Janalis\Viz\Mermaid\JourneyLexer;
use Janalis\Viz\Mermaid\JourneyParser;
use Janalis\Viz\Mermaid\MermaidPalette;
use PHPUnit\Framework\TestCase;
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
}
