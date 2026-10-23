<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * One extension point's registration buffer: rows appended during a plugin's
 * `activate()` and drained wholesale when the loader commits. Every
 * extension point whose register/drain pair is shaped the same way —
 * validate, append a `'plugin'`-stamped row, drain with a bare foreach — has
 * one, held together on {@see PluginContributions}. The four plain arrays left
 * on {@see PluginApi} each say at their declaration why they are not one.
 *
 * @template T of array<string, mixed>
 */
final class PluginBuffer
{
    /** @var list<T> */
    private array $rows = [];

    /** @param T $row */
    public function add(array $row): void
    {
        $this->rows[] = $row;
    }

    /** @return list<T> */
    public function all(): array
    {
        return $this->rows;
    }
}
