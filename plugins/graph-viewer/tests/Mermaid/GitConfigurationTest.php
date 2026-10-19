<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\GitLayout;
use Janalis\Viz\Mermaid\GitLexer;
use Janalis\Viz\Mermaid\GitParser;
use Janalis\Viz\Mermaid\MermaidConfiguration;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\MermaidStatement;
use Janalis\Viz\Render\Format;
use PHPUnit\Framework\TestCase;

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
        $svg = NativeFixtureExport::render($source, Format::Svg);
        $this->assertStringContainsString('trunk', $svg);
        $this->assertStringContainsString('release', $svg);
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 40)->rows);
        $scene = self::scene("gitGraph\ncommit\nbranch topic order:1", ['mainBranchName' => 'trunk', 'mainBranchOrder' => 9]);
        $this->assertSame(['topic', 'trunk'], \array_slice(\array_column($scene->graph->nodes, 'name'), offset: 0, length: 2));
        $this->assertSame('trunk', $scene->graph->nodes[1]->name);
    }
}
