<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

use Porta\Ui\TextField;
use Porta\Widget\Render\EditorRenderer;
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

    /**
     * @param \Closure(): void $invalidate repaint request back into the widget
     */
    public function __construct(
        private readonly \Closure $invalidate,
    ) {}

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
        $this->prompt = ['label' => $label, 'value' => new TextField($initial), 'onSubmit' => $onSubmit];
        ($this->invalidate)();
    }

    /**
     * Modal keyboard handling: consumes every key while the prompt or the
     * list overlay is open (the prompt wins when both are). Returns whether
     * the input was consumed.
     */
    public function handleInput(string $data, Keybindings $kb): bool
    {
        if (null !== $this->prompt) {
            $this->handlePromptInput($data, $kb);

            return true;
        }
        if (null !== $this->overlay) {
            $this->handleOverlayInput($data, $kb);

            return true;
        }

        return false;
    }

    private function handlePromptInput(string $data, Keybindings $kb): void
    {
        if ($kb->matches($data, 'select_cancel')) {
            $this->prompt = null;
            ($this->invalidate)();

            return;
        }

        if ($kb->matches($data, 'accept_completion')) { // Enter
            /** @var array{label: string, value: TextField, onSubmit: callable(string): void} $prompt */
            $prompt = $this->prompt;
            $this->prompt = null;
            ($this->invalidate)();
            $prompt['onSubmit']($prompt['value']->value());

            return;
        }

        if ($kb->matches($data, 'delete_char_backward')) {
            /** @var array{label: string, value: TextField, onSubmit: callable(string): void} $this->prompt */
            if ($this->prompt['value']->backspace()) {
                ($this->invalidate)();
            }

            return;
        }

        /** @var array{label: string, value: TextField, onSubmit: callable(string): void} $this->prompt */
        if ($this->prompt['value']->insert($data)) {
            ($this->invalidate)();
        }
    }

    private function handleOverlayInput(string $data, Keybindings $kb): void
    {
        /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable(string): void} $this->overlay */
        $count = \count($this->overlay['items']);

        if ($kb->matches($data, 'select_cancel')) {
            $this->overlay = null;
        } elseif ($kb->matches($data, 'cursor_up')) {
            $this->overlay['selected'] = ($this->overlay['selected'] - 1 + $count) % $count;
        } elseif ($kb->matches($data, 'cursor_down')) {
            $this->overlay['selected'] = ($this->overlay['selected'] + 1) % $count;
        } elseif ($kb->matches($data, 'accept_completion')) {
            $item = $this->overlay['items'][$this->overlay['selected']];
            $onPick = $this->overlay['onPick'];
            $this->overlay = null;
            $onPick($item['value']);
        } elseif ($kb->matches($data, 'recent_files')) {
            // Ctrl+E again cycles like PhpStorm
            $this->overlay['selected'] = ($this->overlay['selected'] + 1) % $count;
        } else {
            return;
        }
        ($this->invalidate)();
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

        $index = $this->itemAt($relRow, $relCol);
        /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable(string): void} $this->overlay */
        if (null !== $index) {
            $item = $this->overlay['items'][$index];
            $onPick = $this->overlay['onPick'];
            $this->overlay = null;
            ($this->invalidate)();
            $onPick($item['value']);
        } else {
            $this->overlay = null;
            ($this->invalidate)();
        }

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

        $index = $this->itemAt($relRow, $relCol);
        /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>, selected: int, onPick: callable(string): void} $this->overlay */
        if (null !== $index && $index !== $this->overlay['selected']) {
            $this->overlay['selected'] = $index;
            ($this->invalidate)();

            return true;
        }

        return false;
    }

    /** Absolute overlay item index at the given relative cell, or null if outside its rows. */
    private function itemAt(int $relRow, int $relCol): ?int
    {
        $box = $this->overlayBox;
        if (null === $box || $relCol < $box['col'] || $relCol >= ($box['col'] + $box['width'])) {
            return null;
        }
        $i = $relRow - ($box['startRow'] + 1); // row `startRow` is the title
        if ($i < 0 || $i >= $box['count']) {
            return null;
        }

        return $box['windowStart'] + $i;
    }

    /**
     * Composite the open overlay and/or prompt over the rendered editor
     * lines, recording the overlay's box for mouse hit-testing.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function composite(array $lines, int $columns, int $rows, bool $focused): array
    {
        $this->overlayBox = null;
        if (null !== $this->overlay) {
            $this->overlayBox = EditorRenderer::listOverlay($lines, $columns, $rows, $this->overlay);
        }

        if (null !== $this->prompt) {
            $text = ' '.$this->prompt['label'].': '.$this->prompt['value']->value();
            $width = min($columns, max(30, mb_strwidth($text) + 4));
            $rendered = ' '.$this->prompt['label'].': '.$this->prompt['value']->render(cursor: $focused);
            $box = "\x1b[48;5;237m\x1b[38;5;231m".AnsiUtils::truncateToWidth($rendered.' ', $width, '…', pad: true)."\x1b[0m";
            $tail = AnsiUtils::sliceByColumn($lines[0] ?? '', $width, $columns - $width);
            $tailWidth = AnsiUtils::visibleWidth($tail);
            $lines[0] = $box.$tail."\x1b[0m".str_repeat(' ', max(0, $columns - $width - $tailWidth));
        }

        /** @var list<string> $lines writing index 0 keeps the shape */
        return $lines;
    }
}
