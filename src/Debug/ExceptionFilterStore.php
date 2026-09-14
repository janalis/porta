<?php

declare(strict_types=1);

namespace Porta\Debug;

/**
 * The user's exception-breakpoint filter selection. `null` means "never
 * chosen" — the adapter's `default: true` filters apply; an explicit empty
 * list means "the user turned everything off". Filters are only meaningful
 * against what a live adapter advertises, so every read intersects with the
 * advertised set.
 */
final class ExceptionFilterStore
{
    /** @var list<string>|null */
    private ?array $selection = null;

    /** @return list<string>|null */
    public function selection(): ?array
    {
        return $this->selection;
    }

    /** @param list<string>|null $ids */
    public function setSelection(?array $ids): void
    {
        $this->selection = $ids;
    }

    /**
     * The enabled filter ids for an adapter's advertised set: the stored
     * selection intersected with it, or the adapter defaults when nothing
     * was ever chosen.
     *
     * @param list<array{filter: string, label: string, default: bool}> $advertised
     *
     * @return list<string>
     */
    public function enabledFor(array $advertised): array
    {
        if (null === $this->selection) {
            return \array_values(\array_map(
                static fn (array $filter): string => $filter['filter'],
                \array_filter($advertised, static fn (array $filter): bool => $filter['default']),
            ));
        }
        $ids = \array_column($advertised, 'filter');

        return \array_values(\array_intersect($this->selection, $ids));
    }

    /**
     * Flip one filter, materializing the selection from the defaults first
     * so the initial toggle behaves as the checkbox the user sees.
     *
     * @param list<array{filter: string, label: string, default: bool}> $advertised
     */
    public function toggle(string $id, array $advertised): void
    {
        $enabled = $this->enabledFor($advertised);
        $this->selection = \in_array($id, $enabled, strict: true)
            ? \array_values(\array_diff($enabled, [$id]))
            : [...$enabled, $id];
    }
}
