<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\DotColor;

/** Only the four upstream point properties are accepted; numeric extents are bounded. */
final class QuadrantStyles
{
    /** @return array<string, float|string> */
    public static function parse(string $text, MermaidStatement $statement): array
    {
        if ('' === \trim($text)) {
            return [];
        }
        $style = [];
        foreach (\explode(',', $text) as $property) {
            $match = [];
            if (1 !== \preg_match('/^\s*(radius|color|stroke-color|stroke-width)\s*:\s*(\S+)\s*$/', $property, $match)) {
                throw $statement->error('Invalid or unsupported quadrant point style');
            }
            [1 => $key, 2 => $value] = $match;
            if ('color' === $key || 'stroke-color' === $key) {
                if (1 !== \preg_match('/^#(?:[a-f\d]{3}|[a-f\d]{6})$/i', $value)) {
                    throw $statement->error('Quadrant color requires a three or six digit hex color');
                }
                $style[$key] = DotColor::normalize($value) ?? '#333333';
                continue;
            }
            $pattern = 'radius' === $key ? '/^\d+$/' : '/^\d+px$/';
            if (1 !== \preg_match($pattern, $value)) {
                throw $statement->error('Quadrant radius requires an integer; stroke-width requires integer pixels');
            }
            // @mago-expect analysis:invalid-type-cast -- The style grammar validates an integer and optional px suffix.
            $number = (float) \rtrim($value, characters: 'px');
            if ($number > 1000) {
                throw $statement->error('Quadrant point style exceeds the 1000 pixel limit');
            }
            $style[$key] = $number;
        }

        return $style;
    }
}
