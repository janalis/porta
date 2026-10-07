<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * The speed-search sieve over a picker's rows.
 *
 * A free function in a class because BOTH tiers need it and neither may call
 * the other: {@see \Porta\Overlay\State\OverlayHost} counts selection, hover
 * and mouse hits against the narrowed list, while
 * {@see \Porta\Widget\Render\PickerLayout} draws it — and the architecture gate
 * refuses the render tier reaching back into state. Filtering in the state tier
 * and handing the result down would work for the ROWS and lose the WIDTH: the
 * box is centered and sized from its content, so measuring the narrowed list
 * makes the popup shrink and re-centre on every keystroke, hopping out from
 * under the eye that is reading it. Layout needs both lists, so it needs the
 * rule rather than the result.
 */
final class ListFilter
{
    /**
     * The items whose label or hint contains $needle, case-insensitively.
     *
     * Label AND hint, which is the same pair {@see \Porta\Widget\ResultsView}
     * matches on for the results panel (a result's text and its path) — and it
     * pays off immediately: the hint is the symbol KIND in File Structure, so
     * typing `method` narrows a class to its methods.
     *
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}> $items
     *
     * @return list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>
     */
    public static function matching(array $items, string $needle): array
    {
        $needle = \mb_strtolower($needle);
        if ('' === $needle) {
            return $items;
        }

        return \array_values(\array_filter(
            $items,
            static fn (array $item): bool => \str_contains(\mb_strtolower($item['label']), $needle) || \str_contains(\mb_strtolower($item['hint']), $needle),
        ));
    }
}
