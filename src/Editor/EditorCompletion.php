<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Support\FuzzyMatcher;
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
    private string $prefix = '';
    private ?CompletionBox $box = null;
    private readonly SnippetSession $snippet;

    /**
     * @param \Closure(): void         $invalidate         request a redraw
     * @param \Closure(): void         $notifyChange       announce a buffer edit
     * @param \Closure(string): bool   $isInsertable       whether a keystroke is insertable text
     * @param \Closure(int, int): void          $requestCompletion  ask the IDE for completions at (line, utf16 col)
     * @param \Closure(array<string, mixed>): void $resolveCompletion resolve deferred edits for a raw server item
     */
    public function __construct(
        private readonly Document $document,
        private readonly \Closure $invalidate,
        private readonly \Closure $notifyChange,
        private readonly \Closure $isInsertable,
        private readonly \Closure $requestCompletion,
        private readonly \Closure $resolveCompletion,
    ) {
        $this->snippet = new SnippetSession($document, $invalidate, $notifyChange);
    }

    /** @param list<CompletionItem> $items */
    public function show(array $items): void
    {
        $this->prefix = $this->document->getWordBeforeCursor();
        $this->items = $items;
        $this->selected = 0;
        $this->open = [] !== $this->filtered();
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
            ($this->invalidate)();

            return true;
        }

        if ($kb->matches($keys, 'cursor_down')) {
            $this->selected = ($this->selected + 1) % \count($filtered);
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

        if (($this->isInsertable)($keys) && preg_match('/^[\w$]+$/u', $keys)) {
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

    /** Highlight the popup row under the pointer; true when the highlight changed. */
    public function handleHover(int $relRow, int $relCol): bool
    {
        if ($this->open) {
            $index = $this->completionItemAt($relRow, $relCol);
            if (null !== $index && $index !== $this->selected) {
                $this->selected = $index;
                ($this->invalidate)();

                return true;
            }
        }

        return false;
    }

    /**
     * The items + selected index to draw this frame (clamping the selection to the
     * filtered list), or null when the popup should not be drawn. The widget draws
     * it and hands the box geometry back via {@see setBox}.
     *
     * @return array{items: list<CompletionItem>, selected: int}|null
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
        $this->selected = min($this->selected, \count($items) - 1);

        return ['items' => $items, 'selected' => $this->selected];
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

    /** @return list<CompletionItem> */
    private function filtered(): array
    {
        $word = $this->document->getWordBeforeCursor();
        if ('' === $word || $word === $this->prefix) {
            return $this->items;
        }

        $needle = ltrim($word, characters: '$');

        // Fuzzy/camel-hump match, ranked best-first; the server's original
        // ordering (sortText) breaks ties.
        $scored = [];
        foreach ($this->items as $index => $item) {
            $score = FuzzyMatcher::score(ltrim($item->label, characters: '$'), $needle);
            if (null !== $score) {
                $scored[] = ['score' => $score, 'index' => $index, 'item' => $item];
            }
        }
        usort($scored, static fn (array $a, array $b) => ($cmp = $b['score'] <=> $a['score']) ? $cmp : $a['index'] <=> $b['index']);

        return array_map(static fn (array $e) => $e['item'], $scored);
    }
}
