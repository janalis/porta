<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidFenceElements;

final class MermaidFenceElementsTest extends TestCase
{
    /** An element's silhouette is its own shape's, in fractions of the picture: a diamond is four points, not its box. */
    public function testAnElementIsOutlinedByItsOwnShape(): void
    {
        $node = new GraphNode(0, 'd', 'Decide', 50.0, 25.0, 20.0, 10.0, null, null, false);
        $scene = new MermaidScene(new GraphLayout(100.0, 50.0, [0 => $node], [], []), [], [], elements: [0 => 3], shapes: [0 => 'diamond']);

        $work = MermaidFenceElements::fromScene($scene);
        \iterator_to_array($work);
        [$element] = $work->getReturn();

        $this->assertSame(['0', 'Decide', 3], [$element->id, $element->label, $element->origin]);
        $this->assertSame([[0.5, 0.4], [0.6, 0.5], [0.5, 0.6], [0.4, 0.5]], $element->polygon);
    }
}
