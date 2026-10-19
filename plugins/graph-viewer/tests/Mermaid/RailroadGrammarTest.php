<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Mermaid\RailroadDiagram;
use Janalis\Viz\Mermaid\RailroadLayout;
use Janalis\Viz\Mermaid\RailroadLexer;
use Janalis\Viz\Mermaid\RailroadParser;
use PHPUnit\Framework\TestCase;

final class RailroadGrammarTest extends TestCase
{
    private static function parse(string $source): RailroadDiagram
    {
        $parser = new RailroadParser();
        foreach (new RailroadLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    private static function scene(string $source): MermaidScene
    {
        $work = new RailroadLayout()->layout(self::parse($source));
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testAllPrefixesCanBeDetectedAfterTheirOwnComments(): void
    {
        foreach ([
            "/* Comment */\nrailroad-beta\nx = terminal(\"x\");",
            "(* Comment *)\nrailroad-ebnf-beta\nx = \"x\";",
            "# Comment\nrailroad-peg-beta\nx <- \"x\";",
        ] as $source) {
            $this->assertNotEmpty(NativeFixtureRows::render($source, width: 100)->rows);
        }
        $this->assertSame([], self::scene('railroad-beta')->graph->nodes);
    }
}
