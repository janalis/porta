<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\FlowchartParser;
use Janalis\Viz\Mermaid\MermaidGraph;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\RankedGraphLayout;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\Help\Fence\FenceResult;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeScheduler;
use Porta\Ui\Theme\Styles;

final class NativeMermaidTest extends TestCase
{
    private static function graph(string $source): MermaidGraph
    {
        $parser = new FlowchartParser();
        foreach (new MermaidLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RankedGraphLayout()->layout(self::graph($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testNativeArrowheadsFollowTheirRenderedDirection(): void
    {
        foreach (['LR' => '▸', 'RL' => '◂', 'TB' => '▾', 'BT' => '▴'] as $direction => $glyph) {
            $graph = self::scene('graph '.$direction.'; A-->B')->graph;
            $view = new ZoomPanModel((int) $graph->width, (int) $graph->height, [1.0]);
            $rows = GraphRenderer::render($graph, $view, null, 60, 30, Styles::dark());
            $this->assertStringContainsString($glyph, \implode("\n", $rows), $direction);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown family' => ['sequenceDiagram', 'Expected flowchart'];
        yield 'unclosed shape' => ['graph TD; A[broken', 'Unclosed'];
        yield 'unclosed quote' => ['graph TD; A["broken]', 'Unclosed'];
        yield 'wrong delimiter' => ['graph TD; A[text}', 'Unexpected closing'];
        yield 'unclosed group' => ["graph TD\nsubgraph A", 'Unclosed subgraph'];
        yield 'unexpected end' => ['graph TD; end', 'Unexpected end'];
        yield 'invalid group' => ['graph TD; subgraph []', 'Invalid subgraph'];
        yield 'unclosed repeated group' => ['graph TD; subgraph A; end; subgraph A', 'Unclosed subgraph'];
        yield 'invalid direction' => ['graph TD; direction XX', 'Expected direction'];
        yield 'no node' => ['graph TD; -->A', 'requires a preceding node'];
        yield 'invalid node identifier' => ['graph TB; ?bad', 'Expected a node identifier'];
        yield 'no edge' => ['graph TD; A nonsense B', 'Expected a flowchart link'];
        yield 'bad class' => ['graph TD; A:::', 'Expected a class'];
        yield 'unclosed pipe' => ['graph TD; A-->|bad B', 'Unclosed |'];
        yield 'bad attributes' => ['graph TD; A@{shape rect}', 'Invalid shape property'];
        yield 'missing comma' => ['graph TD; A@{shape: rect label: Other}', 'Invalid shape property'];
        yield 'bad declaration' => ['graph TD; style A nonsense', 'Expected flowchart style'];
        yield 'empty source' => ['', 'Expected a diagram header'];
        yield 'invalid UTF8' => ["graph TD\n\xFF", 'Not a UTF-8'];
        yield 'too much nesting' => ['graph TD; A'.\str_repeat('[', times: 129), 'depth limit'];
        yield 'subgraph nesting' => ['graph TD; '.\implode(';', \array_map(static fn (int $i): string => 'subgraph G'.$i, \range(0, end: 128))), 'Subgraph nesting'];
        yield 'oversized statement' => ['graph TD; A['.\str_repeat('x', times: 65_537).']', 'Statement exceeds'];
        yield 'oversized source' => [\str_repeat('x', times: 10_485_761), 'source size limit'];
    }

    public function testScheduledBatchesCancellationAndNarrowRowsPreserveSemantics(): void
    {
        $scheduler = new FakeScheduler();
        $renderer = new NativeMermaidRenderer(new MermaidTask($scheduler));
        $source = 'graph TD; '.\implode('; ', \array_map(static fn (int $i): string => 'N'.$i.'-->N'.($i + 1), \range(0, end: 200)));
        $scene = null;
        $cancel = $renderer->render(
            $source,
            static function (MermaidScene $value) use (&$scene): void {
                $scene = $value;
            },
            static function (string $error): never {
                self::fail($error);
            },
        );
        $stale = \array_first($scheduler->scheduled);
        $scheduler->fire();
        $this->assertNull($scene, 'large diagrams yield before completion');
        $cancel();
        $stale();
        $this->assertSame([], $scheduler->scheduled);
        $result = null;
        $renderer->rows(
            'graph LR; A[漢字]-->|message|B',
            width: 4,
            success: static function (FenceResult $value) use (&$result): void {
                $result = $value;
            },
            error: static function (string $error): never {
                self::fail($error);
            },
        );
        $cancel();
        while ([] !== $scheduler->scheduled) {
            $scheduler->fire();
        }
        $this->assertInstanceOf(FenceResult::class, $result);
        $this->assertStringContainsString('message', \str_replace("\n", replace: '', subject: \implode("\n", $result->rows)));
        $this->assertContains(0, $result->origins);
        $error = null;
        $renderer->render(
            'graph TD; A[',
            static function (): never {
                self::fail('invalid diagram accepted');
            },
            static function (string $value) use (&$error): void {
                $error = $value;
            },
        );
        $scheduler->fire();
        $this->assertStringContainsString('Unclosed', $error);
        $renderer->cancel();
    }
}
