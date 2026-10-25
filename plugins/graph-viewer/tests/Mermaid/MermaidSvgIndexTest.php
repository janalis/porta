<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidSvgIndex;

/**
 * The bounds the index keeps reading mmdc's SVG to — a picture of a size, a
 * document of a size and a depth, nothing hidden — and how it names what it
 * finds there. Each limit at its edge: the last thing it takes and the first
 * it refuses.
 */
final class MermaidSvgIndexTest extends TestCase
{
    private const string NODE = '<g data-id="n"><rect x="1" y="1" width="4" height="4"/></g>';

    public function testOnlyAPictureOfARealSizeIsRead(): void
    {
        foreach (['no width' => [0.0, 50.0], 'no height' => [50.0, 0.0], 'endless width' => [\INF, 50.0], 'endless height' => [50.0, \INF], 'no number' => [\NAN, 50.0]] as $what => [
            $width,
            $height,
        ]) {
            $this->assertNull(self::read(self::svg(self::NODE), $width, $height), $what);
        }
        $this->assertInstanceOf(MermaidSvgIndex::class, self::read(self::svg(self::NODE), 1.0, 1.0));
    }

    public function testADocumentOfFourMebibytesIsReadAndOneByteMoreIsNot(): void
    {
        $svg = self::svg(self::NODE);
        $padded = \str_replace('<g ', '<!--'.\str_repeat('x', 4_194_304 - \strlen($svg) - 7).'--><g ', $svg);
        $this->assertSame(4_194_304, \strlen($padded));

        $this->assertInstanceOf(MermaidSvgIndex::class, self::read($padded));
        $this->assertNull(self::read(\str_replace(search: '<!--', replace: '<!-- ', subject: $padded)));
    }

    public function testADocumentOf32768ElementsIsReadAndOneMoreIsNot(): void
    {
        $this->assertInstanceOf(MermaidSvgIndex::class, self::read(self::svg(\str_repeat('<g/>', times: 32_766).self::NODE)), 'the root, 32 765 groups, the node and its rect');
        $this->assertNull(self::read(self::svg(\str_repeat('<g/>', times: 32_767).self::NODE)));
    }

    /** A node is the shapes under it, and a shape is read down to 64 deep: the root at 0, here the node at 63, its rect at 64. */
    public function testAShapeSixtyFourDeepIsReadAndOneDeeperIsNot(): void
    {
        $this->assertSame(['n'], \array_keys(self::read(self::nested(62))->ids));
        $this->assertSame([], self::read(self::nested(63))->ids);
    }

    public function testNothingHiddenIsIndexed(): void
    {
        $index = self::read(self::svg(
            '<defs>'
            .self::NODE
            .'</defs>'
            .'<g display="none"><g data-id="a"><rect x="1" y="1" width="4" height="4"/></g></g>'
            .'<g style="fill: red; DISPLAY: None"><g data-id="b"><rect x="1" y="1" width="4" height="4"/></g></g>'
            .'<g data-id="flat"><rect x="1" y="1" width="4" height="0"/></g>'
            .'<g data-id="shown">text first<rect x="1" y="1" width="4" height="4"/></g>',
        ));

        $this->assertSame(['shown'], \array_keys($index->ids));
    }

    /**
     * mmdc prefixes every id with the root's and suffixes a counter; the
     * diagram's own name is the part between, and an id that only starts
     * with the root's letters is someone else's.
     */
    public function testAnIdIsReadThroughTheRootsPrefixAndTheCounter(): void
    {
        $index = self::read(
            '<svg xmlns="http://www.w3.org/2000/svg" id="m" viewBox="0 0 100 50">'
            .'<g class="node" id="m-flowchart-Alpha-3"><rect x="1" y="1" width="4" height="4"/></g>'
            .'<g class="node" id="mflowchart-Beta-1"><rect x="1" y="1" width="4" height="4"/></g>'
            .'<g class="node" id="m-flowchart-Gamma-1x"><rect x="1" y="1" width="4" height="4"/></g>'
            .'</svg>',
        );

        $this->assertSame(['flowchart-Alpha-3', 'Alpha', 'mflowchart-Beta-1', 'flowchart-Gamma-1x'], \array_keys($index->ids));
    }

    public function testReadingLeavesLibxmlAsItFoundIt(): void
    {
        // Collecting errors already — switching it off would clear them anyway.
        $previous = \libxml_use_internal_errors(true);
        try {
            self::read('<svg xmlns="http://www.w3.org/2000/svg"><g></svg>');

            $this->assertSame([], \libxml_get_errors(), 'the broken document\'s errors are not left to the caller');
            $this->assertTrue(\libxml_use_internal_errors(false), 'and its error mode is as it was');
            self::read('<svg xmlns="http://www.w3.org/2000/svg"><g></svg>');
            $this->assertFalse(\libxml_use_internal_errors(true));
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    private static function svg(string $body): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50">'.$body.'</svg>';
    }

    /** The node inside $groups nested groups under the root. */
    private static function nested(int $groups): string
    {
        return self::svg(\str_repeat('<g>', $groups).self::NODE.\str_repeat('</g>', $groups));
    }

    private static function read(string $svg, float $width = 200.0, float $height = 100.0): ?MermaidSvgIndex
    {
        $work = MermaidSvgIndex::read($svg, $width, $height);
        \iterator_to_array($work);

        return $work->getReturn();
    }
}
