<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphNode;

/**
 * Pinned tree view icons, fourteen pixels before the label: the built-in `folder` and `file`
 * glyphs in `iconColor`, and the unknown-icon tile for every other name, since the pinned build
 * registers no other pack. Authored `icon(…)` wins, `icon()` hides it, and `showIcons` adds the
 * defaults, by file name and extension first.
 */
final class TreeViewIcons
{
    /** The icon and its gap, in units. */
    public const float SPAN = 1.8;
    private const float SIZE = 1.4;
    private const string PREFIX = 'mermaid-treeview:';

    /** Pinned `getNodeIcon`: the qualified icon a node draws, or null. */
    public static function icon(string $label, ?string $authored, MermaidOptions $options): ?string
    {
        $directory = \str_ends_with($label, '/');
        $shown = $options->flag('treeView', 'showIcons');
        $detected = $directory || !$shown ? null : self::detect($label, $options);
        $fallback = $directory ? 'folder' : 'file';
        $icon = $authored ?? ($shown ? $detected ?? $fallback : null);

        return null === $icon || 'none' === $icon ? null : self::qualified($icon, $options->text('treeView', 'defaultIconPack'));
    }

    /** @return list<MermaidMark> the glyph at the node's left, in the shape's base outline */
    public static function marks(GraphNode $node, string $shape): array
    {
        [$body, $color] = \explode('|', $shape, limit: 2) + ['', '#546e7a'];
        [, $icon, $base] = \explode(':', $body, limit: 3) + ['', '', 'text'];
        $x = $node->x - ($node->w / 2) + 1.0 + (self::SIZE / 2);

        return [
            ...MermaidOutline::marks($node, $base),
            ...match ($icon) {
                // The Material folder, a tab over a body, and file, its corner folded.
                'folder' => [new MermaidMark('polygon', self::material($x, $node->y, [
                    [2.0, 6.0],
                    [4.0, 4.0],
                    [9.17, 4.0],
                    [11.0, 6.0],
                    [20.0, 6.0],
                    [22.0, 8.0],
                    [22.0, 18.0],
                    [20.0, 20.0],
                    [4.0, 20.0],
                    [2.0, 18.0],
                ]), stroke: 'none', fill: $color, strokeWidth: 0.0)],
                'file' => [new MermaidMark('polygon', self::material($x, $node->y, [[4.0, 2.0], [14.0, 2.0], [20.0, 8.0], [20.0, 22.0], [4.0, 22.0]]), stroke: 'none', fill: $color, strokeWidth: 0.0)],
                default => ArchitectureSymbols::tile('', $x, $node->y, self::SIZE),
            },
        ];
    }

    /** Pinned `detectIcon`: `filenameIcons` by name, then `extensionIcons` by extension. */
    private static function detect(string $name, MermaidOptions $options): ?string
    {
        $dot = \strrpos($name, needle: '.');
        $extension = false === $dot || 0 === $dot ? '' : \strtolower(\substr($name, $dot));

        return (
            self::entry($options, 'filenameIcons', $name)
            ?? ('' === $extension ? null : self::entry($options, 'extensionIcons', $extension) ?? self::entry($options, 'extensionIcons', \substr($extension, offset: 1)))
        );
    }

    /** A string entry of a tree view option map. */
    private static function entry(MermaidOptions $options, string $option, string $key): ?string
    {
        // @mago-expect analysis:mixed-assignment -- An option map is a JSON value, narrowed below.
        $map = $options->value('treeView', $option);
        $entries = $map instanceof \stdClass ? \get_object_vars($map) : [];

        return \array_key_exists($key, $entries) && \is_string($entries[$key]) ? $entries[$key] : null;
    }

    /** Pinned `qualifyIcon`: a pack prefix kept, a built-in name or a missing pack the tree view's own. */
    private static function qualified(string $icon, string $pack): string
    {
        return match (true) {
            \str_contains($icon, ':') => $icon,
            \in_array($icon, ['folder', 'file'], strict: true) || '' === $pack => self::PREFIX.$icon,
            default => $pack.':'.$icon,
        };
    }

    /**
     * A node's shape with its icon: the glyph a qualified icon draws (a built-in one, else the
     * unknown tile), the base outline, and the icon color as a theme token.
     */
    public static function shape(string $icon, string $base): string
    {
        $glyph = \in_array($icon, [self::PREFIX.'folder', self::PREFIX.'file'], strict: true) ? \substr($icon, \strlen(self::PREFIX)) : 'unknown';

        return 'tv-icon:'.$glyph.':'.$base.'|@treeView.iconColor|=#546e7a';
    }

    /**
     * A Material glyph's outline, from its 24-pixel grid to the icon's size about its center.
     *
     * @param list<array{float, float}> $grid
     * @return list<array{float, float}>
     */
    private static function material(float $x, float $y, array $grid): array
    {
        $scale = self::SIZE / 24;

        return \array_map(static fn (array $point): array => [$x + (($point[0] - 12) * $scale), $y + (($point[1] - 12) * $scale)], $grid);
    }
}
