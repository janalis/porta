<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Mermaid\VennDiagram;
use Janalis\Viz\Mermaid\VennLexer;
use Janalis\Viz\Mermaid\VennParser;
use PHPUnit\Framework\TestCase;

final class VennGeometryTest extends TestCase
{
    private static function parse(string $source): VennDiagram
    {
        $parser = new VennParser();
        foreach (new VennLexer($source)->statements() as $statement) {
            $parser->accept($statement);
        }

        return $parser->finish();
    }

    public function testIndentationCommentsUnicodeNamesAndImplicitTextStaySourceLinked(): void
    {
        $source = "venn-beta\r\nset \"😀\" [Emoji]\r\n  %% comment\r\n  text \"0\" [Zero]\r\nset \"\"\r\nunion \"\",\"😀\" [Both]\r\ntext \"😀\" named [Explicit]";
        $this->assertNotEmpty(NativeFixtureRows::render($source, width: 80)->rows);
        $diagram = self::parse($source);
        $this->assertSame(['😀', ''], $diagram->subsets[2]['sets']);
        $this->assertSame(['😀'], $diagram->texts[0]['sets']);
        $this->assertSame('0', $diagram->texts[0]['id']);
        $this->assertSame(3, $diagram->texts[0]['statement']->line);
        $this->assertSame('named', $diagram->texts[1]['id']);
    }
}
