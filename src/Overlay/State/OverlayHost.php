<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\Region\ModalBox;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchRow;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\ScreenDensity;
use Porta\Ui\TextEcho;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\PickerLayout;
use Porta\Widget\Support\ListFilter;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

    /** @var array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void}|null */
    private ?array $overlay = null;

    /** @var array{title: string, filter: TextField, value: string}|null the list a running pick closed, for a pick that reopens it */
    private ?array $picked = null;
    /** last-rendered overlay box, for mouse hit-testing */
    private ?ModalBox $overlayBox = null;
    /** @var array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool}|null single-line mini prompt */
    private ?array $prompt = null;
    /** last-rendered (centered) prompt box, for mouse hit-testing */
    private ?ModalBox $promptBox = null;
    private ?SearchModalState $search = null;

    /** Where the user moved each picker and how big they made it, its lit handle and its drag. */
    private readonly WindowFrame $listFrame;

    private readonly WindowFrame $searchFrame;

    private readonly WindowFrame $promptFrame;

    /** Which end of a title bar the close button sits at: the platform's. */
    private readonly CloseButton $closeButton;
    /** last-rendered search box, for mouse hit-testing */
    private ?ModalBox $searchBox = null;

    private readonly CursorStyle $cursorStyle;
    private readonly Translator $translator;
    private readonly ScreenDensity $density;

    /** Repaint request. A frame, not a widget invalidation — nothing below owns these boxes. */
    private readonly \Closure $invalidate;

    public function __construct(
        ?Tui $tui = null,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?Translator $translator = null,
        ?ScreenDensity $density = null,
        ?WindowControls $controls = null,
    ) {
        $this->initStyles($styles);
        $this->listFrame = new WindowFrame();
        $this->searchFrame = new WindowFrame();
        $this->promptFrame = new WindowFrame();
        $this->closeButton = $controls->closeButton ?? CloseButton::Standard;
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
        $this->translator = $translator ?? new Translator('en');
        $this->density = $density ?? new ScreenDensity();
        $this->invalidate = static function () use ($tui): void {
            $tui?->requestRender();
        };
    }

    /**
     * Open the modal list overlay (no-op for an empty list).
     *
     * @param list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}> $items
     * @param callable(string): void                                  $onPick receives the picked item's value
     */
    public function openList(string $title, array $items, callable $onPick): void
    {
        if ([] === $items) {
            return;
        }
        // A pick that reopens its own list — a checklist toggling in place —
        // gets the list back as it left it: the same filter, the picked row.
        $again = null !== $this->picked && $this->picked['title'] === $title ? $this->picked : null;
        if (null === $again) {
            $this->listFrame->reset(); // a fresh list opens at its own size, where lists open
        }
        $overlay = ['title' => $title, 'items' => $items, 'selected' => 0, 'hovered' => null, 'filter' => $again['filter'] ?? new TextField(style: $this->cursorStyle), 'onPick' => $onPick];
        $overlay['selected'] = LiveRows::from($this->matching($overlay), 0, 1);
        if (null !== $again) {
            $overlay['selected'] = (int) \array_search($again['value'], \array_column($this->matching($overlay), column_key: 'value'), strict: true);
        }
        $this->overlay = $overlay;
        ($this->invalidate)();
    }

    /** Dismiss the list overlay (e.g. on a buffer switch); the prompt is untouched. */
    public function closeList(): void
    {
        $this->overlay = null;
    }

    /** Dismiss the list overlay opened with $onPick — only that one, and only while it is open. */
    public function withdrawList(callable $onPick): void
    {
        if (($this->overlay['onPick'] ?? null) !== $onPick) {
            return;
        }
        $this->overlay = null;
        ($this->invalidate)();
    }

    /**
     * Open the single-line input prompt (go to line, rename symbol). A danger
     * prompt (delete confirmation) boxes its field with the double danger
     * border ({@see \Porta\Widget\Render\FieldState::Danger}), so a
     * destructive question never reads like a rename, colour or not.
     */
    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false, TextEcho $echo = TextEcho::Visible): void
    {
        $this->prompt = ['label' => $label, 'value' => new TextField($initial, $this->cursorStyle, echo: $echo), 'onSubmit' => $onSubmit, 'danger' => $danger];
        $this->promptFrame->reset();
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
        $this->searchFrame->reset();
        ($this->invalidate)();
    }

    public function isSearchOpen(): bool
    {
        return null !== $this->search;
    }

    /**
     * Replace the modal's rows. Rows for the query already answered — a slow
     * source arriving after the fast ones — keep the selected HIT, wherever it
     * moved to, so Enter never opens a row the reader did not choose; rows for
     * a new query select its best match, the first hit.
     *
     * @param list<SearchRow> $rows
     */
    public function setSearchRows(array $rows): void
    {
        $search = $this->search;
        if (null === $search) {
            return;
        }
        $was = $search->rowsFor === $search->query->value() ? $search->rows[$search->selected]->hit ?? null : null;
        $search->rows = $rows;
        $search->rowsFor = $search->query->value();
        $kept = null === $was
            ? null
            : \array_find_key(
                $rows,
                static fn (SearchRow $row): bool => null !== $row->hit && $row->hit->category === $was->category && $row->hit->label === $was->label && $row->hit->detail === $was->detail,
            );
        $search->selected = $kept ?? $this->firstHitAtOrAfter($rows, 0);
        $search->hovered = null; // the rows moved under a still pointer
        $search->tallest = \max($search->tallest, \count($rows));
        foreach ($rows as $row) {
            $search->widest = \max(
                $search->widest,
                AnsiUtils::visibleWidth($row->header ?? $row->note ?? '') + 4,
                AnsiUtils::visibleWidth($row->hit->label ?? '') + AnsiUtils::visibleWidth($row->hit->detail ?? '') + 6,
            );
        }
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
     * PgUp/PgDn a page of them, Tab/Shift+Tab to the next or previous
     * category, Enter picks the selected hit; the query field takes its caret
     * keys, and everything else edits the query.
     */
    public function handleSearchInput(string $keys, Keybindings $kb): void
    {
        $search = $this->search;
        if (null === $search) {
            return;
        }
        $before = $search->query->value();
        match (true) {
            $kb->matches($keys, 'select_cancel') => ($search->onClose)(),
            $kb->matches($keys, 'accept_completion') => $this->pickHit($search, $search->rows[$search->selected]->hit ?? null), // Enter
            null !== ($jumped = $this->jumpHit($search, $keys, $kb)) => $this->selectHit($search, $jumped),
            $kb->matches($keys, 'delete_char_backward') => $search->query->backspace() ? $this->queried($search) : null,
            // A caret move repaints; a Delete or Ctrl+W is a new query.
            $search->query->editKey($keys, $kb) => $before === $search->query->value() ? ($this->invalidate)() : $this->queried($search),
            $search->query->insert($keys) => $this->queried($search),
            default => null,
        };
    }

    private function pickHit(SearchModalState $search, ?SearchHit $hit): void
    {
        if (null !== $hit) {
            ($search->onPick)($hit);
        }
    }

    private function selectHit(SearchModalState $search, int $row): void
    {
        $search->selected = $row;
        ($this->invalidate)();
    }

    /** The query was edited: its new value goes to the search. */
    private function queried(SearchModalState $search): void
    {
        ($search->onQuery)($search->query->value());
    }

    /**
     * A left-click while the search modal is open: on a hit row it picks it,
     * on the ✖ or outside the box it closes, and anywhere else inside the box
     * (the query field, a header, the frame) it is swallowed — a click in a
     * dialog is never a request to leave it. Returns false when the modal is
     * not open.
     */
    public function searchClickAt(int $relRow, int $relCol): bool
    {
        $search = $this->search;
        if (null === $search) {
            return false;
        }
        $box = $this->searchBox;
        $index = $box?->itemAt($relRow, $relCol);
        $hit = null === $index ? null : $search->rows[$index]->hit ?? null;
        $cue = null === $box ? 0 : self::edgeCue($box, $relRow, \count($search->rows));
        // The ✖, or outside the box where nothing else took the click.
        $closes = (null !== $box && $box->closeHit($relRow, $relCol)) || (null === $hit && 0 === $cue && (null === $box || !$box->contains($relRow, $relCol)));
        match (true) {
            $closes => ($search->onClose)(),
            null !== $hit => ($search->onPick)($hit),
            0 !== $cue => $this->selectHit($search, $this->pageHit($search, $cue)),
            default => null,
        };

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
        $hovered = null !== $index && $search->rows[$index]->isHit() ? $index : null;
        $changed = $hovered !== $search->hovered;
        if ($changed) {
            $search->hovered = $hovered;
            ($this->invalidate)();
        }

        return $changed || $closeChanged;
    }

    /**
     * Modal keyboard handling: consumes every key while the prompt or the
     * list overlay is open (the prompt wins when both are). Returns whether
     * the input was consumed.
     */
    public function handleInput(string $keys, Keybindings $kb): bool
    {
        $prompt = $this->prompt;
        $overlay = $this->overlay;
        match (true) {
            null !== $prompt => $this->handlePromptInput($keys, $kb, $prompt),
            null !== $overlay => $this->handleOverlayInput($keys, $kb, $overlay),
            default => null,
        };

        return null !== $prompt || null !== $overlay;
    }

    /** @param array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool} $prompt the open prompt (non-null, narrowed by the caller) */
    private function handlePromptInput(string $keys, Keybindings $kb, array $prompt): void
    {
        match (true) {
            $kb->matches($keys, 'select_cancel') => $this->closePrompt(),
            $kb->matches($keys, 'accept_completion') => $this->submitPrompt($prompt), // Enter
            $kb->matches($keys, 'delete_char_backward') => $prompt['value']->backspace() ? ($this->invalidate)() : null,
            // The caret moves, so a rename's typo mid-word is fixed where it is.
            $prompt['value']->editKey($keys, $kb) || $prompt['value']->insert($keys) => ($this->invalidate)(),
            default => null,
        };
    }

    private function closePrompt(): void
    {
        $this->prompt = null;
        ($this->invalidate)();
    }

    /** @param array{label: string, value: TextField, onSubmit: callable(string): void, danger: bool} $prompt */
    private function submitPrompt(array $prompt): void
    {
        $this->closePrompt();
        $prompt['onSubmit']($prompt['value']->value());
    }

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay the open list overlay (non-null) */
    private function handleOverlayInput(string $keys, Keybindings $kb, array $overlay): void
    {
        $count = \count($this->matching($overlay));
        $before = $overlay['filter']->value();

        match (true) {
            // Esc clears the filter before it closes the list. Typing is how
            // you get around a long list, so a typo in the filter must not
            // also cost you the list you were reading.
            $kb->matches($keys, 'select_cancel') => '' !== $before ? $this->clearFilter($overlay) : $this->closeOverlay(),
            $kb->matches($keys, 'delete_char_backward') => $overlay['filter']->backspace() ? $this->refiltered($overlay) : null,
            // A caret move repaints; a Delete or Ctrl+W narrows afresh.
            $overlay['filter']->editKey($keys, $kb) => $before === $overlay['filter']->value() ? ($this->invalidate)() : $this->refiltered($overlay),
            // None of the list's own keys can run against an empty list: every
            // one of them steps the selection modulo $count, and a filter that
            // matches nothing makes that a division by zero. The surface stays
            // open showing why.
            0 !== $count => $this->handleListKey($keys, $kb, $overlay, $count),
            default => $this->typeIntoFilter($overlay, $keys),
        };
    }

    /**
     * A key over a list with rows in it: it moves, pages, picks or checks —
     * or, none of those, types into the filter.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function handleListKey(string $keys, Keybindings $kb, array $overlay, int $count): void
    {
        match (true) {
            $kb->matches($keys, 'cursor_up') => $this->reselect($overlay, ($overlay['selected'] - 1 + $count) % $count, -1),
            $kb->matches($keys, 'cursor_down') => $this->reselect($overlay, ($overlay['selected'] + 1) % $count, 1),
            $kb->matches($keys, 'page_up') || $kb->matches($keys, 'page_down') => $this->pageList($overlay, $count, $kb->matches($keys, 'page_up') ? -1 : 1),
            $kb->matches($keys, 'accept_completion') => $this->pickSelected($overlay),
            // Ctrl+E again cycles like PhpStorm
            $kb->matches($keys, 'recent_files') => $this->reselect($overlay, ($overlay['selected'] + 1) % $count),
            // A checklist takes Space as its checkbox's key — a browser's — so
            // Space toggles the row rather than typing into the filter, where it
            // matched every label with a space in it.
            ' ' === $keys && \array_any($overlay['items'], static fn (array $item): bool => \array_key_exists('checked', $item)) => $this->pick(
                $overlay,
                $this->matching($overlay)[$overlay['selected']],
            ),
            default => $this->typeIntoFilter($overlay, $keys),
        };
    }

    /**
     * A page is the rows the box shows; paging stops at the ends rather
     * than wrapping, as a list box's does.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function pageList(array $overlay, int $count, int $direction): void
    {
        $page = \max(1, $this->overlayBox->count ?? 1) * $direction;
        $this->reselect($overlay, LiveRows::within($this->matching($overlay), \max(0, \min($count - 1, $overlay['selected'] + $page)), $page <=> 0));
    }

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay */
    private function pickSelected(array $overlay): void
    {
        $this->pick($overlay, $this->matching($overlay)[$overlay['selected']]);
    }

    /** @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay */
    private function clearFilter(array $overlay): void
    {
        $overlay['filter']->clear();
        $this->refiltered($overlay);
    }

    private function closeOverlay(): void
    {
        $this->overlay = null;
        ($this->invalidate)();
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
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
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
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
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
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     */
    private function reselect(array $overlay, int $selected, int $direction = 1): void
    {
        $overlay['selected'] = LiveRows::from($this->matching($overlay), $selected, $direction);
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
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     *
     * @return list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>
     */
    private function matching(array $overlay): array
    {
        return ListFilter::matching($overlay['items'], $overlay['filter']->value());
    }

    /**
     * Close the list and run its pick, remembering what it closed for the
     * length of the pick — the debugger's exception filters reopen their own
     * list on every toggle, and used to come back unfiltered on the first row.
     *
     * @param array{title: string, items: list<array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}>, selected: int, hovered: int|null, filter: TextField, onPick: callable(string): void} $overlay
     * @param array{label: string, hint: string, value: string, checked?: bool, disabled?: bool, note?: bool, open?: \Closure(): void}                                                                                                                             $item
     */
    private function pick(array $overlay, array $item): void
    {
        // A disabled item or a note is there to be read, and picking it
        // leaves the list as it was — unless the note opens somewhere (a
        // problem row opens the Problems panel), which a click goes to.
        $run = LiveRows::runner($item, $overlay['onPick']);
        if (null === $run) {
            return;
        }
        $this->overlay = null;
        $this->picked = ['title' => $overlay['title'], 'filter' => $overlay['filter'], 'value' => $item['value']];
        try {
            $run();
        } finally {
            $this->picked = null;
        }
        ($this->invalidate)();
    }

    /**
     * A left-click while the list overlay is open (editor-pane-relative
     * coords): on an item it picks it, on the ✖ or outside the box it
     * dismisses, and elsewhere inside the box it is swallowed. Always
     * consumed; returns false when the overlay is not open.
     */
    public function clickAt(int $relRow, int $relCol): bool
    {
        $overlay = $this->overlay;
        if (null === $overlay) {
            return false;
        }

        $box = $this->overlayBox;
        $closeHit = null !== $box && $box->closeHit($relRow, $relCol);
        // Against the FILTERED list, like every other index here — the row the
        // pointer is over is the row the box drew.
        $matching = $closeHit ? [] : $this->matching($overlay);
        $index = $box?->itemAt($relRow, $relCol);
        $item = null === $index ? null : $matching[$index] ?? null;
        $count = \count($matching);
        $cue = null === $box ? 0 : self::edgeCue($box, $relRow, $count);
        // The ✖, or outside the box where nothing else took the click.
        $closes = $closeHit || (null === $item && (null === $box || (0 === $cue && !$box->contains($relRow, $relCol))));
        match (true) {
            $closes => $this->closeOverlay(),
            null !== $item => $this->pick($overlay, $item),
            null !== $box && 0 !== $cue => $this->reselect($overlay, LiveRows::within($matching, \max(0, \min($count - 1, $overlay['selected'] + ($cue * $box->count))), $cue)),
            default => null,
        };

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
        $changed = $index !== ($overlay['hovered'] ?? null);
        if ($changed) {
            $overlay['hovered'] = $index;
            $this->overlay = $overlay;
            ($this->invalidate)();
        }

        return $changed || $closeChanged;
    }

    /** Hover for the mini prompt: only its ✖ restyles; returns whether it changed. */
    public function promptHoverAt(int $relRow, int $relCol): bool
    {
        if (null === $this->prompt) {
            return false;
        }

        return $this->hoverClose(PickerSurface::Prompt, $this->promptBox, $relRow, $relCol);
    }

    /** $surface's window: its geometry, its drag and its lit handle. */
    private function frame(PickerSurface $surface): WindowFrame
    {
        return match ($surface) {
            PickerSurface::ListOverlay => $this->listFrame,
            PickerSurface::Search => $this->searchFrame,
            PickerSurface::Prompt => $this->promptFrame,
        };
    }

    /** $surface's box as last drawn, or null before it is. */
    private function boxOf(PickerSurface $surface): ?ModalBox
    {
        return match ($surface) {
            PickerSurface::ListOverlay => $this->overlayBox,
            PickerSurface::Search => $this->searchBox,
            PickerSurface::Prompt => $this->promptBox,
        };
    }

    /**
     * Light the close button or the resize handle under the pointer on
     * $surface's frame; returns whether that changed.
     */
    private function hoverClose(PickerSurface $surface, ?ModalBox $box, int $relRow, int $relCol): bool
    {
        if (!$this->frame($surface)->hover($box, $relRow, $relCol)) {
            return false;
        }
        ($this->invalidate)();

        return true;
    }

    /** Follow an armed move or resize of the topmost picker; false when none is armed. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function continueDragTo(int $row, int $col, bool $release): bool
    {
        $surface = $this->topmostSurface();
        if (null === $surface || !$this->frame($surface)->dragTo($row, $col, $release)) {
            return false;
        }
        ($this->invalidate)();

        return true;
    }

    /** A fresh press anywhere disarms a stale drag. */
    public function cancelDrag(): void
    {
        $this->listFrame->cancel();
        $this->searchFrame->cancel();
        $this->promptFrame->cancel();
    }

    /**
     * The first hit-row index at or after $from, else the first hit, else $from.
     *
     * @param list<SearchRow> $rows
     */
    private function firstHitAtOrAfter(array $rows, int $from): int
    {
        if (null !== ($rows[$from] ?? null) && $rows[$from]->isSelectable()) {
            return $from;
        }

        return \array_find_key($rows, static fn (SearchRow $row): bool => $row->isSelectable()) ?? $from;
    }

    /** Wheel over the list overlay: move the selection, wrapping like the arrow keys. */
    public function moveListSelection(int $delta): void
    {
        $overlay = $this->overlay;
        if (null === $overlay || [] === $this->matching($overlay)) {
            return;
        }
        $count = \count($this->matching($overlay));
        $overlay['selected'] = LiveRows::from($this->matching($overlay), ((($overlay['selected'] + $delta) % $count) + $count) % $count, $delta <=> 0);
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
            if ($search->rows[$i]->isSelectable()) {
                return $i;
            }
        }

        return $search->selected;
    }

    /**
     * The row a cursor, paging or category key selects, or null when $keys is
     * none of them. Up/Down step over hit rows, wrapping; PgUp/PgDn move a
     * box's worth of hits and stop at the ends; Tab and Shift+Tab land on the
     * first hit of the next or previous category, wrapping — the way a
     * grouped list's sections are walked.
     */
    private function jumpHit(SearchModalState $search, string $keys, Keybindings $kb): ?int
    {
        return match (true) {
            $kb->matches($keys, 'cursor_up') => $this->stepHit($search, -1),
            $kb->matches($keys, 'cursor_down') => $this->stepHit($search, 1),
            [] === $search->rows => null,
            $kb->matches($keys, 'page_up') => $this->pageHit($search, -1),
            $kb->matches($keys, 'page_down') => $this->pageHit($search, 1),
            "\t" === $keys => $this->categoryHit($search, 1),
            "\x1b[Z" === $keys => $this->categoryHit($search, -1),
            default => null,
        };
    }

    /** The first hit of the category $step away from the selection's, wrapping; null without categories. */
    private function categoryHit(SearchModalState $search, int $step): ?int
    {
        // Each category's first hit, in order; the one the selection is in is
        // the last whose first hit it has reached.
        $firsts = [];
        foreach ($search->rows as $i => $row) {
            if (!$row->isHeader() || !($search->rows[$i + 1] ?? $row)->isHit()) {
                continue;
            }
            $firsts[] = $i + 1;
        }
        if ([] === $firsts) {
            return null;
        }
        $current = \count(\array_filter($firsts, static fn (int $first): bool => $first <= $search->selected)) - 1;
        $next = (((\max(0, $current) + $step) % \count($firsts)) + \count($firsts)) % \count($firsts);

        return $firsts[$next];
    }

    /** The hit a box's worth of hits away in $dir, stopping at the ends. */
    private function pageHit(SearchModalState $search, int $dir): int
    {
        $at = $search->selected;
        for ($moved = 0, $i = $at + $dir; $moved < \max(1, $this->searchBox->count ?? 1) && $i >= 0 && $i < \count($search->rows); $i += $dir) {
            if (!$search->rows[$i]->isSelectable()) {
                continue;
            }
            $at = $i;
            ++$moved;
        }

        return $at;
    }

    /**
     * -1 or 1 when ($relRow) is the ▲ or ▼ a clipped list draws in its frame's
     * padding (the row under the title bar, the row over the bottom border),
     * else 0 — the cue is a target, as the tab strip's is.
     */
    private static function edgeCue(ModalBox $box, int $relRow, int $total): int
    {
        return match (true) {
            $relRow === ($box->startRow + 1) && $box->windowStart > 0 => -1,
            $relRow === ($box->startRow + $box->rows - 2) && ($box->windowStart + $box->count) < $total => 1,
            default => 0,
        };
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
     * item it picks, on the ✖ it closes, anywhere else it dismisses. Returns
     * false only when no surface is open.
     */
    public function pickerClickAt(int $row, int $col): bool
    {
        $surface = $this->topmostSurface();
        if (null === $surface) {
            return false;
        }

        // The frame first, as every window's: the title bar arms a move and
        // an edge a resize. The surface answers the press too — the ✖, an
        // item, a press outside — and swallows one on its frame.
        $box = $this->boxOf($surface);
        if (null !== $box) {
            $this->frame($surface)->press($box, $row, $col);
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
        $search = $this->search;
        $overlay = $this->overlay;
        match (true) {
            null !== $search => $this->moveSearchSelection($delta),
            null !== $overlay => $this->moveListSelection($delta),
            default => null,
        };

        return null !== $search || null !== $overlay;
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
        // The fields' height is the screen's, not the bounds': every boxed
        // control on one screen follows the one rule.
        $fieldRows = FieldBox::rowsFor($this->density->current());
        $placed = [];
        if (null !== $this->overlay) {
            // The FULL overlay, filter and all: layout narrows the rows with
            // the same rule this class indexes by, and sizes the box against
            // the unnarrowed list so it does not shrink and re-centre under
            // the reader on every keystroke.
            $placed[] = $this->place(
                PickerLayout::layoutList(
                    $bounds,
                    $this->overlay,
                    $this->styles,
                    $this->listFrame,
                    $this->translator->trans('no matches'),
                    $this->translator->trans('Type to filter'),
                    $this->direction(),
                    $fieldRows,
                    $this->closeButton,
                ),
                $this->overlayBox,
            );
        }
        if (null !== $this->search) {
            // Only the TOPMOST surface renders the caret (the prompt wins): two
            // markers in one frame mangle ScreenWriter's cursor conversion.
            $caret = null === $this->prompt ? CaretVisibility::Shown : CaretVisibility::Hidden;
            $placed[] = $this->place(
                PickerLayout::layoutSearch(
                    $bounds,
                    $this->search,
                    $this->styles,
                    $this->searchFrame,
                    $caret,
                    $this->translator->trans('Type to search…'),
                    $this->direction(),
                    $fieldRows,
                    $this->closeButton,
                ),
                $this->searchBox,
            );
        }
        if (null !== $this->prompt) {
            $placed[] = $this->place(PickerLayout::layoutPrompt($bounds, $this->prompt, $this->styles, $this->promptFrame, $fieldRows, $this->closeButton), $this->promptBox);
        }

        return $placed;
    }

    /** The locale's direction, which a picker row's layout mirrors with. */
    private function direction(): BaseDirection
    {
        return $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;
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
