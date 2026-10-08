<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Graph;

/**
 * Maps `dot -Tjson0` output to a {@see GraphLayout}. json0 is the laid-out
 * graph with its original attributes but no xdot draw ops: every numeric
 * travels as a string, node width/height are INCHES (positions are points),
 * and the coordinate system is y-up with the origin bottom-left. The parser
 * converts once — inches ×72, `y' = ury − y` on every coordinate — so the
 * value objects are uniformly points, y-down, and the renderer never thinks
 * about Graphviz conventions again.
 *
 * Lenient where dot is expressive (unknown shapes render as boxes, compound
 * edges into clusters are skipped, degenerate splines fall back to straight
 * polylines), strict where the wire format is broken (missing bb, non-numeric
 * coordinates) — those raise {@see GraphParseException}.
 */
final class DotJsonParser
{
    /** Cubic segments are sampled roughly every this many points of chord length. */
    private const float FLATTEN_CHORD_PT = 6.0;

    public static function parse(string $json): GraphLayout
    {
        try {
            /** @var array<array-key, mixed>|scalar|null $data */
            $data = \json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GraphParseException('dot produced invalid JSON: '.$e->getMessage(), previous: $e);
        }
        if (!\is_array($data)) {
            throw new GraphParseException('dot produced a non-object JSON document');
        }
        [$llx, $lly, $urx, $ury] = self::floats($data['bb'] ?? null, count: 4, what: 'graph bb');

        $subgraphCount = (int) ($data['_subgraph_cnt'] ?? 0);
        $objects = \is_array($data['objects'] ?? null) ? \array_values($data['objects']) : [];
        $nodes = [];
        $clusters = [];
        /** @var mixed $object */
        foreach ($objects as $index => $object) {
            if (!\is_array($object)) {
                throw new GraphParseException('graph object is not a JSON object');
            }
            if ($index < $subgraphCount) {
                $cluster = self::cluster($object, $llx, $ury);
                if (null !== $cluster) {
                    $clusters[] = $cluster;
                }
                continue;
            }
            $node = self::node($object, $llx, $ury);
            $nodes[$node->id] = $node;
        }

        $edges = [];
        /** @var mixed $edge */
        foreach (\is_array($data['edges'] ?? null) ? $data['edges'] : [] as $edge) {
            if (!\is_array($edge)) {
                throw new GraphParseException('graph edge is not a JSON object');
            }
            $tail = (int) ($edge['tail'] ?? -1);
            $head = (int) ($edge['head'] ?? -1);
            if (!\array_key_exists($tail, $nodes) || !\array_key_exists($head, $nodes)) {
                continue; // a compound edge clipped to a cluster boundary — nothing to anchor it to
            }
            $edges[] = self::edge($edge, $tail, $head, $llx, $ury);
        }

        return new GraphLayout($urx - $llx, $ury - $lly, $nodes, $edges, $clusters);
    }

    /** @return ?GraphCluster null for non-cluster subgraphs (plain grouping, no box to draw) */
    private static function cluster(array $object, float $llx, float $ury): ?GraphCluster
    {
        $name = (string) ($object['name'] ?? '');
        if (!\array_key_exists('bb', $object) || !\str_starts_with($name, 'cluster')) {
            return null;
        }
        [$x0, $y0, $x1, $y1] = self::floats($object['bb'], count: 4, what: 'cluster bb');

        return new GraphCluster(
            $name,
            self::label($object['label'] ?? '') ?? '',
            $x0 - $llx,
            $ury - $y1, // the flip swaps which bb corner is the top
            $x1 - $llx,
            $ury - $y0,
            DotColor::normalize(self::attr($object, 'color')),
            self::optionalPoint($object['lp'] ?? null, $llx, $ury),
        );
    }

    private static function node(array $object, float $llx, float $ury): GraphNode
    {
        /** @var mixed $id */
        $id = $object['_gvid'] ?? null;
        /** @var mixed $name */
        $name = $object['name'] ?? null;
        if (!\is_int($id) || !\is_string($name)) {
            throw new GraphParseException('graph node lacks _gvid or name');
        }
        [$x, $y] = self::floats($object['pos'] ?? null, count: 2, what: "node {$name} pos");
        [$w] = self::floats($object['width'] ?? null, count: 1, what: "node {$name} width");
        [$h] = self::floats($object['height'] ?? null, count: 1, what: "node {$name} height");
        $style = self::attr($object, 'style') ?? '';

        return new GraphNode(
            $id,
            $name,
            self::label($object['label'] ?? '\N') ?? $name,
            $x - $llx,
            $ury - $y,
            $w * 72.0,
            $h * 72.0,
            DotColor::normalize(self::attr($object, 'color')),
            DotColor::normalize(self::attr($object, 'fillcolor')),
            \str_contains($style, 'dash') || \str_contains($style, 'dot'),
        );
    }

