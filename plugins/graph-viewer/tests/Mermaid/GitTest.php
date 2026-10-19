<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\GitDiagram;
use Porta\GraphViewer\Mermaid\GitLayout;
use Porta\GraphViewer\Mermaid\GitLexer;
use Porta\GraphViewer\Mermaid\GitMarkers;
use Porta\GraphViewer\Mermaid\GitParser;
use Porta\GraphViewer\Mermaid\MermaidDrawing;
use Porta\GraphViewer\Mermaid\MermaidMark;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class GitTest extends TestCase
{
    private static function parse(string $source): GitDiagram
    {
        $parser = new GitParser();
        foreach (new GitLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testBranchSwitchingAndMergePreserveOrderedParentsAndCommitTypes(): void
    {
        $diagram = self::parse(<<<'MMD'
            gitGraph
            commit id: "root" msg: "initial"
            branch 'feature/topic' order: 2
            commit id: "work" type: HIGHLIGHT tag: "v1" tag: "ready"
            switch main
            commit id: "main" type: REVERSE
            merge 'feature/topic' id: "merge" type: NORMAL tag: "release"
            MMD);
        $this->assertSame(['root'], $diagram->commits['work']['parents']);
        $this->assertSame('feature/topic', $diagram->commits['work']['branch']);
        $this->assertSame(['v1', 'ready'], $diagram->commits['work']['tags']);
        $this->assertSame(['main', 'work'], $diagram->commits['merge']['parents']);
        $this->assertSame(['MERGE', 'NORMAL'], [$diagram->commits['merge']['type'], $diagram->commits['merge']['customType']]);
        $this->assertSame(['merge', 'work'], \array_column($diagram->branches, 'tip'));
        $this->assertSame([0, 2], \array_column($diagram->branches, 'order'));
        $this->assertSame(6, $diagram->commits['merge']['origin']);
        $this->assertSame('main', $diagram->current);
    }

    public function testCherryPickDefaultsExplicitTagsAndMergeParentValidation(): void
    {
        $diagram = self::parse(<<<'MMD'
            gitGraph
            commit id: "root"
            branch topic
            commit id: "source" msg: "Feature"
            checkout main
            commit id: "main"
            cherry-pick id: "source"
            cherry-pick id: "source" tag: "" tag: "picked"
            merge topic id: "merged"
            checkout topic
            cherry-pick id: "merged" parent: "source"
            MMD);
        $commits = \array_values($diagram->commits);
        $this->assertSame(['main', 'source'], $commits[3]['parents']);
        $this->assertSame(['cherry-pick:source'], $commits[3]['tags']);
        $this->assertSame(['picked'], $commits[4]['tags']);
        $this->assertSame('cherry-picked Feature into main', $commits[3]['message']);
        $this->assertSame(['cherry-pick:merged|parent:source'], $commits[6]['tags']);
        $this->assertSame(['source', 'merged'], $commits[6]['parents']);
    }

    public function testGeneratedIdsAreDeterministicAndDoNotReplaceAuthoredIds(): void
    {
        $diagram = self::parse("gitGraph:\ncommit id: '1-native'\ncommit\ncommit id: 'same' msg:'before'\ncommit id: 'same' msg:'after'");
        $this->assertSame(['1-native', '1-native-', 'same'], \array_keys($diagram->commits));
        $this->assertSame(['same'], $diagram->commits['same']['parents']);
        $this->assertSame('after', $diagram->commits['same']['message']);
        $this->assertSame(4, $diagram->sequence);
        $blank = self::parse("gitGraph\nbranch ''\ncommit id:'blank'\ncheckout main\ncheckout ''");
        $this->assertSame('', $blank->current);
        $this->assertSame('blank', $blank->branches['']['tip']);
        $empty = self::parse("gitGraph\nbranch new\ncheckout main\ncommit");
        $this->assertSame([], $empty->commits['0-native']['parents']);
    }

    public function testQuotedValuesMetadataAndSourceLocationsSurviveLexing(): void
    {
        $source = <<<'MMD'
            %% intro
            gitGraph TB:
            title Release history
            accTitle: History
            accDescr {Version history}
            commit id:'literal;id' msg:"Line\nquote\" and %% comment" tag:'a\q' %% hidden
            commit 'line
            two'
            MMD;
        $diagram = self::parse($source);
        $this->assertSame('TB', $diagram->direction);
        $this->assertSame("Line\nquote\" and %% comment", $diagram->commits['literal;id']['message']);
        $this->assertSame(['aq'], $diagram->commits['literal;id']['tags']);
        $this->assertSame(5, $diagram->commits['literal;id']['origin']);
        $this->assertSame("line\ntwo", $diagram->commits['1-native']['message']);
        $this->assertCount(3, $diagram->metadata);
        $rows = NativeFixtureRows::render($source, width: 24);
        $this->assertContains(5, $rows->origins);
        $this->assertStringContainsString('Release history', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'unknown command' => ["gitGraph\nreset main"];
        yield 'semicolon' => ['gitGraph; commit'];
        yield 'bad direction' => ['gitGraph RL:'];
        yield 'unknown checkout' => ["gitGraph\ncheckout missing"];
        yield 'duplicate branch' => ["gitGraph\nbranch main"];
        yield 'missing branch' => ["gitGraph\nbranch"];
        yield 'unquoted message' => ["gitGraph\ncommit hello"];
        yield 'bad option' => ["gitGraph\ncommit parent:'x'"];
        yield 'bad type' => ["gitGraph\ncommit type:OTHER"];
        yield 'quoted type' => ["gitGraph\ncommit type:'NORMAL'"];
        yield 'bad order' => ["gitGraph\nbranch topic order:01"];
        yield 'repeated order' => ["gitGraph\nbranch topic order:1 order:2"];
        yield 'negative order' => ["gitGraph\nbranch topic order:-1"];
        yield 'invalid punctuation' => ["gitGraph\ncommit @"];
        yield 'missing value' => ["gitGraph\ncommit id:"];
        yield 'wrong option order' => ["gitGraph\nmerge id:'m' topic"];
        yield 'two names' => ["gitGraph\ncheckout main other"];
        yield 'empty merge' => ["gitGraph\nmerge other"];
        yield 'self merge' => ["gitGraph\ncommit\nmerge main"];
        yield 'same tip merge' => ["gitGraph\ncommit\nbranch topic\nmerge main"];
        yield 'missing merge branch' => ["gitGraph\ncommit\nmerge missing"];
        yield 'empty target' => ["gitGraph\nbranch topic\ncheckout main\ncommit\nmerge topic"];
        yield 'duplicate merge id' => ["gitGraph\ncommit id:'r'\nbranch t\ncommit\ncheckout main\ncommit\nmerge t id:'r'"];
        yield 'unknown cherry source' => ["gitGraph\ncommit\ncherry-pick id:'missing'"];
        yield 'same branch cherry' => ["gitGraph\ncommit id:'r'\ncherry-pick id:'r'"];
        yield 'empty cherry target' => ["gitGraph\nbranch t\ncommit id:'r'\ncheckout main\ncherry-pick id:'r'"];
        yield 'wrong cherry parent' => ["gitGraph\ncommit id:'r'\nbranch t\ncommit id:'s'\ncheckout main\ncherry-pick id:'s' parent:'wrong'"];
        yield 'missing merge cherry parent' => ["gitGraph\ncommit\nbranch t\ncommit\ncheckout main\ncommit\nmerge t id:'m'\ncheckout t\ncherry-pick id:'m'"];
    }

    #[DataProvider('invalid')]
    public function testInvalidCommandsFailWithLocatedErrors(string $source): void
    {
        $this->expectException(MermaidSyntaxError::class);
        self::parse($source);
    }

    public function testParserHeaderEmptyInputAndCommandLimit(): void
    {
        foreach (['', 'graph LR'] as $source) {
            try {
                self::parse($source);
                $this->fail('Invalid header accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame([0, 0, 0], [$error->offset, $error->sourceLine, $error->column]);
            }
        }
        foreach (['wrong', "gitGraph\nwrong"] as $text) {
            $direct = new GitParser();
            try {
                foreach (\explode("\n", $text) as $line) {
                    $direct->accept(new MermaidStatement($line, offset: 0, line: 0, column: 0));
                }
                $this->fail('Invalid direct declaration accepted');
            } catch (MermaidSyntaxError $error) {
                $this->assertSame(0, $error->offset);
            }
        }
        $parser = new GitParser();
        $parser->accept(new MermaidStatement('gitGraph', offset: 0, line: 0, column: 0));
        for ($i = 0; $i < 4096; ++$i) {
            $parser->accept(new MermaidStatement('commit', offset: 10, line: 1, column: 2));
        }
        $this->assertCount(4096, $parser->finish()->commits);
        $this->expectException(MermaidSyntaxError::class);
        $parser->accept(new MermaidStatement('commit', offset: 10, line: 1, column: 2));
    }

    #[DataProvider('directions')]
    public function testArrowsTurnWherePinnedDrawArrowTurnsThem(string $direction): void
    {
        $source = 'gitGraph '.$direction.":\ncommit id:'root'\nbranch topic\ncommit id:'feature'\ncheckout main\ncommit id:'fix'\nmerge topic";
        $work = new GitLayout()->layout(self::parse($source));
        \iterator_to_array($work);
        $scene = $work->getReturn();
        $node = static fn (string $name): GraphNode => \array_find($scene->graph->nodes, static fn (GraphNode $node): bool => $name === $node->name);
        $edge = static fn (string $from, string $to): GraphEdge => \array_find(
            $scene->graph->relationships,
            static fn (GraphEdge $edge): bool => $node($from)->id === $edge->tail && $node($to)->id === $edge->head,
        );
        [$root, $feature, $merge] = [
            $node(name: 'root'),
            $node(name: 'feature'),
            \array_last(\array_filter($scene->graph->nodes, static fn (GraphNode $node): bool => '@' !== $node->name[0] && \str_ends_with($node->name, '-native'))),
        ];
        $branching = $edge(from: 'root', to: 'feature');
        $merging = \array_find($scene->graph->relationships, static fn (GraphEdge $edge): bool => $feature->id === $edge->tail && $merge->id === $edge->head);
        $horizontal = 'LR' === $direction;
        // Branching off, the arrow leaves along the parent's time position and turns onto the child's lane.
        $this->assertEqualsWithDelta(
            $horizontal ? [$root->x, ($root->y + $feature->y) / 2] : [($root->x + $feature->x) / 2, $root->y],
            $horizontal ? [$branching->points[1][0], ($root->y + $feature->y) / 2] : [($root->x + $feature->x) / 2, $branching->points[1][1]],
            1e-9,
        );
        // Merged in, it runs its own lane up to the merge's time position, then turns.
        $turn = \array_values(\array_filter($merging->points, static fn (array $point): bool => $horizontal ? \abs($point[1] - $feature->y) < 1e-9 : \abs($point[0] - $feature->x) < 1e-9));
        $this->assertEqualsWithDelta($horizontal ? $merge->x - 2 : $merge->y + ('BT' === $direction ? 2 : -2), $horizontal ? \array_last($turn)[0] : \array_last($turn)[1], 1e-9);
        $this->assertSame($scene->graph->nodes[$merging->tail]->color, $merging->color, 'a merged arrow keeps its branch color');
        $this->assertCount(2 + 4 + 1, $branching->points, 'one turn, rounded by five points');
        $this->assertSame([0.8, true], [$branching->strokeWidth, \array_find($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->decoration)->dashed], 'bold arrows over dashed tracks');
    }

    public function testCustomMergeTypesReplaceTheMergeRingAndEmptyGraphsRemainVisible(): void
    {
        $node = new GraphNode(0, 'commit', '', x: 4.0, y: 4.0, w: 4.0, h: 4.0, color: null, fillColor: null, dashed: false);
        $this->assertCount(0, GitMarkers::points($node, 'MERGE', 'NORMAL'));
        $this->assertCount(2, GitMarkers::points($node, 'MERGE', 'REVERSE'));
        $this->assertCount(1, GitMarkers::points($node, 'MERGE', 'HIGHLIGHT'));
        $this->assertCount(1, GitMarkers::points($node, 'MERGE', null));
        $this->assertNotEmpty(NativeFixtureRows::render('gitGraph', width: 1)->rows);
    }

    public function testWrappedBranchAndCommitTextStayInsideEveryCanvas(): void
    {
        foreach (['LR', 'TB', 'BT'] as $direction) {
            $name = \str_repeat('long-branch-', times: 8);
            $source = 'gitGraph '.$direction.":\nbranch '".$name."'\ncommit msg:'".\str_repeat('message ', times: 20)."'";
            $work = new GitLayout()->layout(self::parse($source));
            \iterator_to_array($work);
            $scene = $work->getReturn();
            foreach ($scene->graph->nodes as $node) {
                $this->assertGreaterThanOrEqual(0.0, $node->x - ($node->w / 2));
                $this->assertGreaterThanOrEqual(0.0, $node->y - ($node->h / 2));
                $this->assertLessThanOrEqual($scene->graph->width, $node->x + ($node->w / 2));
                $this->assertLessThanOrEqual($scene->graph->height, $node->y + ($node->h / 2));
            }
            $this->assertStringContainsString("\n", $scene->graph->nodes[1]->label);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function directions(): iterable
    {
        yield 'horizontal' => ['LR'];
        yield 'top to bottom' => ['TB'];
        yield 'bottom to top' => ['BT'];
    }

    #[DataProvider('directions')]
    public function testGraphicsKeepLanesChronologyGlyphsAndSelectableOrigins(string $direction): void
    {
        $source = 'gitGraph '.$direction.":\ncommit id:'r' type:REVERSE\nbranch topic\ncommit id:'h' type:HIGHLIGHT\ncheckout main\ncommit id:'n'\ncherry-pick id:'h'\nmerge topic";
        $work = new GitLayout()->layout(self::parse($source));
        $this->assertGreaterThan(20, \count(\iterator_to_array($work)));
        $scene = $work->getReturn();
        $r = \array_find($scene->graph->nodes, static fn ($node): bool => 'r' === $node->name);
        $n = \array_find($scene->graph->nodes, static fn ($node): bool => 'n' === $node->name);
        $h = \array_find($scene->graph->nodes, static fn ($node): bool => 'h' === $node->name);
        $this->assertSame(1, $scene->elements[$r->id]);
        $this->assertCount(7, $scene->elements);
        $this->assertSame(6, $scene->graph->edgeCount());
        $this->assertCount(1, \array_filter($scene->graph->relationships, static fn (GraphEdge $edge): bool => $edge->dashed));
        if ('LR' === $direction) {
            $this->assertLessThan($n->x, $r->x);
            $this->assertSame($r->y, $n->y);
            $this->assertNotSame($r->y, $h->y);
        }
        if ('LR' !== $direction) {
            $this->assertSame($r->x, $n->x);
            $this->assertNotSame($r->x, $h->x);
            $this->assertSame('BT' === $direction, $r->y > $n->y);
        }
        $drawing = MermaidDrawing::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $white = \array_filter($drawing->getReturn()->marks, static fn (MermaidMark $mark): bool => '#ffffff' === $mark->stroke && 'polyline' === $mark->kind);
        $this->assertCount(7, $white);
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('>cherry-pick:h</text>', $svg, 'a cherry-pick flies its pinned tag');
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, GraphExportFormat::Png));
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 20)->rows);
    }

    /** @return iterable<string, array{string}> */
    public static function pinnedBodies(): iterable
    {
        $files = \glob(__DIR__.'/../fixtures/mermaid/git/*.mmd');
        foreach (false === $files ? [] : $files as $file) {
            // Custom main-branch configuration is graded separately from family body syntax.
            if (\str_contains($file, '95-should-render-with-custom-mainbranchname')) {
                continue;
            }
            $source = (string) \file_get_contents($file);
            $source = \preg_replace('/\A---\r?\n.*?\r?\n---\r?\n/s', replacement: '', subject: $source) ?? $source;
            $source = \preg_replace('/%%\{.*?\}%%/s', replacement: '', subject: $source) ?? $source;
            yield \basename($file) => [$source];
        }
    }

    #[DataProvider('pinnedBodies')]
    public function testPinnedBodiesRenderToRowsAndSvg(string $source): void
    {
        $this->assertNotEmpty(self::parse($source)->commits);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }
}
