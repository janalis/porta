<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

/**
 * One extension point's registration buffer: rows appended during a plugin's
 * `activate()` and drained wholesale when the loader commits. Shared by the
 * four extension points whose register/buffered/drain triples are shaped
 * identically (language servers, installers, highlighters, token spans) —
 * validate, append a `'plugin'`-stamped row, drain with a bare foreach. The
 * other four buffers in {@see PluginApi} stay plain arrays on purpose; each
 * says why at its declaration.
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
