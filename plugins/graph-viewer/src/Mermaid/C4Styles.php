<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

/** C4 roles have stable colors, independent of declaration order and of the theme. */
final class C4Styles
{
    /**
     * Pinned `c4` `<type>_bg_color` and `<type>_border_color`: `SystemDb_Ext` reads
     * `external_system_db_bg_color`, and so on; authored `$bgColor` and `$borderColor` win.
     *
     * @param array<string, string> $attributes
     * @return array{fill: string, stroke: string, text: string}
     */
    public static function colors(string $macro, array $attributes, MermaidStatement $statement, MermaidOptions $options): array
    {
        $role = \array_find(['Person', 'System', 'Container', 'Component'], static fn (string $role): bool => \str_starts_with($macro, $role)) ?? 'Component';
        $type = self::type($macro);
        $fill = MermaidColor::css($options->text('c4', $type.'_bg_color')) ?? '#85bbf0';
        $border = MermaidColor::css($options->text('c4', $type.'_border_color')) ?? '#78a8d8';
        $text = 'Component' === $role ? '#333333' : '#ffffff';

        return [
            'fill' => MermaidColor::parse('fill', self::slot($attributes, 'bgColor', $fill), $statement, family: 'C4'),
            'stroke' => MermaidColor::parse('stroke', self::slot($attributes, 'borderColor', $border), $statement, family: 'C4'),
            'text' => MermaidColor::parse('color', self::slot($attributes, 'fontColor', $text), $statement, family: 'C4'),
        ];
    }

    /** Pinned `typeC4Shape`: `SystemDb_Ext` is `external_system_db`, and so on. */
    public static function type(string $macro): string
    {
        $role = \array_find(['Person', 'System', 'Container', 'Component'], static fn (string $role): bool => \str_starts_with($macro, $role)) ?? 'Component';
        $variant = match (true) {
            \str_contains($macro, 'Db') => '_db',
            \str_contains($macro, 'Queue') => '_queue',
            default => '',
        };

        return (\str_ends_with($macro, '_Ext') ? 'external_' : '').\strtolower($role).$variant;
    }

    /** @param array<string, string> $attributes */
    public static function slot(array $attributes, string $key, string $fallback): string
    {
        $value = $attributes[$key] ?? '';

        return '' === $value ? $fallback : $value;
    }

    public static function role(string $macro): string
    {
        $name = \str_replace('_Ext', replace: '', subject: $macro);
        $base = match (true) {
            \str_starts_with($name, 'Person') => 'Person',
            \str_starts_with($name, 'System') => 'Software System',
            \str_starts_with($name, 'Container') => 'Container',
            default => 'Component',
        };
        $variant = match (true) {
            \str_ends_with($name, 'Db') => ' / Database',
            \str_ends_with($name, 'Queue') => ' / Queue',
            default => '',
        };

        return (\str_ends_with($macro, '_Ext') ? 'External ' : '').$base.$variant;
    }

    /**
     * The card's name, drawn bold as pinned, and the role and description beneath it.
     *
     * @param array<string, string> $attributes
     * @return array{string, string}
     */
    public static function parts(string $macro, array $attributes): array
    {
        $technology = $attributes['techn'] ?? '';
        $role = '['.self::role($macro).('' === $technology ? '' : ': '.$technology).']';
        $description = $attributes['descr'] ?? '';

        return [MermaidText::decode($attributes['label'] ?? ''), MermaidText::decode($role.('' === $description ? '' : "\n".$description))];
    }

    /** @param array<string, string> $attributes */
    public static function groupLabel(array $attributes): string
    {
        return MermaidText::decode(($attributes['label'] ?? '')."\n[".($attributes['type'] ?? 'System').']');
    }
}
