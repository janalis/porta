<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Overlay\State\OverlayHost;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;

/**
 * The rig the {@see OverlayHost} suites share: a host over a real Tui (whose
 * render flag is the repaint signal), the keymaps the pickers read, the
 * presenter's compositing replayed onto a canvas, and stock search rows.
 */
trait DrivesOverlayHost
{
    private Tui $tui;

    private function host(): OverlayHost
    {
        // A real Tui, because the host asks IT for a frame now rather than
        // calling back into a widget: these boxes float over the workspace and
        // there is no widget below them to invalidate.
        $this->tui = TestTui::create(100, 24);
        $this->clearRepaint();

        return new OverlayHost($this->tui);
    }

    private function clearRepaint(): void
    {
        Probe::set($this->tui, 'renderRequested', false);
    }

    private function repainted(): bool
    {
        return true === Probe::get($this->tui, 'renderRequested');
    }

    private function keybindings(): Keybindings
    {
        return new Keybindings([
            'select_cancel' => [Key::ESCAPE],
            'accept_completion' => [Key::ENTER],
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'recent_files' => ['ctrl+e'],
            'delete_char_backward' => [Key::BACKSPACE],
        ]);
    }

    /**
     * Lay the host out against a [0, 0, $rows, $columns] union and composite
     * the returned boxes onto a blank canvas — the presenter's job, replayed
     * here so the render/geometry assertions keep exercising the real rows.
     *
     * @return list<string>
     */
    private function canvas(OverlayHost $host, int $columns, int $rows): array
    {
        $lines = \array_fill(0, \max(0, $rows), \str_repeat(' ', \max(0, $columns)));
        foreach ($host->layout([0, 0, $rows, $columns]) as $box) {
            foreach ($box['rows'] as $i => $rowText) {
                $row = $box['top'] + $i;
                if ($row >= $rows) {
                    break;
                }
                $lines[$row] = OverlayCompositor::into($lines[$row], $box['col'], $box['width'], $columns, $rowText);
            }
        }

        return $lines;
    }

    private function hit(SearchCategory $category, string $label, \Closure $activate): SearchHit
    {
        return new SearchHit($category, $label, '', $activate);
    }

    /** @return list<SearchRow> Files: a, b · Actions: c — headers around hits. */
    private function searchRows(\Closure $onActivate): array
    {
        return [
            SearchRow::header('Files'),
            SearchRow::hit($this->hit(SearchCategory::Files, 'a', static function () use ($onActivate): void {
                $onActivate('a');
            })),
            SearchRow::hit($this->hit(SearchCategory::Files, 'b', static function () use ($onActivate): void {
                $onActivate('b');
            })),
            SearchRow::header('Actions'),
            SearchRow::hit($this->hit(SearchCategory::Actions, 'c', static function () use ($onActivate): void {
                $onActivate('c');
            })),
        ];
    }

    /** @return list<SearchRow> $count consecutive hits, no headers — every step lands on a hit immediately. */
    private function consecutiveHits(int $count = 4): array
    {
        $labels = ['a', 'b', 'c', 'd', 'e', 'f'];

        return \array_map(
            fn (string $label): SearchRow => SearchRow::hit($this->hit(SearchCategory::Files, $label, static fn (): null => null)),
            \array_slice($labels, offset: 0, length: $count),
        );
    }

    /** The full editing keys every picker field and prompt share, bound as the IDE binds them. */
    private function editingKeys(): Keybindings
    {
        return new Keybindings([
            'select_cancel' => [Key::ESCAPE],
            'accept_completion' => [Key::ENTER],
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'delete_char_backward' => [Key::BACKSPACE],
        ]);
    }
}
