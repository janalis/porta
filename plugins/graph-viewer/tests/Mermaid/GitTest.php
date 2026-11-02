<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Layout\GraphEdge;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\GitDiagram;
use Janalis\Viz\Mermaid\GitLayout;
use Janalis\Viz\Mermaid\GitLexer;
use Janalis\Viz\Mermaid\GitMarkers;
use Janalis\Viz\Mermaid\GitParser;
use Janalis\Viz\Mermaid\MermaidPainter;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $this->assertStringContainsString('Release history', NativeFixtureExport::render($source, Format::Svg));
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
        $drawing = MermaidPainter::graph($scene->graph, $scene->shapes);
        \iterator_to_array($drawing);
        $white = \array_filter($drawing->getReturn()->marks, static fn (Mark $mark): bool => '#ffffff' === $mark->stroke && MarkKind::Polyline === $mark->kind);
        $this->assertCount(7, $white);
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('>cherry-pick:h</text>', $svg, 'a cherry-pick flies its pinned tag');
        $this->assertStringStartsWith("\x89PNG", NativeFixtureExport::render($source, Format::Png));
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
        $this->assertStringContainsString('<svg', NativeFixtureExport::render($source, Format::Svg));
    }
}
