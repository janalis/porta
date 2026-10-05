<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * A one-row trail of crumbs on the {@see Role::Toolbar} strip — the editor's
 * breadcrumbs and the settings page header — with an optional right-hand
 * suffix (the preview toggles, a count).
 *
 * Each crumb is a pill, ` label `, shaded as a bar item by
 * {@see BarChromeStyle}: the last crumb is where you are, so it is lit the
 * way the active tab is, and the one under the pointer takes the tab hover
 * shade with its label underlined, because a crumb is a link. Pills are joined by a bare
 * dim `›`, so at rest the trail reads ` a › b › c` exactly as the plain one
 * did. The pill's padding is where the tint shows, so it is part of the hit
 * zone, as a tab's whole shape is.
 *
 * Laid out from the right: when the trail does not fit, the crumbs nearest
 * the root drop behind one `… ›`, never the current one, and a current crumb
 * that is alone and still too wide is cut in the middle so both of its ends
 * stay.
 */
final class CrumbBar
{
    /** The strip's shade, whose half the row under the editor's bar holds. */
    public const Role SHADE = Role::Toolbar;

    /** ` … ›`: the lead pill and the separator after it. */
    private const int LEAD_WIDTH = 4;

    /**
     * The trail as one row exactly $columns wide, with each drawn crumb's
     * columns (keyed by its index in $crumbs) and a card for whatever the
     * row hid or cut.
     *
     * @param list<array{label: string, emphasis?: bool}> $crumbs root first, the current crumb last; none leaves the strip bare
     *
     * @return array{line: string, zones: array<int, array{start: int, width: int}>, tips: list<array{start: int, width: int, key: string, text: string}>}
     */
    public static function render(array $crumbs, int $columns, Styles $styles, ?int $hoverCol, string $suffix = ''): array
    {
        // A suffix wider than the row is cut to it: a row wider than its
        // pane throws out of the render loop and takes the session with it.
        if (AnsiUtils::visibleWidth($suffix) > $columns) {
            $suffix = AnsiText::truncate($suffix, \max(0, $columns));
        }
        $room = \max(0, $columns - AnsiUtils::visibleWidth($suffix));
        $labels = \array_column($crumbs, 'label');
        [$first, $labelRoom] = [] === $labels ? [0, 0] : self::window($labels, $room - 2);
        $separator = $styles->of(Role::Gutter)->apply(Glyphs::BREADCRUMB_SEPARATOR);
        $line = '';
        $col = 0;
        $zones = [];
        $tips = [];
        if ($first > 0) {
            // The crumbs that did not fit, said once: the trail is cut at its
            // root end, never at the crumb you are on.
            $line .= $styles->of(Role::Muted)->apply(' '.Glyphs::ELLIPSIS.' ').$separator;
            $tips[] = [
                'start' => 0,
                'width' => 3,
                'key' => 'lead',
                'text' => \implode(' '.Glyphs::BREADCRUMB_SEPARATOR.' ', \array_slice($labels, offset: 0, length: $first)),
            ];
            $col = self::LEAD_WIDTH;
        }
        $last = \count($crumbs) - 1;
        foreach (\array_slice($crumbs, $first, preserve_keys: true) as $i => $crumb) {
            if ($i > $first) {
                $line .= $separator;
                ++$col;
            }
            $label = $i === $last ? AnsiText::elideMiddle($crumb['label'], $labelRoom) : $crumb['label'];
            $width = AnsiUtils::visibleWidth($label) + 2;
            $zones[$i] = ['start' => $col, 'width' => $width];
            if ($label !== $crumb['label']) {
                $tips[] = ['start' => $col, 'width' => $width, 'key' => 'current', 'text' => $crumb['label']];
            }
            $hovered = null !== $hoverCol && $hoverCol >= $col && $hoverCol < ($col + $width);
            $line .= self::pill($label, $styles, $i === $last, $hovered, $crumb['emphasis'] ?? false);
            $col += $width;
        }

        return [
            'line' => $styles->of(self::SHADE)->apply(AnsiText::truncate($line, $room, pad: true).$suffix),
            'zones' => $zones,
            'tips' => $tips,
        ];
    }

    /**
     * The columns the whole trail takes when nothing is cut: every pill and
     * the separators between them.
     *
     * @param list<array{label: string, emphasis?: bool}> $crumbs
     */
    public static function naturalWidth(array $crumbs): int
    {
        $width = 0;
        foreach ($crumbs as $crumb) {
            $width += AnsiUtils::visibleWidth($crumb['label']) + 3;
        }

        return \max(0, $width - 1);
    }

    /**
     * The index of the drawn crumb whose pill covers column $col, or null.
     *
     * @param array<int, array{start: int, width: int}> $zones
     */
    public static function at(array $zones, int $col): ?int
    {
        foreach ($zones as $index => $zone) {
            if ($col >= $zone['start'] && $col < ($zone['start'] + $zone['width'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One crumb's pill. A crumb marked for emphasis (the editor's file, the
     * border between the path and the code) keeps a bright foreground at
     * rest; lit or hovered it is shaded like any other.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- current, hovered and emphasis are the crumb's state, not a second responsibility.
    private static function pill(string $label, Styles $styles, bool $current, bool $hovered, bool $emphasis): string
    {
        $state = TabState::of($current, $hovered);
        $style = TabState::Plain === $state && $emphasis
            ? $styles->merge(Role::Toolbar, Role::Emphasis)
            : BarChromeStyle::of($styles, $state, Role::Toolbar);
        if ($hovered) {
            // The current crumb is a link too: a click on it jumps there.
            $label = new Style(underline: true)->apply($label);
        }

        return $style->apply(' '.$label.' ');
    }

    /**
     * Which crumbs fit in $room label columns, laid out from the right: the
     * first one drawn, and the room left for the last one's label. The
     * current crumb is always drawn; each earlier one joins only while it,
     * its separator and — when crumbs remain beyond it — the lead still fit.
     *
     * @param list<string> $labels
     *
     * @return array{int, int}
     */
    private static function window(array $labels, int $room): array
    {
        $first = \count($labels) - 1;
        $used = AnsiUtils::visibleWidth($labels[$first]);
        while ($first > 0) {
            $next = $used + 3 + AnsiUtils::visibleWidth($labels[$first - 1]);
            if (($next + ($first > 1 ? self::LEAD_WIDTH : 0)) > $room) {
                break;
            }
            --$first;
            $used = $next;
        }

        return [$first, \max(1, $room - ($first > 0 ? self::LEAD_WIDTH : 0))];
    }
}
