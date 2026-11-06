<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * What one window changed, applied over what another wrote since: the file
 * as it is now ($disk), with this window's removals taken out and its
 * additions and changes put in — each against what this window loaded.
 *
 * Two windows on one project each load the state once and write it whole
 * on quit; written whole, the second to quit put back what it loaded and
 * took away everything the first had added.
 */
final class ThreeWay
{
    /**
     * @template K of array-key
     * @template V
     *
     * @param array<K, V> $disk
     * @param array<K, V> $loaded
     * @param array<K, V> $current
     *
     * @return array<K, V>
     */
    public static function merge(array $disk, array $loaded, array $current): array
    {
        foreach (\array_diff_key($loaded, $current) as $key => $_) {
            unset($disk[$key]);
        }
        foreach ($current as $key => $value) {
            if (\array_key_exists($key, $loaded) && $loaded[$key] === $value) {
                continue; // as this window found it: whatever the disk says now stands
            }
            $disk[$key] = $value;
        }

        return $disk;
    }
}
