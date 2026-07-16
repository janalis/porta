<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ColorPalette;
use Porta\Ui\CursorStyle;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\TextField;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\PaneFocus;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * App-modal chrome composited over the editor pane: the centered list overlay
 * (recent files, hover docs, quick fixes, peek, file structure) and the
 * single-line mini prompt (go to line, rename symbol). Owns their state,
 * modal input handling, mouse hit-testing and render-time composition; the
 * editor widget only delegates. While either is open, all keyboard input is
 * swallowed.
 */
final class OverlayHost
{
    /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable(string): void}|null */
    private ?array $overlay = null;
    /** @var array{col: int, width: int, startRow: int, windowStart: int, count: int}|null last-rendered overlay box, for mouse hit-testing */
    private ?array $overlayBox = null;
    /** @var array{label: string, value: TextField, onSubmit: callable(string): void}|null single-line mini prompt */
    private ?array $prompt = null;
    private ?SearchModalState $search = null;
    /** @var array{col: int, width: int, startRow: int, windowStart: int, count: int}|null last-rendered search box, for mouse hit-testing */
    private ?array $searchBox = null;

    private readonly Palette $palette;

    /**
     * @param \Closure(): void $invalidate repaint request back into the widget
     */
    private readonly CursorStyle $cursorStyle;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Palette $palette = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
    }

    /**
     * Open the modal list overlay (no-op for an empty list).
     *
     * @param list<array{label: string, hint: string, value: string}> $items
     * @param callable(string): void                                  $onPick receives the picked item's value
     */
    public function openList(string $title, array $items, callable $onPick): void
    {
        if ([] === $items) {
            return;
        }
        $this->overlay = ['title' => $title, 'items' => $items, 'selected' => 0, 'onPick' => $onPick];
        ($this->invalidate)();
    }

    public function isListOpen(): bool
    {
        return null !== $this->overlay;
    }

    /** Dismiss the list overlay (e.g. on a buffer switch); the prompt is untouched. */
    public function closeList(): void
    {
        $this->overlay = null;
    }

    /** Open the single-line input prompt (go to line, rename symbol). */
    public function openPrompt(string $label, string $initial, callable $onSubmit): void
    {
        $this->prompt = ['label' => $label, 'value' => new TextField($initial, $this->cursorStyle), 'onSubmit' => $onSubmit];
        ($this->invalidate)();
    }

    /**
     * Open the Search Everywhere modal with the query/pick/close callbacks. The
     * controller pushes rows via {@see setSearchRows}; keys are fed through the
     * global path to {@see handleSearchInput} (not {@see handleInput}).
     *
     * @param \Closure(string): void    $onQuery
     * @param \Closure(SearchHit): void $onPick
     * @param \Closure(): void          $onClose
     */
    public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose): void
    {
        $this->search = new SearchModalState($title, new TextField(style: $this->cursorStyle), $onQuery, $onPick, $onClose);
        ($this->invalidate)();
    }

    public function isSearchOpen(): bool
    {
        return null !== $this->search;
    }

    /**
     * Replace the modal's rows, keeping selection on a hit row.
     *
     * @param list<SearchRow> $rows
     */
    public function setSearchRows(array $rows): void
    {
        $search = $this->search;
        if (null === $search) {
            return;
        }
        $search->rows = $rows;
        $search->selected = $this->firstHitAtOrAfter($rows, $search->selected);
        ($this->invalidate)();
    }

    public function closeSearch(): void
    {
        $this->search = null;
        ($this->invalidate)();
    }

    /**
     * Modal keyboard handling for the search box (driven by the global input
     * path): Esc closes, Up/Down move over hit rows only (headers skipped),
     * Enter picks the selected hit, everything else edits the query.
     */
    public function handleSearchInput(string $keys, Keybindings $kb): void
    {
        $search = $this->search;
        if (null === $search) {
            return;
        }
        if ($kb->matches($keys, 'select_cancel')) {
            ($search->onClose)();

            return;
        }
        if ($kb->matches($keys, 'accept_completion')) { // Enter
            $hit = $search->rows[$search->selected]->hit ?? null;
            if (null !== $hit) {
                ($search->onPick)($hit);
            }

            return;
        }
        if ($kb->matches($keys, 'cursor_up')) {
            $search->selected = $this->stepHit($search, -1);
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'cursor_down')) {
            $search->selected = $this->stepHit($search, 1);
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'delete_char_backward')) {
            if ($search->query->backspace()) {
                ($search->onQuery)($search->query->value());
            }

            return;
        }
        if ($search->query->insert($keys)) {
            ($search->onQuery)($search->query->value());
        }
    }

    /**
     * A left-click while the search modal is open: on a hit row it picks it,
     * anywhere else it closes. Returns false when the modal is not open.
     */
    public function searchClickAt(int $relRow, int $relCol): bool
    {
        $search = $this->search;
        if (null === $search) {
            return false;
        }
        $index = $this->boxItemAt($this->searchBox, 2, $relRow, $relCol);
        $hit = null === $index ? null : $search->rows[$index]->hit ?? null;
        if (null !== $hit) {
            ($search->onPick)($hit);

            return true;
        }
        ($search->onClose)();

        return true;
    }

    /** Highlight the search hit row under the pointer; returns whether it changed. */
    public function searchHoverAt(int $relRow, int $relCol): bool
    {
        $search = $this->search;
        if (null === $search) {
            return false;
        }
        $index = $this->boxItemAt($this->searchBox, 2, $relRow, $relCol);
        if (null !== $index && !$search->rows[$index]->isHeader() && $index !== $search->selected) {
            $search->selected = $index;
            ($this->invalidate)();

            return true;
        }

        return false;
    }

    /**
     * Modal keyboard handling: consumes every key while the prompt or the
     * list overlay is open (the prompt wins when both are). Returns whether
     * the input was consumed.
     */
    public function handleInput(string $keys, Keybindings $kb): bool
    {
        if (null !== $this->prompt) {
            $this->handlePromptInput($keys, $kb, $this->prompt);

            return true;
        }
        if (null !== $this->overlay) {
            $this->handleOverlayInput($keys, $kb, $this->overlay);

            return true;
        }

        return false;
    }

    /** @param array{label: string, value: TextField, onSubmit: callable(string): void} $prompt the open prompt (non-null, narrowed by the caller) */
    private function handlePromptInput(string $keys, Keybindings $kb, array $prompt): void
    {
        if ($kb->matches($keys, 'select_cancel')) {
            $this->prompt = null;
            ($this->invalidate)();

            return;
        }

        if ($kb->matches($keys, 'accept_completion')) { // Enter
            $this->prompt = null;
            ($this->invalidate)();
            $prompt['onSubmit']($prompt['value']->value());

            return;
        }

        if ($kb->matches($keys, 'delete_char_backward')) {
            if ($prompt['value']->backspace()) {
                ($this->invalidate)();
            }

            return;
        }

        if ($prompt['value']->insert($keys)) {
            ($this->invalidate)();
        }
    }

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable(string): void} $overlay the open list overlay (non-null) */
    private function handleOverlayInput(string $keys, Keybindings $kb, array $overlay): void
    {
        $count = \count($overlay['items']);

        if ($kb->matches($keys, 'select_cancel')) {
            $this->overlay = null;
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'cursor_up')) {
            $overlay['selected'] = ($overlay['selected'] - 1 + $count) % $count;
            $this->overlay = $overlay;
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'cursor_down')) {
            $overlay['selected'] = ($overlay['selected'] + 1) % $count;
            $this->overlay = $overlay;
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'accept_completion')) {
            $item = $overlay['items'][$overlay['selected']];
            $onPick = $overlay['onPick'];
            $this->overlay = null;
            $onPick($item['value']);
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'recent_files')) {
            // Ctrl+E again cycles like PhpStorm
            $overlay['selected'] = ($overlay['selected'] + 1) % $count;
            $this->overlay = $overlay;
            ($this->invalidate)();
        }
    }

    /**
     * A left-click while the list overlay is open (editor-pane-relative
     * coords): on an item it picks it, anywhere else it dismisses. Always
     * consumed; returns false when the overlay is not open.
     */
    public function clickAt(int $relRow, int $relCol): bool
    {
        if (null === $this->overlay) {
            return false;
        }

        $overlay = $this->overlay;
        $index = $this->boxItemAt($this->overlayBox, 1, $relRow, $relCol);
        if (null !== $index) {
            $item = $overlay['items'][$index];
            $onPick = $overlay['onPick'];
            $this->overlay = null;
            ($this->invalidate)();
            $onPick($item['value']);

            return true;
        }
        $this->overlay = null;
        ($this->invalidate)();

        return true;
    }

    /**
     * Highlight the overlay row under the pointer; returns whether the
     * highlighted row actually changed (so the caller can skip a redundant
     * re-render on a same-row hover).
     */
    public function hoverAt(int $relRow, int $relCol): bool
    {
        if (null === $this->overlay) {
            return false;
        }

        $overlay = $this->overlay;
        $index = $this->boxItemAt($this->overlayBox, 1, $relRow, $relCol);
        if (null !== $index && $index !== $overlay['selected']) {
            $overlay['selected'] = $index;
            $this->overlay = $overlay;
            ($this->invalidate)();

            return true;
        }

        return false;
    }

    /**
     * Absolute item index at the given relative cell for a rendered box, or null
     * when outside its item rows. $headerRows is the chrome above the list: 1 for
     * the list overlay (title), 2 for the search modal (title + query).
     *
     * @param array{col: int, width: int, startRow: int, windowStart: int, count: int}|null $box
     */
    private function boxItemAt(?array $box, int $headerRows, int $relRow, int $relCol): ?int
    {
        if (null === $box || $relCol < $box['col'] || $relCol >= ($box['col'] + $box['width'])) {
            return null;
        }
        $i = $relRow - ($box['startRow'] + $headerRows);
        if ($i < 0 || $i >= $box['count']) {
            return null;
        }

        return $box['windowStart'] + $i;
    }

    /**
     * The first hit-row index at or after $from, else the first hit, else $from.
     *
     * @param list<SearchRow> $rows
     */
    private function firstHitAtOrAfter(array $rows, int $from): int
    {
        if (($rows[$from] ?? null) !== null && !$rows[$from]->isHeader()) {
            return $from;
        }
        foreach ($rows as $i => $row) {
            if (!$row->isHeader()) {
                return $i;
            }
        }

        return $from;
    }

    /** Move the selection $dir hit rows, wrapping and skipping headers. */
    private function stepHit(SearchModalState $search, int $dir): int
    {
        $count = \count($search->rows);
        for ($step = 1; $step <= $count; ++$step) {
            $i = ((($search->selected + ($dir * $step)) % $count) + $count) % $count;
            if (!$search->rows[$i]->isHeader()) {
                return $i;
            }
        }

        return $search->selected;
    }

    /**
     * Composite the open overlay and/or prompt over the rendered editor
     * lines, recording the overlay's box for mouse hit-testing.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function composite(array $lines, int $columns, int $rows, CaretVisibility $focused): array
    {
        $this->overlayBox = null;
        if (null !== $this->overlay) {
            $this->overlayBox = EditorRenderer::listOverlay($lines, $columns, $rows, $this->overlay, $this->palette);
        }

        $this->searchBox = null;
        $search = $this->search;
        if (null !== $search) {
            $rowModels = array_map(static fn (SearchRow $r): array => [
                'header' => $r->header?->title(),
                'label' => $r->hit->label ?? '',
                'detail' => $r->hit->detail ?? '',
            ], $search->rows);
            $this->searchBox = EditorRenderer::searchOverlay(
                $lines,
                $columns,
                $rows,
                $search->title,
                $search->query->value(),
                CaretVisibility::Shown === $focused ? PaneFocus::Focused : PaneFocus::Blurred,
                $rowModels,
                $search->selected,
                $this->palette,
                $this->cursorStyle->marker(),
            );
        }

        if (null !== $this->prompt) {
            $text = ' '.$this->prompt['label'].': '.$this->prompt['value']->value();
            $width = min($columns, max(30, mb_strwidth($text) + 4));
            $rendered = ' '.$this->prompt['label'].': '.$this->prompt['value']->render(cursor: $focused);
            $box = $this->palette->of(Role::HeaderBar).$this->palette->of(Role::Bright).AnsiUtils::truncateToWidth($rendered.' ', $width, '…', pad: true).Sgr::RESET;
            $tail = AnsiUtils::sliceByColumn($lines[0] ?? '', $width, $columns - $width);
            $lines[0] = AnsiText::padRight($box.$tail.Sgr::RESET, $columns);
        }

        /** @var list<string> $lines writing index 0 keeps the shape */
        return $lines;
    }
}
