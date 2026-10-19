<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;

/**
 * Pinned `<prefix>FontSize` and `<prefix>FontWeight` options as native text: a scale of the
 * pinned default size, since native text measures in cells, and a face.
 */
final class MermaidFonts
{
    /**
     * The scale and face a family's font options set: `bold`, `bolder` or a weight from 600
     * draws bold, and a size that is no positive length keeps the default.
     *
     * @return array{float, GraphFont}
     */
    public static function configured(MermaidOptions $options, string $family, string $prefix, float $default): array
    {
        $pixels = self::pixels($options->value($family, $prefix.'FontSize')) ?? $default;
        // @mago-expect analysis:mixed-assignment -- A weight is a keyword or a number, narrowed below.
        $weight = $options->value($family, $prefix.'FontWeight');
        $bold = \in_array($weight, ['bold', 'bolder'], strict: true) || (\is_numeric($weight) && (float) $weight >= 600);

        return [$pixels > 0 ? $pixels / $default : 1.0, $bold ? GraphFont::Bold : GraphFont::Regular];
    }

    /** A CSS size, a number or `N` / `Npx`, in pixels; null for anything else. */
    public static function pixels(mixed $size): ?float
    {
        $match = [];
        if (\is_string($size) && 1 === \preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/', \trim($size), $match)) {
            // @mago-expect analysis:invalid-type-cast -- the preceding pattern accepts only a decimal number
            $size = (float) $match[1];
        }

        return \is_int($size) || \is_float($size) ? (float) $size : null;
    }
}
