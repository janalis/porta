<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphExportFormat;
use Porta\GraphViewer\Mermaid\GitConfiguration;
use Porta\GraphViewer\Mermaid\GitLayout;
use Porta\GraphViewer\Mermaid\GitLexer;
use Porta\GraphViewer\Mermaid\GitParser;
use Porta\GraphViewer\Mermaid\MermaidConfiguration;
use Porta\GraphViewer\Mermaid\MermaidScene;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\MermaidSyntaxError;

final class GitConfigurationTest extends TestCase
{
    private static function config(array $options): MermaidConfiguration
    {
        return MermaidConfiguration::parse((object) ['gitGraph' => (object) $options], new MermaidStatement('', offset: 4, line: 2, column: 1));
    }

    private static function scene(string $source, array $options): MermaidScene
    {
        $config = self::config($options)->gitGraph;
        $parser = new GitParser($config);
        foreach (new GitLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $layout = new GitLayout($config)->layout($parser->finish());
        \iterator_to_array($layout);

        return $layout->getReturn();
    }

    public function testOptionOverlaysPreserveFalseZeroAndMainIdentity(): void
    {
        $first = self::config(['mainBranchName' => 'trunk', 'mainBranchOrder' => 9, 'showBranches' => true, 'showCommitLabel' => true, 'parallelCommits' => true]);
        $later = self::config(['mainBranchOrder' => 0, 'showBranches' => false, 'showCommitLabel' => false, 'parallelCommits' => false, 'rotateCommitLabel' => false]);
        $merged = $first->overlay($later)->gitGraph;
        $this->assertSame('trunk', $merged->name());
        $this->assertSame(0, $merged->order());
        $this->assertFalse($merged->branches());
        $this->assertFalse($merged->labels());
        $this->assertFalse($merged->parallel());
        $this->assertTrue($merged->configured());
        $this->assertSame('trunk', $first->overlay(new MermaidConfiguration())->gitGraph->name());
        $this->assertSame('trunk', new MermaidConfiguration()->overlay($first)->gitGraph->name());
        $this->assertFalse(self::config([])->gitGraph->configured());
        $this->assertSame('main', new GitConfiguration()->name());
        $this->assertSame(0, new GitConfiguration()->order());
        self::config([])->validateFamily('other');
        $first->validateFamily('git');
        $this->expectException(MermaidSyntaxError::class);
        $first->validateFamily('other');
    }

    public function testParallelBranchesShareChronologyUntilMerged(): void
    {
        $source = "gitGraph\ncommit id:'root'\nbranch t\ncommit id:'topic'\ncheckout main\ncommit id:'main'\nmerge t id:'merge'";
        foreach (['LR', 'TB', 'BT'] as $direction) {
            $source = \preg_replace('/^gitGraph[^\n]*/', 'gitGraph '.$direction.':', $source);
            $scene = self::scene($source, ['parallelCommits' => true]);
            $topic = \array_find($scene->graph->nodes, static fn ($node): bool => 'topic' === $node->name);
            $main = \array_find($scene->graph->nodes, static fn ($node): bool => 'main' === $node->name && '' === $node->drawLabel);
            $merge = \array_find($scene->graph->nodes, static fn ($node): bool => 'merge' === $node->name);
            $this->assertSame('LR' === $direction ? $main->x : $main->y, 'LR' === $direction ? $topic->x : $topic->y);
            $this->assertNotSame('LR' === $direction ? $main->x : $main->y, 'LR' === $direction ? $merge->x : $merge->y);
        }
    }

    public function testHiddenLabelsKeepTagsAndHiddenBranchesKeepHistory(): void
    {
        $scene = self::scene("gitGraph\ncommit id:'first' tag:'release'\nbranch topic\ncommit id:'second'", ['showBranches' => false, 'showCommitLabel' => false]);
        $this->assertCount(2, $scene->elements);
        $this->assertSame(1, $scene->graph->edgeCount());
        $this->assertCount(0, \array_filter($scene->graph->edges, static fn (GraphEdge $edge): bool => $edge->tail === $edge->head), 'branch rails are hidden too');
        $labels = \array_column($scene->graph->nodes, 'label', 'name');
        $this->assertSame('', $labels['@caption:first'], 'tags fly their own flag, labels or not');
        $this->assertSame('release', $labels['@tag:first']);
        $this->assertSame('', $labels['@caption:second']);
        $this->assertSame('', $labels['main']);
        $this->assertSame('', $labels['topic']);
        $this->assertStringContainsString('first', \implode("\n", \array_column($scene->legend, 'text')));
    }

    public function testCustomMainBranchAndOrderApplyThroughFrontMatterAndInit(): void
    {
        $source = <<<'MMD'
            ---
            config:
              gitGraph:
                mainBranchName: trunk
                mainBranchOrder: 9
                showBranches: false
            ---
            %%{init: {gitGraph: {mainBranchOrder: 0, showBranches: true}}}%%
            gitGraph
            commit id:'root'
            branch topic order:1
            commit id:'work'
            checkout trunk
            commit id:'release'
            MMD;
        $svg = NativeFixtureExport::render($source, GraphExportFormat::Svg);
        $this->assertStringContainsString('trunk', $svg);
        $this->assertStringContainsString('release', $svg);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $scene = self::scene("gitGraph\ncommit\nbranch topic order:1", ['mainBranchName' => 'trunk', 'mainBranchOrder' => 9]);
        $this->assertSame(['topic', 'trunk'], \array_slice(\array_column($scene->graph->nodes, 'name'), offset: 0, length: 2));
        $this->assertSame('trunk', $scene->graph->nodes[1]->name);
    }

    public function testPinnedCustomMainBranchFixtureRendersWithItsConfiguration(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../fixtures/mermaid/git/95-should-render-with-custom-mainbranchname.mmd');
        $this->assertStringContainsString('trunk', NativeFixtureExport::render($source, GraphExportFormat::Svg));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'empty main' => [['mainBranchName' => '']];
        yield 'numeric main' => [['mainBranchName' => 1]];
        yield 'long main' => [['mainBranchName' => \str_repeat('a', times: 4097)]];
        yield 'negative order' => [['mainBranchOrder' => -1]];
        yield 'large order' => [['mainBranchOrder' => 1_000_001]];
        yield 'fractional order' => [['mainBranchOrder' => 1.5]];
        yield 'branches' => [['showBranches' => 0]];
        yield 'labels' => [['showCommitLabel' => 'false']];
        yield 'parallel' => [['parallelCommits' => []]];
        yield 'rotated labels' => [['rotateCommitLabel' => 'yes']];
    }

    #[DataProvider('invalid')]
    public function testInvalidOptionsHaveLocatedErrors(array $options): void
    {
        try {
            self::config($options);
            $this->fail('Invalid option accepted');
        } catch (MermaidSyntaxError $error) {
            $this->assertSame([4, 2, 1], [$error->offset, $error->sourceLine, $error->column]);
        }
    }

    public function testGitConfigurationMustBeAMapping(): void
    {
        $this->expectException(MermaidSyntaxError::class);
        MermaidConfiguration::parse((object) ['gitGraph' => true], new MermaidStatement('', offset: 0, line: 0, column: 0));
    }
}
