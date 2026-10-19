<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/**
 * Flowchart `@{ icon }` and `@{ img }` nodes: the picture above its label, or below it with
 * `pos: t`, sized in pinned pixels at ten to a unit.
 *
 * The pinned build registers no icon pack, so every icon draws its unknown-icon tile. An image
 * draws when its source is a `data:` PNG, JPEG, GIF or WebP; a remote or relative source draws a
 * placeholder of the same size, because native rendering never fetches.
 */
final class FlowchartMedia
{
    /** Pinned icon nodes default to 48px; images without a size keep theirs, else 100px. */
    private const float ICON = 4.8;
    private const float IMAGE = 10.0;
    /** A picture spans one unit at least and sixty at most. */
    private const float LARGEST = 60.0;
    private const array TYPES = [\IMAGETYPE_PNG => 'image/png', \IMAGETYPE_JPEG => 'image/jpeg', \IMAGETYPE_GIF => 'image/gif', \IMAGETYPE_WEBP => 'image/webp'];

    /**
     * The shape and size of an icon or image node.
     *
     * @param array<string, string> $values the node's `@{…}` properties
     * @return array{string, array{float, float}}
     */
    public static function node(array $values, string $label): array
    {
        $top = 't' === ($values['pos'] ?? 'b');
        $image = \array_key_exists('img', $values);
        $data = $image ? self::data($values['img']) : null;
        [$width, $height] = $image ? self::picture($values, $data) : [self::ICON, self::ICON];
        $height = $image ? $height : self::units($values['h'] ?? '', self::ICON);
        $width = $image ? $width : $height;
        $lines = '' === $label ? 0 : \substr_count($label, needle: "\n") + 1;
        $labelWidth = MermaidText::box($label)[0] - 2;
        $shape = ($image ? 'fc-image:' : 'fc-icon:').($top ? 't' : 'b').':'.$width.':'.$height.($image ? ':'.\base64_encode($data ?? '') : '');

        return [$shape, [\max($width, $labelWidth), $height + (0 === $lines ? 0.0 : (2 * $lines) + 1)]];
    }

    /** @return list<MermaidMark> */
    public static function marks(GraphNode $node, string $shape): array
    {
        [$kind, $position, $width, $height] = self::parts($shape);
        $top = $node->y - ($node->h / 2);
        $y = 't' === $position ? $node->y + ($node->h / 2) - ($height / 2) : $top + ($height / 2);
        $marks = 'fc-icon' === $kind ? ArchitectureSymbols::tile('', $node->x, $y, $height) : self::image($node, $shape, $node->x, $y, $width, $height);
        if ($node->h > $height) {
            // The label sits on a backdrop between the picture and the node's edge.
            $band = 't' === $position ? [$top, $top + $node->h - $height] : [$top + $height, $top + $node->h];
            $half = \min($node->w, MermaidText::box($node->label)[0] - 2) / 2;
            $marks[] = new MermaidMark(
                'polygon',
                [[$node->x - $half, $band[0]], [$node->x + $half, $band[0]], [$node->x + $half, $band[1]], [$node->x - $half, $band[1]]],
                stroke: 'none',
                fill: $node->fillColor ?? '#ffffff',
                strokeWidth: 0.0,
            );
        }

        return $marks;
    }

    /** The label's offset: the band beside the picture, as a fraction of the node's height. */
    public static function labelOffset(string $shape, GraphNode $node): float
    {
        [, $position, , $height] = self::parts($shape);
        $center = ($height / 2) / $node->h;

        return 't' === $position ? -$center : $center;
    }

    /** @return array{string, string, float, float} kind, label side, width and height */
    private static function parts(string $shape): array
    {
        [$kind, $position, $width, $height] = \explode(':', $shape) + ['', 'b', '0', '0'];

        return [$kind, $position, \is_numeric($width) ? (float) $width : 0.0, \is_numeric($height) ? (float) $height : 0.0];
    }

    /** @return list<MermaidMark> */
    private static function image(GraphNode $node, string $shape, float $x, float $y, float $width, float $height): array
    {
        $bytes = (string) \base64_decode(\explode(':', $shape, limit: 5)[4] ?? '', strict: true);
        $box = [[$x - ($width / 2), $y - ($height / 2)], [$x + ($width / 2), $y + ($height / 2)]];
        if ('' !== $bytes) {
            return [new MermaidMark('image', $box, self::uri($bytes))];
        }
        [[$left, $upper], [$right, $lower]] = $box;
        $color = $node->color ?? '#333333';

        // A placeholder for a source native rendering does not fetch: a frame, crossed.
        return [
            new MermaidMark('polygon', [[$left, $upper], [$right, $upper], [$right, $lower], [$left, $lower]], stroke: $color, fill: 'none'),
            new MermaidMark('polyline', [[$left, $upper], [$right, $lower]], stroke: $color),
            new MermaidMark('polyline', [[$left, $lower], [$right, $upper]], stroke: $color),
        ];
    }

    /** The bytes of a `data:` image of a supported type, else null. */
    private static function data(string $source): ?string
    {
        $match = [];
        if (1 !== \preg_match('~^data:[\w/+.-]*(;base64)?,(.*)$~s', \trim($source), $match)) {
            return null;
        }
        $bytes = '' === $match[1] ? \rawurldecode($match[2]) : \base64_decode(\preg_replace('/\s+/', replacement: '', subject: $match[2]) ?? '', strict: true);
        $size = false === $bytes ? null : self::size($bytes);

        return null === $size ? null : (string) $bytes;
    }

    /** A data URI rebuilt from validated bytes, so the export never carries authored text. */
    private static function uri(string $bytes): string
    {
        return 'data:'.(self::size($bytes)[2] ?? 'image/png').';base64,'.\base64_encode($bytes);
    }

    /**
     * The pixel size and type of image bytes of a supported type, read from their header.
     *
     * @return ?array{int, int, string}
     */
    private static function size(string $bytes): ?array
    {
        // A malformed header warns before returning false; false is the answer.
        \set_error_handler(static fn (): bool => true);
        try {
            $size = \getimagesizefromstring($bytes);
        } finally {
            \restore_error_handler();
        }
        $type = false === $size ? null : self::TYPES[$size[2]] ?? null;

        return null === $type || false === $size ? null : [$size[0], $size[1], $type];
    }

    /**
     * An image's size: both authored sides, one side and the picture's proportions, its own
     * pixels, or the default.
     *
     * @param array<string, string> $values
     * @return array{float, float}
     */
    private static function picture(array $values, ?string $data): array
    {
        $natural = null === $data ? null : self::size($data);
        [$naturalWidth, $naturalHeight] = null === $natural ? [self::IMAGE, self::IMAGE] : [$natural[0] / 10, $natural[1] / 10];
        $width = \array_key_exists('w', $values) ? self::units($values['w'], $naturalWidth) : null;
        $height = \array_key_exists('h', $values) ? self::units($values['h'], $naturalHeight) : null;
        $ratio = $naturalHeight / \max(0.1, $naturalWidth);

        return [
            self::clamp($width ?? (null === $height ? $naturalWidth : $height / $ratio)),
            self::clamp($height ?? (null === $width ? $naturalHeight : $width * $ratio)),
        ];
    }

    /** Pinned pixels as units, ten to one; anything but a positive number keeps the default. */
    private static function units(string $pixels, float $default): float
    {
        return \is_numeric($pixels) && (float) $pixels > 0 ? self::clamp((float) $pixels / 10) : $default;
    }

    private static function clamp(float $units): float
    {
        return \max(1.0, \min(self::LARGEST, $units));
    }
}
