<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\FuzzyMatcher;
use Porta\Widget\Render\PopupMetrics;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The editor's LSP completion + snippet machinery: the popup's item list, its
 * open/selected/prefix state and rendered box geometry, fuzzy filtering, keyboard
 * navigation, click/hover hit-testing, and accepting an item — either as plain
 * text or, when the server marks it a snippet, as a tab-stop session (with inline
 * and deferred auto-imports). Built per document (like {@see MultiCaret}); effects
 * and the request/resolve LSP round-trips flow through injected closures.
 */
final class EditorCompletion
{
    /** @var list<CompletionItem> */
    private array $items = [];
    private bool $open = false;
    private int $selected = 0;
    /** Row tinted under the pointer; visual only, never the selection. */
    private ?int $hovered = null;
    private string $prefix = '';
    private ?CompletionBox $box = null;
    /**
     * Items already asked about: object id => the label asked about. An item is
     * asked once and once only, however often the popup re-filters around it or
     * the selection walks back over it.
     *
     * @var array<int, string>
     */
    private array $detailAsked = [];
    private readonly SnippetSession $snippet;

    /**
     * @param \Closure(): void         $invalidate         request a redraw
     * @param \Closure(): void         $notifyChange       announce a buffer edit
     * @param \Closure(string): bool   $isInsertable       whether a keystroke is insertable text
     * @param \Closure(int, int): void          $requestCompletion  ask the IDE for completions at (line, utf16 col)
     * @param \Closure(array<string, mixed>): void $resolveCompletion resolve deferred edits for a raw server item
     * @param \Closure(array<string, mixed>, \Closure(string, string): void): void $resolveDetail ask for an item's type and docblock, deferred by some servers
     */
    public function __construct(
        private readonly Document $document,
        private readonly \Closure $invalidate,
        private readonly \Closure $notifyChange,
        private readonly \Closure $isInsertable,
        private readonly \Closure $requestCompletion,
        private readonly \Closure $resolveCompletion,
        private readonly \Closure $resolveDetail,
    ) {
        $this->snippet = new SnippetSession($document, $invalidate, $notifyChange);
    }

    /** @param list<CompletionItem> $items */
    public function show(array $items): void
    {
        $this->prefix = $this->document->getWordBeforeCursor();
        $this->items = $items;
        $this->selected = 0;
        $this->hovered = null; // the list rebuilt under a still pointer
        $this->open = [] !== $this->filtered();
        $this->detailAsked = [];
        $this->askForVisibleDetails();
        ($this->invalidate)();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->open = false;
        $this->items = [];
        $this->selected = 0;
        $this->hovered = null;
        $this->prefix = '';
    }

    /** Cancel any active snippet session (e.g. before an external edit). */
    public function clearSnippet(): void
    {
        $this->snippet->clear();
    }

    /** Ask the IDE for completions at the caret (auto-trigger). */
    public function requestCompletion(): void
    {
        ($this->requestCompletion)($this->document->getCursorLine(), $this->document->getCursorColUtf16());
    }

    /**
     * Expand the live template at the caret on the `indent` key, PhpStorm's Tab.
     * Consumed only when there IS one to expand, so Tab keeps indenting
     * everywhere else. An active tab-stop session never reaches here — the
     * widget routes Tab to {@see handleSnippetTabStop} first, which is what
     * makes Tab walk the stops of the template it just expanded.
     *
     * @param array<string, string> $templates abbreviation => LSP snippet body
     */
    public function expandTemplate(string $keys, Keybindings $kb, array $templates): bool
    {
        if (!$kb->matches($keys, 'indent') || $this->open || $this->document->hasSelection()) {
            return false;
        }
        $doc = $this->document;
        $line = $doc->getCursorLine();
        $found = TemplateExpander::at($doc->getLine($line), $doc->getCursorCol(), $templates);
        if (null === $found) {
            return false;
        }

        $doc->replaceBeforeCursor($found['consume'], '');
        $startLine = $doc->getCursorLine();
        $startCol = $doc->getCursorCol();
        $parsed = SnippetParser::parse($found['snippet']);
        $doc->insert($parsed['text']);
        $doc->endCompound();
        ($this->notifyChange)();
        $this->snippet->begin($startLine, $startCol, $parsed['text'], $parsed['stops']);

        return true;
    }

    /** Route a keystroke to an active snippet tab-stop session; true when consumed. */
    public function handleSnippetTabStop(string $keys, Keybindings $kb): bool
    {
        return $this->snippet->isActive() && !$this->open && $this->snippet->handleInput($keys, $kb, $this->isInsertable);
    }

