<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphCluster;
use Porta\GraphViewer\Graph\GraphEdge;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphNode;
use Porta\GraphViewer\Mermaid\MermaidColor;
use Porta\GraphViewer\Mermaid\MermaidPalette;

/**
 * Native theme roles against the paint the pinned build computes for the same documents in
 * every theme variant of census-reference.json.
 */
final class MermaidFamilyPaintTest extends TestCase
{
    private const string CENSUS = __DIR__.'/../fixtures/mermaid/themes/census-reference.json';
    private const string CHECKS = __DIR__.'/../fixtures/mermaid/themes/paint-checks.json';

    /**
     * Per family (paint-checks.json): a census element prefix (optionally `|color-N`, the color
     * theme slot it must carry when the theme assigns slots, and led by `?` when some themes do
     * not draw it) and CSS property, the native element and channel that must paint the same
     * color, and the element opacity the native alpha folds in. Native elements are
     * `node:<name>` (`#N` for the Nth of that name), `cluster:<index>`, `edge:<index>` or
     * `edge:label` (the first labeled edge); channels are fill, stroke, text.
     *
     * @return array<string, list<array{string, string, string, string, float}>>
     */
    private static function checks(): array
    {
        /** @var array<string, list<array{string, string, string, string, float}>> */
        return \json_decode((string) \file_get_contents(self::CHECKS), associative: true, flags: \JSON_THROW_ON_ERROR);
    }

    #[DataProvider('variants')]
    public function testFamilyRolesPaintLikeThePinnedBuild(string $family, string $variant, string $source, \stdClass $census): void
    {
        $scene = NativeFixtureScene::render($source);
        $this->assertInstanceOf(MermaidPalette::class, $scene->palette);
        $graph = $scene->palette->paint($scene->graph);
        foreach (self::checks()[$family] as [$element, $property, $native, $channel, $opacity]) {
            $paint = self::paint($census, \ltrim($element, characters: '?'), $property, optional: \str_starts_with($element, '?'));
            if (null === $paint) {
                continue; // An optional element this theme does not draw.
            }
            if (\str_starts_with($paint, 'url(')) {
                continue; // A neo gradient stroke; its first stop is the node border drawn natively.
            }
            self::assertColor($paint, self::channel(self::element($graph, $native), $channel), $family.' '.$variant.' '.$native.' '.$channel, $opacity);
        }
    }

    public static function variants(): iterable
    {
        $reference = self::reference();
        foreach (\array_keys(self::checks()) as $family) {
            foreach ($reference->census->{$family} as $variant => $census) {
                yield $family.' '.$variant => [$family, $variant, $reference->variants->{$variant}.$reference->families->{$family}, $census];
            }
        }
    }

    public function testNeoShadowsFollowTheThemeAndOnlyNeoFamiliesCastThem(): void
    {
        $redux = NativeFixtureScene::render("flowchart TD\nA-->B");
        $this->assertSame([4.0, 4.0, '#0000000f'], $redux->palette?->shadow());
        $default = NativeFixtureScene::render("---\nconfig: {theme: default}\n---\nflowchart TD\nA-->B");
        $this->assertSame([1.0, 2.0, '#b9b9b9'], $default->palette?->shadow());
        $classic = NativeFixtureScene::render("---\nconfig: {look: classic}\n---\nflowchart TD\nA-->B");
        $this->assertNull($classic->palette?->shadow());
        $this->assertNull(NativeFixtureScene::render("pie\n\"A\": 1")->palette?->shadow());
    }

    private static function element(GraphLayout $graph, string $selector): GraphNode|GraphEdge|GraphCluster
    {
        [$kind, $key] = \explode(':', $selector, limit: 2);
        // `node:name#N` is the Nth node of that name, where a rule title and a reference share it.
        [$name, $nth] = \array_pad(\explode('#', $key, limit: 2), length: 2, value: '1');
        $element = match ($kind) {
            'node' => \array_values(\array_filter($graph->nodes, static fn (GraphNode $node): bool => $name === $node->name))[(int) $nth - 1] ?? null,
            'cluster' => $graph->clusters[(int) $key] ?? null,
            default => 'label' === $key ? \array_find($graph->edges, static fn (GraphEdge $edge): bool => null !== $edge->label) : $graph->edges[(int) $key] ?? null,
        };
        self::assertNotNull($element, 'No native '.$selector);

        return $element;
    }

    private static function channel(GraphNode|GraphEdge|GraphCluster $element, string $channel): ?string
    {
        return match ($channel) {
            'fill' => $element instanceof GraphEdge ? null : $element->fillColor,
            'stroke' => $element->color,
            default => $element->textColor,
        };
    }

    /** The browser converts HSL in float precision, so channels may differ by one. */
    private static function assertColor(string $css, ?string $actual, string $message, float $opacity): void
    {
        if ('none' === $css) {
            self::assertSame('none', $actual, $message);

            return;
        }
        $expected = MermaidColor::css($css);
        self::assertNotNull($expected, $message);
        if (1.0 !== $opacity) {
            // The census records the fill; the element's CSS opacity folds into the native alpha.
            $alpha = 9 === \strlen($expected) ? \hexdec(\substr($expected, offset: 7)) : 255;
            $expected = \sprintf('%s%02x', \substr($expected, offset: 0, length: 7), (int) \round($alpha * $opacity));
        }
        if ('none' === $actual && 9 === \strlen($expected) && \str_ends_with($expected, '00')) {
            return; // A transparent paint draws nothing, as `none` does.
        }
        self::assertNotNull($actual, $message);
        self::assertSame(\strlen($expected), \strlen($actual), $message.': '.$expected.' vs '.$actual);
        foreach (\str_split(\substr($expected, offset: 1), length: 2) as $index => $channel) {
            self::assertEqualsWithDelta(\hexdec($channel), \hexdec(\substr($actual, 1 + (2 * $index), length: 2)), 1, $message.': '.$expected.' vs '.$actual);
        }
    }

    // @mago-expect lint:no-boolean-flag-parameter -- Whether the census must draw the element is the one fact a check adds.
    private static function paint(\stdClass $census, string $element, string $property, bool $optional = false): ?string
    {
        [$prefix, $slot] = \array_pad(\explode('|', $element, limit: 2), length: 2, value: null);
        foreach ($census->elements as $paint) {
            if (\str_starts_with($paint->element, (string) $prefix) && (null === $slot || null === $paint->colorId || $slot === $paint->colorId)) {
                return $paint->{$property};
            }
        }
        if ($optional) {
            return null;
        }
        self::fail('The census has no '.$element);
    }

    private static function reference(): \stdClass
    {
        $reference = \json_decode((string) \file_get_contents(self::CENSUS), associative: false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $reference);

        return $reference;
    }
}
