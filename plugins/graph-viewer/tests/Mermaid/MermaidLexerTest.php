<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidLexer;

final class MermaidLexerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function configurations(): iterable
    {
        yield 'participant' => ['participant A@'];
        yield 'actor' => ['actor A @'];
        yield 'created participant' => ['create participant A@'];
        yield 'created actor' => ['create actor A@'];
        yield 'links' => ['links A: '];
        yield 'properties' => ['properties A: '];
    }

    #[DataProvider('configurations')]
    public function testConfigurationOpenerBelongsToItsStatement(string $prefix): void
    {
        $configuration = $prefix."{\n\"alias\":\"literal #hash; %%comment {brace} \\\"quote\\\"\"\n}";
        $source = 'sequenceDiagram; '.$configuration."\nA->>B: after; ".$configuration."\nB->>A: done";
        $statements = \iterator_to_array(new MermaidLexer($source, textual: true)->statements());
        $this->assertSame(['sequenceDiagram', $configuration, 'A->>B: after', $configuration, 'B->>A: done'], \array_column($statements, 'text'));
        $this->assertSame([0, 0, 3, 3, 6], \array_column($statements, 'line'));
        $this->assertSame(17, $statements[1]->offset);
        $this->assertSame(\strrpos($source, needle: $configuration), $statements[3]->offset);
    }

    public function testLongMessageConfigurationLookalikesStayLiteral(): void
    {
        $message = 'A->>B: '.\str_repeat('participant@{ links { properties { ', times: 1800);
        $source = "sequenceDiagram\n".$message.";\nparticipant C\nB->>C: done";
        $statements = \iterator_to_array(new MermaidLexer($source, textual: true)->statements());
        $this->assertSame(['sequenceDiagram', \rtrim($message), 'participant C', 'B->>C: done'], \array_column($statements, 'text'));
        $this->assertSame([0, 1, 2, 3], \array_column($statements, 'line'));
    }
}
