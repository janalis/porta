<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;

/** Inert, self-contained SVG: diagram text is text, never markup or executable content. */
final class MermaidSvg
{
    /** @return \Generator<int, null, mixed, string> */
    public static function render(MermaidDrawing $drawing): \Generator
    {
        $width = (string) $drawing->width;
        $height = (string) $drawing->height;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.\ceil($drawing->width * 10).'" height="'.\ceil($drawing->height * 10).'" viewBox="0 0 '.$width.' '.$height.'">';
        if (null !== $drawing->background) {
            $svg .= '<rect width="100%" height="100%" fill="'.self::escape($drawing->background).'"/>';
        }
        $faces = [GraphFont::Regular->value => GraphFont::Regular];
        foreach ($drawing->marks as $mark) {
            $faces[$mark->font->value] = $mark->font;
            yield null;
        }
        $svg .= '<style>';
        foreach ($faces as $face) {
            $font = (string) \file_get_contents(MermaidFont::file($face));
            $svg .= '@font-face{font-family:PortaMermaid;font-style:'.$face->style().';font-weight:'.$face->weight().';src:url(data:font/ttf;base64,'.\base64_encode($font).')}';
            yield null;
        }
        $svg .= 'text{font-family:PortaMermaid,monospace,sans-serif;font-size:1.6px;text-anchor:middle}</style>';
        $svg .= '<metadata>'.self::escape((string) \file_get_contents(__DIR__.'/../../resources/fonts/OFL.txt')).'</metadata>';
        foreach ($drawing->marks as $index => $mark) {
            $svg .= self::mark($mark, $index);
            yield null;
        }

        return $svg.'</svg>';
    }

    private static function mark(MermaidMark $mark, int $index): string
    {
        return match ($mark->kind) {
            'image' => self::image($mark),
            'text' => self::text($mark),
            default => self::gradient($mark, 'gradient-'.$index).self::outline($mark, null === $mark->gradient ? $mark->fill : 'url(#gradient-'.$index.')'),
        };
    }

    /** A polygon's left-to-right gradient, spanning its own horizontal extent. */
    private static function gradient(MermaidMark $mark, string $id): string
    {
        if (null === $mark->gradient) {
            return '';
        }
        $xs = \array_column($mark->points, 0);
        $stops = '';
        foreach ($mark->gradient as $offset => $color) {
            $alpha = 9 === \strlen($color) ? \hexdec(\substr($color, offset: 7)) / 255 : 1.0;
            $stops .= '<stop offset="'.$offset.'" stop-color="'.self::escape(\substr($color, offset: 0, length: 7)).'" stop-opacity="'.\round($alpha, precision: 4).'"/>';
        }

        return '<linearGradient id="'.$id.'" gradientUnits="userSpaceOnUse" x1="'.\min($xs).'" y1="0" x2="'.\max($xs).'" y2="0">'.$stops.'</linearGradient>';
    }

    /** The URI was rebuilt from validated image bytes, never copied from the document. */
    private static function image(MermaidMark $mark): string
    {
        [[$left, $top], [$right, $bottom]] = $mark->points;

        return '<image x="'.$left.'" y="'.$top.'" width="'.($right - $left).'" height="'.($bottom - $top).'" preserveAspectRatio="none" href="'.self::escape($mark->text).'"/>';
    }

    private static function text(MermaidMark $mark): string
    {
        [$x, $y] = $mark->points[0];

        $style = GraphFont::Regular === $mark->font ? '' : ' font-style="'.$mark->font->style().'" font-weight="'.$mark->font->weight().'"';

        $style .= 1.0 === $mark->fontScale ? '' : ' style="font-size:'.(1.6 * $mark->fontScale).'px"';
        $style .= 0.0 === $mark->angle ? '' : ' transform="rotate('.$mark->angle.' '.$x.' '.$y.')"';

        return '<text x="'.$x.'" y="'.$y.'" fill="'.self::escape($mark->stroke).'"'.$style.'>'.self::escape($mark->text).'</text>';
    }

    private static function outline(MermaidMark $mark, string $fill): string
    {
        $points = \implode(' ', \array_map(static fn (array $point): string => $point[0].','.$point[1], $mark->points));
        $tag = 'polygon' === $mark->kind ? 'polygon' : 'polyline';
        $fill = 'polygon' === $mark->kind ? $fill : 'none';

        return (
            '<'
            .$tag
            .' points="'
            .$points
            .'" stroke="'
            .self::escape($mark->stroke)
            .'" fill="'
            .self::escape($fill)
            .'" stroke-width="'
            .$mark->strokeWidth
            .'"'
            .(1.0 === $mark->fillOpacity ? '' : ' fill-opacity="'.$mark->fillOpacity.'"')
            .(1.0 === $mark->opacity ? '' : ' opacity="'.$mark->opacity.'"')
            .($mark->dashed ? ' stroke-dasharray="'.\implode(' ', \array_map(static fn (float $length): string => (string) \round($length, precision: 4), $mark->dashes ?? [0.6, 0.4])).'"' : '')
            .'/>'
        );
    }

    private static function escape(string $text): string
    {
        $text = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', replacement: '', subject: $text) ?? $text;

        return \htmlspecialchars($text, flags: \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE, encoding: 'UTF-8');
    }
}
