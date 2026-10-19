<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidNodeStyles;
use Porta\GraphViewer\Mermaid\MermaidStatement;

final class MermaidNodeStylesTest extends TestCase
{
    public function testDecorationPreservesGeometryIdentityAndExistingPresentation(): void
    {
        $node = new GraphNode(9, 'A', 'Semantic', 5, 6, 8, 10, '#112233', '#aabbcc', dashed: true, background: true, strokeWidth: 0.7, drawLabel: 'Draw', textColor: '#778899');
        $styles = new MermaidNodeStyles('ER');
        $this->assertEquals($node, $styles->decorate($node));
        $styles->accept(new MermaidStatement('style A fill:blue,color:white,stroke-dasharray:0', offset: 0, line: 0, column: 0));
        $styled = $styles->decorate($node);
        $this->assertSame([$node->id, $node->name, $node->label, $node->x, $node->y, $node->w, $node->h], [$styled->id, $styled->name, $styled->label, $styled->x, $styled->y, $styled->w, $styled->h]);
        $this->assertSame('Draw', $styled->drawLabel);
        $this->assertTrue($styled->background);
        $this->assertSame(0.7, $styled->strokeWidth);
        $this->assertSame('#112233', $styled->color);
        $this->assertSame('#0000ff', $styled->fillColor);
        $this->assertSame('#ffffff', $styled->textColor);
        $this->assertFalse($styled->dashed);
    }

    public function testStandaloneAssignmentsAndPropertyUpdatesCompose(): void
    {
        $styles = new MermaidNodeStyles('requirement');
        foreach (['A:::red, edge;', 'class B red, edge', 'classDef red fill:red', 'classDef red color:white', 'classDef edge stroke:blue', 'style A fill:green', 'style A stroke-width:2'] as $text) {
            $styles->accept(new MermaidStatement($text, offset: 0, line: 0, column: 0));
        }
        $this->assertSame(['fill' => '#ff0000', 'color' => '#ffffff', 'stroke' => '#0000ff'], $styles->forNode('B'));
        $this->assertSame(['fill' => '#008000', 'color' => '#ffffff', 'stroke' => '#0000ff', 'stroke-width' => 0.2], $styles->forNode('A'));
    }
}