    public function handleInput(string $keys, Keybindings $kb): bool
    {
        $filtered = $this->filtered();
        if ([] === $filtered) {
            $this->close();

            return false;
        }

        if ($kb->matches($keys, 'select_cancel')) {
            $this->close();
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'cursor_up')) {
            $this->selected = ($this->selected - 1 + \count($filtered)) % \count($filtered);
            $this->askForVisibleDetails();
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'cursor_down')) {
            $this->selected = ($this->selected + 1) % \count($filtered);
            $this->askForVisibleDetails();
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'accept_completion') || $kb->matches($keys, 'indent')) {
            $item = $filtered[$this->selected] ?? null;
            if (null !== $item) {
                $this->acceptItem($item);
            }

            return true;
        }

        // Typing narrows the filter; deleting widens it
        if ($kb->matches($keys, 'delete_char_backward')) {
            if ('' === $this->document->getWordBeforeCursor()) {
                $this->close();
            }

            return false; // let normal handling perform the deletion
        }

        if (($this->isInsertable)($keys) && \preg_match('/^[\w$]+$/u', $keys)) {
            return false; // identifier chars: insert normally, popup refilters on render
        }

        // Any other key closes the popup and is handled normally
        $this->close();

        return false;
    }

    /** Consume a click that lands on a popup row (accepting that item); false otherwise. */
    public function handleClick(int $relRow, int $relCol): bool
    {
        if ($this->open) {
            $index = $this->completionItemAt($relRow, $relCol);
            if (null !== $index) {
                $item = $this->filtered()[$index] ?? null;
                if (null !== $item) {
                    $this->acceptItem($item);
                }

                return true;
            }
        }

        return false;
    }

    /** Wheel over the completion popup: move the selection, wrapping like the arrow keys. */
    public function moveSelection(int $delta): void
    {
        $filtered = $this->filtered();
        if ([] === $filtered) {
            return;
        }
        $count = \count($filtered);
        $this->selected = ((($this->selected + $delta) % $count) + $count) % $count;
        $this->askForVisibleDetails();
        ($this->invalidate)();
    }

    /** Tint the popup row under the pointer; true when the tint changed. Never moves the selection. */
    public function handleHover(int $relRow, int $relCol): bool
    {
        $index = $this->open ? $this->completionItemAt($relRow, $relCol) : null;
        if ($index === $this->hovered) {
            return false;
        }
        $this->hovered = $index;
        ($this->invalidate)();

        return true;
    }

    /**
     * The items + selected index to draw this frame (clamping the selection to the
     * filtered list), or null when the popup should not be drawn. The widget draws
     * it and hands the box geometry back via {@see setBox}.
     *
     * @return array{items: list<CompletionItem>, selected: int, hovered: int|null}|null
     */
    public function popupState(): ?array
    {
        if (!$this->open) {
            return null;
        }
        $items = $this->filtered();
        if ([] === $items) {
            return null;
        }
        $this->selected = \min($this->selected, \count($items) - 1);
        // The list refilters as you type, so a resting pointer's row can move
        // out from under it — drop a tint that no longer names a row.
        $hovered = null !== $this->hovered && $this->hovered < \count($items) ? $this->hovered : null;

        return ['items' => $items, 'selected' => $this->selected, 'hovered' => $hovered];
    }

    /** Remember the last-rendered popup box geometry for mouse hit-testing. */
    public function setBox(?CompletionBox $box): void
    {
        $this->box = $box;
    }

    /** Filtered-completion index at the given relative cell, or null if outside its rows. */
    private function completionItemAt(int $relRow, int $relCol): ?int
    {
        return $this->box?->itemAt($relRow, $relCol);
    }

    /**
     * Apply an accepted completion item: expand a snippet into a tab-stop session
     * when the server marked it as one, else insert its plain text. In both cases
     * inline auto-imports are applied, and deferred imports are requested via
     * completionItem/resolve.
     */
    private function acceptItem(CompletionItem $item): void
    {
        $doc = $this->document;
        /** @var list<array{newText: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}> $additional */
        $additional = $item->additionalTextEdits;

        $doc->beginCompound();
        // Apply inline auto-imports first so the caret (and any snippet stops
        // computed below) sit at their final line.
        if ([] !== $additional) {
            $doc->applyEditsKeepingCursor($additional);
        }

        $prefix = $doc->getWordBeforeCursor();

        $this->expandOrInsert($doc, $item, $item->snippet, $prefix);

        ($this->notifyChange)();

        // No inline edits but the server may defer auto-imports to
        // completionItem/resolve — ask the IDE to fetch and apply them.
        if ([] === $additional && [] !== $item->raw) {
            ($this->resolveCompletion)($item->raw);
        }
    }

    /**
     * Expand an accepted item as a snippet tab-stop session when the server
     * marked it one, else replace the prefix with its plain insert text.
     */
    private function expandOrInsert(Document $doc, CompletionItem $item, ?string $snippet, string $prefix): void
    {
        if (null !== $snippet && '' !== $snippet) {
            $doc->replaceBeforeCursor(\strlen($prefix), '');
            $startLine = $doc->getCursorLine();
            $startCol = $doc->getCursorCol();
            $parsed = SnippetParser::parse($snippet);
            $doc->insert($parsed['text']);
            $doc->endCompound();

            $this->close();
            $this->snippet->begin($startLine, $startCol, $parsed['text'], $parsed['stops']);

            return;
        }
        $doc->replaceBeforeCursor(\strlen($prefix), $item->insertText);
        $doc->endCompound();
        $this->close();
    }

    /**
     * Ask the server for the types of the rows now on screen.
     *
     * Some servers send the type with the completion and some make you ask:
     * measured, phpantom_lsp answers `detail: "string"` in the response itself,
     * while phpactor sends label/kind/insertText and nothing else and only
     * answers `pub greet(string $who): string` to `completionItem/resolve`. A
     * type column fed only by the first response is empty against the second.
     *
     * Only what is on screen, and only once per item: the list can be hundreds
     * of entries and resolving all of them is a request storm for eight visible
     * rows. The window is the renderer's own, from {@see PopupMetrics}.
     *
     * The gap this leaves, stated rather than hidden: typing narrows the filter
     * without going through here, so a row that scrolls in under a new prefix
     * may show no type until the selection reaches it. Servers that send the
     * type inline never show the gap at all.
     */
    private function askForVisibleDetails(): void
    {
        $filtered = $this->filtered();
        $window = \array_slice($filtered, \max(0, $this->selected - PopupMetrics::COMPLETION_ROWS + 1), PopupMetrics::COMPLETION_ROWS);
        foreach ($window as $item) {
            $id = \spl_object_id($item);
            // A row with BOTH already needs nothing; one with only a type still
            // wants its docblock, which is the half servers defer most.
            if ('' !== $item->detail && '' !== $item->documentation || null !== ($this->detailAsked[$id] ?? null) || [] === $item->raw) {
                continue;
            }
            $this->detailAsked[$id] = $item->label;
            ($this->resolveDetail)($item->raw, function (string $detail, string $documentation) use ($item): void {
                $this->applyResolved($item, $detail, $documentation);
            });
        }
    }

    /**
     * Put a resolved type and docblock on their item, if it is still in the
     * list — the
     * answer can arrive after the popup closed or was rebuilt by a newer
     * request, and the one thing it must not do is write a type onto whatever
     * now sits at that position.
     */
    private function applyResolved(CompletionItem $item, string $detail, string $documentation): void
    {
        $index = \array_search($item, $this->items, strict: true);
        if (!\is_int($index)) {
            return;
        }
        // Neither half overwrites what the first response already gave: a
        // server that answers only one of them must not blank the other.
        $resolved = '' === $detail ? $item : $item->withDetail($detail);
        $resolved = '' === $documentation ? $resolved : $resolved->withDocumentation($documentation);
        $this->items[$index] = $resolved;
        $this->detailAsked[\spl_object_id($resolved)] = $item->label;
        ($this->invalidate)();
    }

    /** @return list<CompletionItem> */
    private function filtered(): array
    {
        $word = $this->document->getWordBeforeCursor();
        if ('' === $word || $word === $this->prefix) {
            return $this->items;
        }

        $needle = \ltrim($word, characters: '$');

        // Fuzzy/camel-hump match, ranked best-first; the server's original
        // ordering (sortText) breaks ties.
        $scored = [];
        foreach ($this->items as $index => $item) {
            $score = FuzzyMatcher::score(\ltrim($item->label, characters: '$'), $needle);
            if (null !== $score) {
                $scored[] = ['score' => $score, 'index' => $index, 'item' => $item];
            }
        }
        \usort($scored, static fn (array $a, array $b): int => ($cmp = $b['score'] <=> $a['score']) ? $cmp : $a['index'] <=> $b['index']);

        return \array_map(static fn (array $e): CompletionItem => $e['item'], $scored);
    }
}
