<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

use Porta\Overlay\ModalBox;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\ModalShadow;
use Porta\Widget\Render\OverlayCompositor;
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
    /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, onPick: callable(string): void}|null */
    private ?array $overlay = null;
    /** last-rendered overlay box, for mouse hit-testing */
    private ?ModalBox $overlayBox = null;
    /** @var array{label: string, value: TextField, onSubmit: callable(string): void}|null single-line mini prompt */
    private ?array $prompt = null;
    /** last-rendered (centered) prompt box, for mouse hit-testing */
    private ?ModalBox $promptBox = null;
    private ?SearchModalState $search = null;

    /** Which open surface's title-bar × is under the pointer ('list'|'search'|'prompt'), or null. */
    private ?string $closeHovered = null;
    /** last-rendered search box, for mouse hit-testing */
    private ?ModalBox $searchBox = null;

    private readonly Styles $styles;

    /**
     * @param \Closure(): void $invalidate repaint request back into the widget
     */
    private readonly CursorStyle $cursorStyle;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
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
        $this->overlay = ['title' => $title, 'items' => $items, 'selected' => 0, 'hovered' => null, 'onPick' => $onPick];
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

    public function isPromptOpen(): bool
    {
        return null !== $this->prompt;
    }

    /**
     * A left-click while the mini-prompt is open: a click on the prompt box
     * (its row-0 span) is swallowed so the caret stays put, a click anywhere
     * else dismisses it — the outside-click-dismiss every sibling overlay in
     * this host already has, and its keyboard modality already implies. Always
     * consumed so the click never reaches the panes underneath; returns false
     * only when no prompt is open.
     */
    public function promptClickAt(int $relRow, int $relCol): bool
    {
        if (null === $this->prompt) {
            return false;
        }
        $box = $this->promptBox;
        if (null === $box || $box->closeHit($relRow, $relCol) || !$box->contains($relRow, $relCol)) {
            $this->prompt = null;
            ($this->invalidate)();
        }

        return true;
    }

    /**
     * Open the Search Everywhere modal with the query/pick/close callbacks. The
     * controller pushes rows via {@see setSearchRows}; keys are fed through the
     * global path to {@see handleSearchInput} (not {@see handleInput}).
     *
     * @param \Closure(string): void    $onQuery
     * @param \Closure(SearchHit): void $onPick
     * @param \Closure(): void          $onClose
     * @param string                    $emptyMessage filler row for a no-hit query (pre-translated)
     */
    public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose, string $emptyMessage = ''): void
    {
        $this->search = new SearchModalState($title, new TextField(style: $this->cursorStyle), $onQuery, $onPick, $onClose, $emptyMessage);
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
        $search->hovered = null; // the rows moved under a still pointer
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
        if (null !== $this->searchBox && $this->searchBox->closeHit($relRow, $relCol)) {
            ($search->onClose)();

            return true;
        }
        $index = $this->searchBox?->itemAt($relRow, $relCol);
        $hit = null === $index ? null : $search->rows[$index]->hit ?? null;
        if (null !== $hit) {
            ($search->onPick)($hit);

            return true;
        }
        ($search->onClose)();

        return true;
    }

    /** Tint the search hit row under the pointer; returns whether it changed. Never moves the selection. */
    public function searchHoverAt(int $relRow, int $relCol): bool
    {
        $search = $this->search;
        if (null === $search) {
            return false;
        }
        $closeChanged = $this->hoverClose('search', $this->searchBox, $relRow, $relCol);
        $index = $this->searchBox?->itemAt($relRow, $relCol);
        $hovered = null !== $index && !$search->rows[$index]->isHeader() ? $index : null;
        if ($hovered === $search->hovered) {
            return $closeChanged;
        }
        $search->hovered = $hovered;
        ($this->invalidate)();

        return true;
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

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, onPick: callable(string): void} $overlay the open list overlay (non-null) */
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
        if (null !== $this->overlayBox && $this->overlayBox->closeHit($relRow, $relCol)) {
            $this->overlay = null;
            ($this->invalidate)();

            return true;
        }
        $index = $this->overlayBox?->itemAt($relRow, $relCol);
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

        // Copy while the null-guard's narrowing still holds — hoverClose()
        // runs the invalidate closure, after which the analyser must assume
        // the property may have changed.
        $overlay = $this->overlay;
        $closeChanged = $this->hoverClose('list', $this->overlayBox, $relRow, $relCol);
        $index = $this->overlayBox?->itemAt($relRow, $relCol);
        if ($index === ($overlay['hovered'] ?? null)) {
            return $closeChanged;
        }
        $overlay['hovered'] = $index;
        $this->overlay = $overlay;
        ($this->invalidate)();

        return true;
    }

    /** Hover for the mini prompt: only its × restyles; returns whether it changed. */
    public function promptHoverAt(int $relRow, int $relCol): bool
    {
        if (null === $this->prompt) {
            return false;
        }

        return $this->hoverClose('prompt', $this->promptBox, $relRow, $relCol);
    }

    /**
     * Track whether $surface's title-bar × is under the pointer, brightening
     * it like the tab strips' close glyph; returns whether that changed. One
     * shared slot suffices — the surfaces are modal, never open together.
     */
    private function hoverClose(string $surface, ?ModalBox $box, int $relRow, int $relCol): bool
    {
        $hovered = null !== $box && $box->closeHit($relRow, $relCol) ? $surface : null;
        if ($hovered === $this->closeHovered) {
            return false;
        }
        $this->closeHovered = $hovered;
        ($this->invalidate)();

        return true;
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
    /** Wheel over the list overlay: move the selection, wrapping like the arrow keys. */
    public function moveListSelection(int $delta): void
    {
        $overlay = $this->overlay;
        if (null === $overlay || [] === $overlay['items']) {
            return;
        }
        $count = \count($overlay['items']);
        $overlay['selected'] = ((($overlay['selected'] + $delta) % $count) + $count) % $count;
        $this->overlay = $overlay;
        ($this->invalidate)();
    }

    /** Wheel over the search modal: move the selection, skipping header rows. */
    public function moveSearchSelection(int $delta): void
    {
        $search = $this->search;
        if (null === $search || [] === $search->rows) {
            return;
        }
        for ($i = 0; $i < abs($delta); ++$i) {
            $search->selected = $this->stepHit($search, $delta > 0 ? 1 : -1);
        }
        ($this->invalidate)();
    }

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
        $this->promptBox = null;
        if (null !== $this->overlay) {
            $this->overlayBox = EditorRenderer::listOverlay($lines, $columns, $rows, $this->overlay, $this->styles, closeHovered: 'list' === $this->closeHovered);
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
                // Render the query through the shared field primitive so the
                // caret flows the same path as every other input; the renderer
                // stays decoupled, receiving a plain string.
                $search->query->render($focused),
                $rowModels,
                $search->selected,
                $search->hovered,
                $this->styles,
                $search->emptyMessage,
                closeHovered: 'search' === $this->closeHovered,
            );
        }

        if (null !== $this->prompt) {
            $text = ' '.$this->prompt['label'].': '.$this->prompt['value']->value();
            $innerWidth = min($columns - 8, max(30, mb_strwidth($text) + 4));
            $rendered = ' '.$this->prompt['value']->render(cursor: $focused);
            // The prompt is a framed 3-row modal like its siblings: the frame's
            // title bar carries the label, the Esc hint and the ×; the content
            // row holds the field through scrollLineToCaret, which keeps the
            // value's caret marker on screen instead of clipping the hardware
            // cursor off it. Centered like the list/search overlays.
            $field = $this->styles->merge(Role::HeaderBar, Role::Bright)->apply(AnsiText::padRight(AnsiText::scrollLineToCaret($rendered, $innerWidth), $innerWidth));
            $framed = [
                ModalFrame::titleBar($this->prompt['label'], $innerWidth + ModalFrame::CHROME_COLS, $this->styles, closeHovered: 'prompt' === $this->closeHovered),
                ...ModalFrame::wrap([$field], $innerWidth, $this->styles),
            ];
            $col = max(0, intdiv($columns - $innerWidth - ModalFrame::CHROME_COLS, num2: 2));
            [$lines, $startRow] = OverlayCompositor::placeCentered($lines, $framed, $col, $innerWidth + ModalFrame::CHROME_COLS, $columns, $rows);
            $lines = ModalShadow::blit($lines, $startRow, $col, $innerWidth + ModalFrame::CHROME_COLS, 1 + ModalFrame::CHROME_ROWS, $columns, $this->styles);
            $this->promptBox = new ModalBox($startRow, $col, $innerWidth + ModalFrame::CHROME_COLS, 1 + ModalFrame::CHROME_ROWS, headerRows: ModalFrame::CHROME_ROWS_TOP, windowStart: 0, count: 1);
        }

        return $lines;
    }
}
