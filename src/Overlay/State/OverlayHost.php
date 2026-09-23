<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Overlay\Region\ModalBox;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\PickerLayout;
use Porta\Widget\Support\ListFilter;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;

/**
 * The workspace-floating pickers: the centered list overlay (recent files,
 * hover docs, quick fixes, peek, file structure), the Search Everywhere modal
 * and the single-line mini prompt (go to line, rename symbol). Owns their
 * state, modal input handling, mouse hit-testing (screen coordinates) and the
 * per-frame {@see layout()} the presenter composites from; the editor widget
 * only delegates. While one is open, all keyboard input is swallowed.
 *
 * A SERVICE, not a member of the editor widget, because these surfaces float
 * over the whole workspace and the editor does not always exist. A full-screen
 * view detaches it, and while it was detached the pickers were dead: the
 * command palette is listed view-safe in the Git menu and painted nothing, and
 * every git verb that asks a question had to close the view first to be seen.
 * Owning the state above the editor is what makes those the same surface.
 */
#[AsIdeService]
final class OverlayHost
{
    use StylesDefault;

    /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void}|null */
    private ?array $overlay = null;
    /** last-rendered overlay box, for mouse hit-testing */
    private ?ModalBox $overlayBox = null;
    /** @var array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool}|null single-line mini prompt */
    private ?array $prompt = null;
    /** last-rendered (centered) prompt box, for mouse hit-testing */
    private ?ModalBox $promptBox = null;
    private ?SearchModalState $search = null;

    /** Which open surface's title-bar × is under the pointer, or null. */
    private ?PickerSurface $closeHovered = null;
    /** last-rendered search box, for mouse hit-testing */
    private ?ModalBox $searchBox = null;

    private readonly CursorStyle $cursorStyle;
    private readonly Translator $translator;

    /** Repaint request. A frame, not a widget invalidation — nothing below owns these boxes. */
    private readonly \Closure $invalidate;

    public function __construct(
        ?Tui $tui = null,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?Translator $translator = null,
    ) {
        $this->initStyles($styles);
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
        $this->translator = $translator ?? new Translator('en');
        $this->invalidate = static function () use ($tui): void {
            $tui?->requestRender();
        };
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
        $this->overlay = ['title' => $title, 'items' => $items, 'selected' => 0, 'hovered' => null, 'filter' => new TextField(style: $this->cursorStyle), 'onPick' => $onPick];
        ($this->invalidate)();
    }

    /** Dismiss the list overlay (e.g. on a buffer switch); the prompt is untouched. */
    public function closeList(): void
    {
        $this->overlay = null;
    }

    /**
     * Open the single-line input prompt (go to line, rename symbol). A danger
     * prompt (delete confirmation) boxes its field with the double danger
     * border ({@see \Porta\Widget\Render\FieldState::Danger}), so a
     * destructive question never reads like a rename, colour or not.
     */
    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false): void
    {
        $this->prompt = ['label' => $label, 'value' => new TextField($initial, $this->cursorStyle), 'onSubmit' => $onSubmit, 'danger' => $danger];
        ($this->invalidate)();
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
        $closeChanged = $this->hoverClose(PickerSurface::Search, $this->searchBox, $relRow, $relCol);
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

    /** @param array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool} $prompt the open prompt (non-null, narrowed by the caller) */
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

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay the open list overlay (non-null) */
    private function handleOverlayInput(string $keys, Keybindings $kb, array $overlay): void
    {
        $count = \count($this->matching($overlay));

        if ($kb->matches($keys, 'select_cancel')) {
            // Esc clears the filter before it closes the list. Typing is how
            // you get around a long list, so a typo in the filter must not
            // also cost you the list you were reading.
            if ('' !== $overlay['filter']->value()) {
                $overlay['filter']->clear();
                $this->refiltered($overlay);

                return;
            }
            $this->overlay = null;
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'delete_char_backward')) {
            if ($overlay['filter']->backspace()) {
                $this->refiltered($overlay);
            }

            return;
        }
        // Nothing below can run against an empty list: every one of them steps
        // the selection modulo $count, and a filter that matches nothing makes
        // that a division by zero. The surface stays open showing why.
        if (0 === $count) {
            $this->typeIntoFilter($overlay, $keys);

            return;
        }
        if ($kb->matches($keys, 'cursor_up')) {
            $this->reselect($overlay, ($overlay['selected'] - 1 + $count) % $count);

            return;
        }
        if ($kb->matches($keys, 'cursor_down')) {
            $this->reselect($overlay, ($overlay['selected'] + 1) % $count);

            return;
        }
        if ($kb->matches($keys, 'accept_completion')) {
            $item = $this->matching($overlay)[$overlay['selected']];
            $onPick = $overlay['onPick'];
            $this->overlay = null;
            $onPick($item['value']);
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($keys, 'recent_files')) {
            // Ctrl+E again cycles like PhpStorm
            $this->reselect($overlay, ($overlay['selected'] + 1) % $count);

            return;
        }
        $this->typeIntoFilter($overlay, $keys);
    }

    /**
     * A printable keystroke narrows the list — PhpStorm's speed search, which
     * every list popup there has and none of Porta's had.
     *
     * Round 8 gave the results PANEL a filter and said so; the overlays were
     * left handling four keys, so `Ctrl+F12` on a forty-method class was a
     * list you could only walk. {@see TextField::insert()} decides what counts
     * as printable, so a chord this method does not recognise is swallowed
     * rather than typed, exactly as it was before.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function typeIntoFilter(array $overlay, string $keys): void
    {
        if ($overlay['filter']->insert($keys)) {
            $this->refiltered($overlay);
        }
    }

    /**
     * After the filter changed: back to the top, and no hover.
     *
     * Both because the ROWS MOVED. The old selected index now names a
     * different item, and picking the one you never looked at is worse than
     * losing your place; the mouse-hover highlight is stale for exactly the
     * same reason, and it would sit on the wrong row until the pointer next
     * moves — which, mid-keystroke, may be never.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function refiltered(array $overlay): void
    {
        $overlay['hovered'] = null;
        $this->reselect($overlay, 0);
    }

    /**
     * Store the overlay back with a new selection and repaint.
     *
     * The write-back is not incidental: `$overlay` is an ARRAY, so every handler
     * above holds a copy, and one that mutates its own and forgets this line
     * changes nothing at all.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function reselect(array $overlay, int $selected): void
    {
        $overlay['selected'] = $selected;
        $this->overlay = $overlay;
        ($this->invalidate)();
    }

    /**
     * The items the filter admits.
     *
     * Every index in the overlay — `selected`, `hovered`, the mouse hit-test —
     * counts against THIS list rather than the full one. Two index spaces for
     * one visible list is how a filtered picker opens the wrong item.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     *
     * @return list<array{label: string, hint: string, value: string}>
     */
    private function matching(array $overlay): array
    {
        return ListFilter::matching($overlay['items'], $overlay['filter']->value());
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
        // Against the FILTERED list, like every other index here — the row the
        // pointer is over is the row the box drew.
        $item = null === $index ? null : $this->matching($overlay)[$index] ?? null;
        if (null !== $item) {
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
        $closeChanged = $this->hoverClose(PickerSurface::ListOverlay, $this->overlayBox, $relRow, $relCol);
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

        return $this->hoverClose(PickerSurface::Prompt, $this->promptBox, $relRow, $relCol);
    }

    /**
     * Track whether $surface's title-bar × is under the pointer, brightening
     * it like the tab strips' close glyph; returns whether that changed. One
     * shared slot suffices — the surfaces are modal, never open together.
     */
    private function hoverClose(PickerSurface $surface, ?ModalBox $box, int $relRow, int $relCol): bool
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

    /** Wheel over the list overlay: move the selection, wrapping like the arrow keys. */
    public function moveListSelection(int $delta): void
    {
        $overlay = $this->overlay;
        if (null === $overlay || [] === $this->matching($overlay)) {
            return;
        }
        $count = \count($this->matching($overlay));
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
        for ($i = 0; $i < \abs($delta); ++$i) {
            $search->selected = $this->stepHit($search, $delta > 0 ? 1 : -1);
        }
        ($this->invalidate)();
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

    /** Whether any picker surface (list, search, prompt) is open. */
    public function anyOpen(): bool
    {
        return null !== $this->topmostSurface();
    }

    /**
     * The open surface that owns the pointer and the caret: prompt over
     * search over list — the same precedence the keyboard path has
     * ({@see handleInput}: the prompt wins) and the inverse of
     * {@see layout()}'s paint order, which draws the topmost surface last.
     */
    public function topmostSurface(): ?PickerSurface
    {
        return match (true) {
            null !== $this->prompt => PickerSurface::Prompt,
            null !== $this->search => PickerSurface::Search,
            null !== $this->overlay => PickerSurface::ListOverlay,
            default => null,
        };
    }

    /**
     * Route a left-click (screen coords) to the topmost open surface: on an
     * item it picks, on the × it closes, anywhere else it dismisses. Returns
     * false only when no surface is open.
     */
    public function pickerClickAt(int $row, int $col): bool
    {
        $surface = $this->topmostSurface();
        if (null === $surface) {
            return false;
        }

        // Exhaustive over a NON-nullable subject: the null is hoisted out
        // above rather than answered by an arm, which is what lets this stay a
        // total match. Adding a PickerSurface case still breaks it here, which
        // is the whole point of matching on the enum.
        return match ($surface) {
            PickerSurface::Prompt => $this->promptClickAt($row, $col),
            PickerSurface::Search => $this->searchClickAt($row, $col),
            PickerSurface::ListOverlay => $this->clickAt($row, $col),
        };
    }

    /** Route a hover (screen coords) to the topmost open surface; returns whether the tint changed. */
    public function pickerHoverAt(int $row, int $col): bool
    {
        $surface = $this->topmostSurface();
        if (null === $surface) {
            return false;
        }

        // Exhaustive over a NON-nullable subject: the null is hoisted out
        // above rather than answered by an arm, which is what lets this stay a
        // total match. Adding a PickerSurface case still breaks it here, which
        // is the whole point of matching on the enum.
        return match ($surface) {
            PickerSurface::Prompt => $this->promptHoverAt($row, $col),
            PickerSurface::Search => $this->searchHoverAt($row, $col),
            PickerSurface::ListOverlay => $this->hoverAt($row, $col),
        };
    }

    /**
     * Wheel over the pickers: move the topmost SELECTION-bearing surface's
     * selection — search over list, and never the prompt (it has none; with
     * a prompt and a list both open the wheel still moves the list). Returns
     * whether a surface consumed the wheel.
     */
    public function moveSelection(int $delta): bool
    {
        if (null !== $this->search) {
            $this->moveSearchSelection($delta);

            return true;
        }
        if (null !== $this->overlay) {
            $this->moveListSelection($delta);

            return true;
        }

        return false;
    }

    /**
     * Lay every open surface out against the pane-union bounds, in paint
     * order (list, then search, then the prompt on top), recording each
     * SCREEN-space box for mouse hit-testing. Compositing — the span push,
     * shadow and backdrop — belongs to the presenter
     * ({@see \Porta\Overlay\Presenter\PickerModalPresenter}): the pickers float over
     * the whole workspace, not inside the editor pane's lines.
     *
     * @param array{int, int, int, int} $bounds [top, left, bottom, right)
     *
     * @return list<array{rows: list<string>, top: int, col: int, width: int}>
     */
    public function layout(array $bounds): array
    {
        $this->overlayBox = null;
        $this->searchBox = null;
        $this->promptBox = null;
        $placed = [];
        if (null !== $this->overlay) {
            // The FULL overlay, filter and all: layout narrows the rows with
            // the same rule this class indexes by, and sizes the box against
            // the unnarrowed list so it does not shrink and re-centre under
            // the reader on every keystroke.
            $placed[] = $this->place(
                PickerLayout::layoutList($bounds, $this->overlay, $this->styles, PickerSurface::ListOverlay === $this->closeHovered, $this->translator->trans('no matches')),
                $this->overlayBox,
            );
        }
        if (null !== $this->search) {
            // Only the TOPMOST surface renders the caret (the prompt wins): two
            // markers in one frame mangle ScreenWriter's cursor conversion.
            $caret = null === $this->prompt ? CaretVisibility::Shown : CaretVisibility::Hidden;
            $placed[] = $this->place(PickerLayout::layoutSearch($bounds, $this->search, $this->styles, PickerSurface::Search === $this->closeHovered, $caret), $this->searchBox);
        }
        if (null !== $this->prompt) {
            $placed[] = $this->place(PickerLayout::layoutPrompt($bounds, $this->prompt, $this->styles, PickerSurface::Prompt === $this->closeHovered), $this->promptBox);
        }

        return $placed;
    }

    /**
     * Record a laid-out surface's geometry (the hit-tests read it) and hand the
     * placement on. The box travels back from {@see PickerLayout} as data
     * instead of being written there, so the render tier stays stateless.
     *
     * @param array{rows: list<string>, top: int, col: int, width: int, box: ModalBox} $placed
     *
     * @return array{rows: list<string>, top: int, col: int, width: int}
     */
    private function place(array $placed, ?ModalBox &$slot): array
    {
        $slot = $placed['box'];

        return ['rows' => $placed['rows'], 'top' => $placed['top'], 'col' => $placed['col'], 'width' => $placed['width']];
    }
}
