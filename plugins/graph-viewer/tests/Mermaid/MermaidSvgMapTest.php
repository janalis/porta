<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSvgIndex;
use Porta\GraphViewer\Mermaid\MermaidSvgMap;

final class MermaidSvgMapTest extends TestCase
{
    private static function source(string $second = 'Beta'): MermaidScene
    {
        return new MermaidScene(
            new GraphLayout(
                100.0,
                50.0,
                [
                    1 => new GraphNode(1, 'A', 'Alpha', 0.0, 0.0, 1.0, 1.0, color: null, fillColor: null, dashed: false),
                    2 => new GraphNode(2, 'B', $second, 0.0, 0.0, 1.0, 1.0, color: null, fillColor: null, dashed: false),
                ],
                edges: [],
                clusters: [],
            ),
            origins: [],
            legend: [],
            elements: [1 => 4, 2 => 7],
        );
    }

    private static function map(string $body, MermaidScene $source, float $width = 200.0, float $height = 100.0): ?MermaidScene
    {
        $work = MermaidSvgMap::project('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">'.$body.'</svg>', $source, $width, $height);
        \iterator_to_array($work);

        return $work->getReturn();
    }

    public function testNodeIdsAndNestedTransformsPreserveSourceAndPictureCoordinates(): void
    {
        $source = self::source();
        $map = self::map(
            '<g transform="translate(10,5)"><g class="node default" id="flowchart-A-0" transform="scale(3)"><rect x="1" y="2" width="10" height="5"/></g></g><g data-id="B"><rect x="60" y="20" width="30" height="20"/></g>',
            $source,
        );
        $this->assertInstanceOf(MermaidScene::class, $map);
        $this->assertSame([1 => 4, 2 => 7], $map->elements);
        $this->assertSame([200.0, 100.0], [$map->graph->width, $map->graph->height]);
        $node = $map->graph->nodes[1];
        $this->assertSame(['A', 'Alpha', 56.0, 37.0, 60.0, 30.0], [$node->name, $node->label, $node->x, $node->y, $node->w, $node->h]);
        $this->assertSame([0.0, 0.0], [$source->graph->nodes[1]->x, $source->graph->nodes[1]->y], 'projection does not mutate cached native geometry');
    }

    public function testUniqueLabelsAreFallbacksButDuplicateLabelsAndIdsNeverGuess(): void
    {
        $body = '<g class="actor"><foreignObject x="10" y="10" width="20" height="10"><div xmlns="http://www.w3.org/1999/xhtml">Alpha</div></foreignObject></g>';
        $map = self::map($body, self::source());
        $this->assertSame([1 => 4], $map->elements);
        $this->assertNull(self::map($body, self::source('Alpha')));
        $this->assertNull(self::map($body.$body, self::source()));
        $explicit = '<g data-id="A"><rect width="10" height="10"/></g>';
        $this->assertNull(self::map($explicit.$explicit, self::source()));
        $this->assertSame([1 => 4], self::map($explicit, self::source('Alpha'))->elements, 'unique IDs remain usable even with duplicate labels');
        $usecase = '<g class="node" data-usecase-id="A"><rect width="10" height="10"/></g><g class="node" data-usecase-id="B"><rect x="20" width="10" height="10"/></g>';
        $this->assertSame([1 => 4, 2 => 7], self::map($usecase, self::source('Alpha'))->elements);
    }

    public function testHiddenReferencedEmptyAndNonFiniteShapesDoNotBecomeTargets(): void
    {
        $body = '<defs><g data-id="A"><rect width="20" height="20"/></g></defs><g data-id="A" display="none"><rect width="20" height="20"/></g><g data-id="A" style="fill:red; display: none"><rect width="20" height="20"/></g><g data-id="A"><path d="M 0 0"/></g><g data-id="B" transform="scale(1e999)"><rect width="20" height="20"/></g>';
        $this->assertNull(self::map($body, self::source()));
        $this->assertNull(self::map('<g data-id="A"><rect x="200" width="10" height="10"/></g>', self::source()));
        $this->assertNull(self::map('<g data-id="A"><rect x="10" y="200" width="10" height="10"/></g>', self::source()));
    }

    public function testVisibleBoundsAreClippedAndLetterboxingUsesTheActualImageAspect(): void
    {
        $map = self::map('<g data-id="A"><rect x="-10" y="-5" width="120" height="60"/></g>', self::source());
        $node = $map->graph->nodes[1];
        $this->assertSame([100.0, 50.0, 200.0, 100.0], [$node->x, $node->y, $node->w, $node->h]);
        $map = self::map('<g data-id="A"><rect width="10" height="10"/></g>', self::source(), height: 200.0);
        $node = $map->graph->nodes[1];
        $this->assertSame([10.0, 60.0, 20.0, 20.0], [$node->x, $node->y, $node->w, $node->h]);
    }

