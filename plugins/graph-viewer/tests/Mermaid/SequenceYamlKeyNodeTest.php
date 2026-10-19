<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidStatement;
use Porta\GraphViewer\Mermaid\SequenceYamlKeyNode;

final class SequenceYamlKeyNodeTest extends TestCase
{
    public function testUnclosedCollectionRangeStopsAtTheSourceBoundary(): void
    {
        $yaml = '[alias, "quoted]text"';
        $node = SequenceYamlKeyNode::read($yaml, start: 0, flowIndent: 0, statement: new MermaidStatement('', offset: 0, line: 0, column: 0));
        $this->assertSame(\strlen($yaml), $node->end);
        $this->assertSame(0, $node->body);
        $this->assertNull($node->anchor);
        $this->assertSame('', $node->properties);
    }
}
