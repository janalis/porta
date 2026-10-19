<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidPalette;
use Janalis\Viz\Mermaid\SequenceDiagram;
use Janalis\Viz\Mermaid\SequenceLayout;
use Janalis\Viz\Mermaid\SequenceParser;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSceneRows;
use Porta\Ui\Theme\Styles;

final class SequenceGroupsTest extends TestCase
{
    private static function parse(string $source): SequenceDiagram
    {
        $parser = new SequenceParser();
        foreach (new MermaidLexer($source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testGroupsAreContiguousAndDoNotAdvanceMessages(): void
    {
        $diagram = self::parse(
            "sequenceDiagram\nparticipant A\nparticipant C\nbox rgb(10,20,30) 团队\nparticipant A\nactor B as Worker\nparticipant A\nend\nbox Other\nparticipant D\nend\nbox\nend\nloop retry\nA->>B: start\nend\nD->>C: finish",
        );
        $this->assertSame(['A', 'B'], $diagram->groups[0]['actors']);
        $this->assertSame(3, $diagram->groups[0]['origin']);
        $this->assertCount(4, $diagram->events);
        $this->assertSame(['A' => 0, 'B' => 0, 'D' => 1], $diagram->memberships);
        $work = new SequenceLayout()->layout($diagram);
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $headers = [];
        foreach ($scene->graph->nodes as $node) {
            if (!\array_key_exists($node->name, $diagram->participants)) {
                continue;
            }
            $headers[$node->name] = $node;
        }
        $this->assertSame(['A', 'B', 'C', 'D'], \array_keys($headers));
        [$group, $other, $control] = $scene->graph->clusters;
        $this->assertSame('rgb(10,20,30) 团队', $group->label);
        $this->assertSame(3, $scene->origins[(int) ($group->y0 / 2)]);
        foreach (['A', 'B'] as $id) {
            $this->assertLessThan($headers[$id]->x - ($headers[$id]->w / 2), $group->x0);
            $this->assertGreaterThan($headers[$id]->x + ($headers[$id]->w / 2), $group->x1);
        }
        $this->assertLessThan($headers['C']->x, $group->x1);
        $this->assertLessThan($other->x0, $headers['C']->x);
        $footer = \array_last(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => 'A' === $node->name));
        $this->assertGreaterThan($footer->y + ($footer->h / 2), $group->y1, 'a box encloses the mirrored footers too');
        $this->assertLessThan($scene->graph->height, $group->y1);
        $this->assertLessThan($footer->y - ($footer->h / 2), $control->y1, 'other frames end with their statements');
        $this->assertLessThan($control->y0, $group->y0);
        $rows = MermaidSceneRows::rows($scene->themed(MermaidPalette::defaults('sequenceDiagram')), width: 8, styles: Styles::dark());
        \iterator_to_array($rows);
        $this->assertContains(3, $rows->getReturn()->origins);
        $this->assertStringContainsString('box', \implode('', $rows->getReturn()->rows));
        $this->assertCount(1, self::parse('sequenceDiagram; box End; destroy A; end; A->B: gone')->groups[0]['actors']);
        $this->assertCount(0, self::parse('sequenceDiagram; box Empty; end')->participants);
    }
}
