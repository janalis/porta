<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * A row's named click targets: half-open column spans recorded at render
 * time ({@see clear} + {@see add}) and resolved on click/hover ({@see at}).
 * The shared form of the zone lists the toolbar-ish rows hand-rolled —
 * a value object each widget news up, not a service. First match wins.
 * Zone lists carrying non-string payloads or positional lookups (tab
 * strips, breadcrumbs, menu bar) are deliberately not this: laundering
 * their payloads through names would cost more than the loop they keep.
 */
final class ClickZones
{
    /** @var list<array{name: string, start: int, end: int}> */
    private array $zones = [];

    /** Drop every zone; the owning row re-records them each render. */
    public function clear(): void
    {
        $this->zones = [];
    }

    /** Record a zone covering display columns [$start, $end). */
    public function add(string $name, int $start, int $end): void
    {
        $this->zones[] = ['name' => $name, 'start' => $start, 'end' => $end];
    }

    /** The first recorded zone covering $col, or null. */
    public function at(int $col): ?string
    {
        foreach ($this->zones as $zone) {
            if ($col >= $zone['start'] && $col < $zone['end']) {
                return $zone['name'];
            }
        }

        return null;
    }
}