    #[DataProvider('invalid')]
    public function testInvalidAndOversizedSvgIsRejectedWithoutExternalResources(string $svg): void
    {
        $work = MermaidSvgIndex::read($svg, width: 100.0, height: 50.0);
        \iterator_to_array($work);
        $this->assertNull($work->getReturn());
        $work = MermaidSvgMap::project($svg, self::source(), width: 100.0, height: 50.0);
        \iterator_to_array($work);
        $this->assertNull($work->getReturn());
    }

    public static function invalid(): iterable
    {
        yield [''];
        yield ['<svg>'];
        yield ['<html/>'];
        yield ['<!DOCTYPE svg [<!ENTITY secret SYSTEM "file:///etc/passwd">]><svg/>'];
        yield ['<!ENTITY secret "value"><svg/>'];
        yield [\str_repeat(' ', times: 4_194_305)];
        yield ['<svg>'.\str_repeat('<g/>', times: 32_769).'</svg>'];
    }

    #[DataProvider('browserFixtures')]
    public function testPublishedMermaidSvgBoundsAgreeWithTheBrowserReference(string $name, array $expected): void
    {
        $path = __DIR__.'/../fixtures/mermaid/cli-svg/'.$name;
        $source = NativeFixtureScene::render((string) \file_get_contents($path.'.mmd'));
        $work = MermaidSvgMap::project((string) \file_get_contents($path.'.svg'), $source, $expected['width'], $expected['height']);
        \iterator_to_array($work);
        $mapped = $work->getReturn();
        $this->assertInstanceOf(MermaidScene::class, $mapped);
        $this->assertCount(\count($expected['matches']), $mapped->elements);
        foreach ($expected['matches'] as $nodeName => $match) {
            $node = \array_find($mapped->graph->nodes, static fn (GraphNode $node): bool => $nodeName === $node->name);
            $this->assertInstanceOf(GraphNode::class, $node);
            $this->assertSame($source->elements[$node->id], $mapped->elements[$node->id]);
            $actual = [$node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2)];
            if ($match['exact']) {
                foreach ($actual as $axis => $value) {
                    $this->assertEqualsWithDelta($match['bounds'][$axis], $value, delta: 0.000_01);
                }
                continue;
            }
            $this->assertLessThanOrEqual($match['bounds'][0], $actual[0]);
            $this->assertLessThanOrEqual($match['bounds'][1], $actual[1]);
            $this->assertGreaterThanOrEqual($match['bounds'][2], $actual[2]);
            $this->assertGreaterThanOrEqual($match['bounds'][3], $actual[3]);
        }
    }

    public static function browserFixtures(): iterable
    {
        $reference = \json_decode((string) \file_get_contents(__DIR__.'/../fixtures/mermaid/cli-svg-reference.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
        foreach ($reference['diagrams'] as $name => $expected) {
            yield $name => [$name, $expected];
        }
    }

    public function testDepthBoundsYieldAndInvalidViewportSizesAreInert(): void
    {
        $body = \str_repeat('<g>', times: 66).'<g data-id="A"><rect width="10" height="10"/></g>'.\str_repeat('</g>', times: 66);
        $this->assertNull(self::map($body, self::source()));
        foreach ([[0.0, 50.0], [100.0, 0.0], [\NAN, 50.0], [100.0, \INF]] as [$width, $height]) {
            $this->assertNull(self::map('<g data-id="A"><rect width="10" height="10"/></g>', self::source(), $width, $height));
        }
        $work = MermaidSvgMap::project('<svg><g data-id="A"><rect width="10" height="10"/></g></svg>', self::source(), width: 100.0, height: 50.0);
        $steps = \iterator_to_array($work, preserve_keys: false);
        $this->assertGreaterThan(4, \count($steps));
        $this->assertSame([1 => 4], $work->getReturn()->elements);
    }

    /**
     * An element the picture cannot place — drawn twice, or squeezed to
     * nothing against the picture's edge — is passed over, and the next one
     * still placed: a plain box, not a dashed one.
     */
    public function testAnElementThatCannotBePlacedLeavesTheNextOneItsPlace(): void
    {
        $twice = '<g data-id="A"><rect width="10" height="10"/></g><g data-id="A"><rect x="20" width="10" height="10"/></g>';
        $atTheRightEdge = '<g data-id="A"><rect x="100" width="10" height="10"/></g>';
        $atTheBottomEdge = '<g data-id="A"><rect y="50" width="10" height="10"/></g>';
        $placed = '<g data-id="B"><rect x="60" y="20" width="30" height="20"/></g>';

        foreach (['drawn twice' => $twice, 'at the right edge' => $atTheRightEdge, 'at the bottom edge' => $atTheBottomEdge] as $what => $unplaced) {
            $map = self::map($unplaced.$placed, self::source());
            $this->assertInstanceOf(MermaidScene::class, $map, $what);
            $this->assertSame([2 => 7], $map->elements, $what);
            $this->assertFalse($map->graph->nodes[2]->dashed);
        }
    }
}