    private static function edge(array $edge, int $tail, int $head, float $llx, float $ury): GraphEdge
    {
        [$points, $tip] = self::spline((string) ($edge['pos'] ?? ''), $llx, $ury);
        [$minX, $minY] = $points[0];
        [$maxX, $maxY] = $points[0];
        foreach ($points as [$x, $y]) {
            $minX = \min($minX, $x);
            $maxX = \max($maxX, $x);
            $minY = \min($minY, $y);
            $maxY = \max($maxY, $y);
        }
        if (null !== $tip) {
            $minX = \min($minX, $tip[0]);
            $maxX = \max($maxX, $tip[0]);
            $minY = \min($minY, $tip[1]);
            $maxY = \max($maxY, $tip[1]);
        }
        $style = self::attr($edge, 'style') ?? '';

        return new GraphEdge(
            $tail,
            $head,
            $points,
            $tip,
            $minX,
            $minY,
            $maxX,
            $maxY,
            DotColor::normalize(self::attr($edge, 'color')),
            self::label($edge['label'] ?? ''),
            self::optionalPoint($edge['lp'] ?? null, $llx, $ury),
            \str_contains($style, 'dash') || \str_contains($style, 'dot'),
        );
    }

    /**
     * Parse a spline string — optional `e,x,y` (arrowhead tip) / `s,x,y`
     * prefixes, then control points. A well-formed B-spline has 3n+1 points
     * and is flattened by sampling each cubic; anything else is used verbatim
     * as a polyline.
     *
     * @return array{list<array{float, float}>, ?array{float, float}}
     */
    private static function spline(string $pos, float $llx, float $ury): array
    {
        $tokens = \preg_split('/\s+/', \trim($pos), flags: \PREG_SPLIT_NO_EMPTY);
        \assert(false !== $tokens, description: 'the pattern is valid, preg_split cannot fail');
        $tip = null;
        $points = [];
        foreach ($tokens as $token) {
            if (\str_starts_with($token, 'e,')) {
                $tip = self::point(\substr($token, offset: 2), $llx, $ury, what: 'edge arrow tip');
                continue;
            }
            if (\str_starts_with($token, 's,')) {
                continue; // tail-arrow point — Porta draws head arrows only
            }
            $points[] = self::point($token, $llx, $ury, what: 'edge spline point');
        }
        if (\count($points) < 2) {
            throw new GraphParseException('edge spline has fewer than two points');
        }
        if (1 !== (\count($points) % 3)) {
            return [$points, $tip]; // not 3n+1: use the raw points as a polyline
        }
        $first = $points[0];
        $poly = [$first];
        for ($i = 0, $length = \count($points); ($i + 3) < $length; $i += 3) {
            $p0 = $points[$i];
            $p1 = $points[$i + 1];
            $p2 = $points[$i + 2];
            $p3 = $points[$i + 3];
            $chord = \hypot($p3[0] - $p0[0], $p3[1] - $p0[1]);
            $steps = \max(2, (int) \ceil($chord / self::FLATTEN_CHORD_PT));
            for ($j = 1; $j <= $steps; ++$j) {
                $t = $j / $steps;
                $u = 1.0 - $t;
                $poly[] = [
                    ($u * $u * $u * $p0[0]) + (3 * $u * $u * $t * $p1[0]) + (3 * $u * $t * $t * $p2[0]) + ($t * $t * $t * $p3[0]),
                    ($u * $u * $u * $p0[1]) + (3 * $u * $u * $t * $p1[1]) + (3 * $u * $t * $t * $p2[1]) + ($t * $t * $t * $p3[1]),
                ];
            }
        }

        return [$poly, $tip];
    }

    /** Resolve `\N` to nothing (caller substitutes), normalize `\n|\l|\r` line breaks, map '' to null. */
    private static function label(mixed $raw): ?string
    {
        $label = \is_string($raw) ? $raw : '';
        if ('' === $label || '\N' === $label) {
            return null;
        }

        return \str_replace(['\n', '\l', '\r'], replace: "\n", subject: $label);
    }

    private static function attr(array $object, string $key): ?string
    {
        /** @var mixed $value */
        $value = $object[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @return ?array{float, float} */
    private static function optionalPoint(mixed $raw, float $llx, float $ury): ?array
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return self::point($raw, $llx, $ury, what: 'label position');
    }

    /** @return array{float, float} flipped to screen-down */
    private static function point(string $raw, float $llx, float $ury, string $what): array
    {
        [$x, $y] = self::floats($raw, count: 2, what: $what);

        return [$x - $llx, $ury - $y];
    }

    /**
     * Split a comma-separated numeric string ("27,18", "0,0,412,568", "0.75"),
     * tolerating the `!` pin suffix dot echoes back on fixed positions.
     *
     * @return list<float>
     */
    private static function floats(mixed $raw, int $count, string $what): array
    {
        if (!\is_string($raw)) {
            throw new GraphParseException("missing {$what}");
        }
        $parts = \explode(',', \rtrim($raw, characters: '!'));
        if (\count($parts) !== $count) {
            throw new GraphParseException("malformed {$what}: \"{$raw}\"");
        }
        $floats = [];
        foreach ($parts as $part) {
            if (!\is_numeric($part)) {
                throw new GraphParseException("non-numeric {$what}: \"{$raw}\"");
            }
            $floats[] = (float) $part;
        }

        return $floats;
    }
}
