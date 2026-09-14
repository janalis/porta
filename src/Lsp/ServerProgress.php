<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Symfony\Component\Tui\Tui;

/**
 * What the language server is busy doing, from `$/progress`.
 *
 * PhpStorm never leaves you guessing whether it is indexing; Porta did, because
 * `$/progress` had no handler AND `window.workDoneProgress` was never declared,
 * so a spec-compliant server would not have sent progress even if something had
 * been listening. Both halves are needed for this to report anything.
 *
 * Bounded on purpose. Tokens are created by the server and ended by the server,
 * so a server that begins work it never ends would otherwise grow this map for
 * the life of the process — and the whole IDE is one event loop.
 */
#[AsIdeService]
final class ServerProgress
{
    /**
     * Enough for every concurrent task a language server plausibly reports.
     * Past this, new work is not tracked rather than the map growing without
     * bound; the tasks already running still finish and still clear.
     */
    private const int MAX_TOKENS = 32;

    /** @var array<string, array{title: string, message: string, percentage: int|null}> live work, keyed by the server's token */
    private array $work = [];

    public function __construct(
        private readonly Tui $tui,
    ) {}

    /**
     * @param array<string, mixed> $params `$/progress` params
     */
    public function handle(array $params): void
    {
        // The spec allows a string OR an integer token; the map is keyed by
        // string either way, so the two can never collide with each other.
        $key = match (true) {
            \is_string($params['token'] ?? null) => $params['token'],
            \is_int($params['token'] ?? null) => (string) $params['token'],
            default => null,
        };
        if (null === $key) {
            return;
        }
        /** @var array<string, mixed> $value */
        $value = \is_array($params['value'] ?? null) ? $params['value'] : [];
        $kind = \is_string($value['kind'] ?? null) ? $value['kind'] : '';
        $before = $this->work;

        match ($kind) {
            'begin' => $this->begin($key, $value),
            'report' => $this->update($key, $value),
            'end' => $this->clear($key),
            default => null,
        };

        // Only ask for a frame when something actually changed: a server can
        // report percentages faster than the screen refreshes.
        if ($before !== $this->work) {
            $this->tui->requestRender();
        }
    }

    /**
     * The one-line label for the status bar, or null when the server is idle.
     *
     * One line for possibly several tasks: the status bar has one slot, and the
     * newest task is the one the user just caused.
     */
    public function label(): ?string
    {
        if ([] === $this->work) {
            return null;
        }

        // NOT end(): that moves the array's internal pointer, which mutates
        // the property every time the status bar is painted.
        $current = $this->work[\array_key_last($this->work)];
        $label = $current['title'];
        if ('' !== $current['message']) {
            $label .= ': '.$current['message'];
        }
        if (null !== $current['percentage']) {
            $label .= ' '.$current['percentage'].'%';
        }

        return $label;
    }

    /** Whether anything is running — the cheap check for a render path. */
    public function isBusy(): bool
    {
        return [] !== $this->work;
    }

    /** @param array<string, mixed> $value */
    private function begin(string $key, array $value): void
    {
        // Already tracking as much as we will: drop the NEW task rather than
        // evict a running one, whose end would then never clear anything.
        if (($this->work[$key] ?? null) === null && \count($this->work) >= self::MAX_TOKENS) {
            return;
        }

        $this->work[$key] = [
            'title' => \is_string($value['title'] ?? null) ? $value['title'] : '',
            'message' => \is_string($value['message'] ?? null) ? $value['message'] : '',
            'percentage' => \is_int($value['percentage'] ?? null) ? $value['percentage'] : null,
        ];
    }

    /**
     * A `report` carries only what changed, so an absent field keeps its
     * previous value rather than blanking the line mid-task.
     *
     * @param array<string, mixed> $value
     */
    private function update(string $key, array $value): void
    {
        $current = $this->work[$key] ?? null;
        // A report for work that never began (or has already ended) is not
        // ours to invent a title for.
        if (null === $current) {
            return;
        }

        $this->work[$key] = [
            'title' => $current['title'],
            'message' => \is_string($value['message'] ?? null) ? $value['message'] : $current['message'],
            'percentage' => \is_int($value['percentage'] ?? null) ? $value['percentage'] : $current['percentage'],
        ];
    }

    private function clear(string $key): void
    {
        unset($this->work[$key]);
    }
}
