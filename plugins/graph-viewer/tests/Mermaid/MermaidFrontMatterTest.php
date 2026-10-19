<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MermaidFrontMatterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function families(): iterable
    {
        yield 'flowchart' => ['flowchart LR; subgraph Team; A-->B; end'];
        yield 'sequence' => ['sequenceDiagram; A->>B: Hello'];
        yield 'pie' => ["pie showData\n\"One\": 1"];
        yield 'ER' => ["erDiagram\nA ||--o{ B : owns"];
        yield 'class' => ["classDiagram\nA <|-- B"];
        yield 'railroad' => ["railroad-beta\nx=terminal(\"A\");"];
        yield 'railroad EBNF' => ["railroad-ebnf-beta\nx=\"A\";"];
        yield 'railroad ABNF' => ["railroad-abnf-beta\nx=\"A\";"];
        yield 'railroad PEG' => ["railroad-peg-beta\nx<-\"A\";"];
        yield 'venn' => ["venn-beta\nset A"];
        yield 'eventmodeling' => ["eventmodeling\ntf 01 ui Screen"];
        yield 'architecture' => ["architecture-beta\nservice a[First]"];
        yield 'usecase' => ["usecase-beta\nactor A"];
        yield 'gantt' => ["gantt\nA:2024-01-01, 1d"];
        yield 'agentflow' => ["agentflow-beta LR\nflow Agent\nA[\"First\"]@{shape: task}\nend"];
        yield 'swimlane' => ["swimlane-beta LR\nsubgraph Team\nA[\"A\"]\nend"];
        yield 'block' => ["block\nA[\"A\"]"];
        yield 'c4' => ["C4Context\nSystem(a,\"A\")"];
        yield 'wardley' => ["wardley-beta\ncomponent First [0.8,0.2]"];
        yield 'git' => ["gitGraph\ncommit id:'A'"];
        yield 'state' => ["stateDiagram-v2\n[*] --> A\nA --> [*]"];
        yield 'timeline' => ["timeline\nNow : Event"];
        yield 'journey' => ["journey\nTask: 5: Actor"];
        yield 'mindmap' => ["mindmap\n  root((Root))\n    Child"];
        yield 'requirement' => ["requirementDiagram\nrequirement Safe {}"];
        yield 'cynefin' => ["cynefin-beta\ncomplex\n\"Explore\""];
        yield 'radar' => ["radar-beta\naxis A, B, C\ncurve S{1,2,3}"];
        yield 'ishikawa' => ["ishikawa\nEffect\nCause"];
        yield 'tree view' => ["treeView-beta\nRoot/\n  Child"];
        yield 'treemap' => ["treemap\n\"Root\"\n  \"Leaf\":4"];
        yield 'sankey' => ["sankey\nSource,Target,10"];
        yield 'kanban' => ["kanban\nTodo\n  First"];
        yield 'packet' => ["packet\n0-15: \"Source\""];
        yield 'xy' => ["xychart\nx-axis [A, B]\nline [10,20]"];
        yield 'quadrant' => ["quadrantChart\nA: [0.75, 0.93]"];
    }

    #[DataProvider('families')]
    public function testFrontMatterTitleRendersWithoutBecomingSelectable(string $body): void
    {
        $source = "---\ntitle: Shared caption\n---\n".$body;
        $rows = NativeFixtureRows::render($source, width: 30);
        $this->assertStringContainsString('Shared caption', \implode("\n", $rows->rows));
        $this->assertContains(1, $rows->origins);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('Shared caption', $svg, 'SVG contains the front matter title');
    }

    public function testBodyTitlesOverrideFrontMatterAndInfoKeepsItsDiagnostic(): void
    {
        foreach (["pie title Body caption\n\"One\":1", 'sequenceDiagram; title Body caption; A->>B: Hi'] as $body) {
            $rows = NativeFixtureRows::render("---\ntitle: Front caption\n---\n".$body, width: 50);
            $text = \implode("\n", $rows->rows);
            $this->assertStringContainsString('Body caption', $text);
            $this->assertStringNotContainsString('Front caption', $text);
        }
        $rows = NativeFixtureRows::render("---\ntitle: Front caption\n---\ninfo", width: 50);
        $this->assertStringNotContainsString('Front caption', \implode("\n", $rows->rows));
    }
}
