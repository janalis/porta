<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * An active snippet tab-stop session: the list of stops, the current index, and
 * the Tab/Shift-Tab/typing behaviour that drives the caret between them.
 *
 * It owns the session state and edits the injected {@see Document}; the two
 * closures are the only seam back to the widget — one to request a redraw, one
 * to announce a buffer change — so snippet mechanics stay out of the widget.
 */
final class SnippetSession
{
    /** @var array{stops: list<array{line: int, start: int, end: int}>, index: int}|null */
    private ?array $session = null;

    /**
     * @param \Closure(): void $onInvalidate request a redraw
     * @param \Closure(): void $onChange     announce a buffer edit
     */
    public function __construct(
        private readonly Document $document,
        private readonly \Closure $onInvalidate,
        private readonly \Closure $onChange,
    ) {}

    public function isActive(): bool
    {
        return null !== $this->session;
    }

    /** Cancel the session (e.g. before an external edit); leaves the caret in place. */
    public function clear(): void
    {
        $this->session = null;
    }

    /**
     * Start a session for a snippet just inserted at ($startLine, $startCol).
     * Does nothing (stays inactive) when the snippet has no tab stops.
     *
     * @param list<SnippetTabStop> $rawStops byte offsets into $text
     */
    public function begin(int $startLine, int $startCol, string $text, array $rawStops): void
    {
        $stops = $this->absoluteStops($startLine, $startCol, $text, $rawStops);
        if ([] !== $stops) {
            $this->session = ['stops' => $stops, 'index' => 0];
            $this->selectStop($stops[0]);
        }
    }

    /**
     * Handle a key while the session is active: Tab/Shift+Tab jump between stops,
     * Esc ends the session, typing edits the current stop (keeping later same-line
     * stops aligned), and anything else ends the session and falls through to
     * normal handling.
     *
     * @param \Closure(string): bool $isInsertable printable-char guard from the widget
     */
    public function handleInput(string $keys, Keybindings $kb, \Closure $isInsertable): bool
    {
        if ($kb->matches($keys, 'indent')) {
            $this->moveStop(1);

            return true;
        }
        if ($kb->matches($keys, 'prev_tab_stop')) {
            $this->moveStop(-1);

            return true;
        }
        if ($kb->matches($keys, 'select_cancel')) {
            // Collapse any placeholder selection and leave the caret in place.
            $this->document->setCursor($this->document->getCursorLine(), $this->document->getCursorCol());
            $this->session = null;
            ($this->onInvalidate)();

            return true;
        }

        $printable = $isInsertable($keys);
        $isBackspace = $kb->matches($keys, 'delete_char_backward');
        if (($printable || $isBackspace) && !\str_contains($keys, "\n")) {
            $line = $this->document->getCursorLine();
            /** @var array{stops: list<array{line: int, start: int, end: int}>, index: int} $session */
            $session = $this->session;
            $index = $session['index'];
            $active = $session['stops'][$index];
            if ($active['line'] === $line) {
                $before = \strlen($this->document->getLine($line));
                if ($printable) {
                    $this->document->insert($keys);
                }
                if (!$printable) {
                    $this->deleteAtCaret();
                }
                $this->adjustStops($index, $line, \strlen($this->document->getLine($line)) - $before);
                ($this->onChange)();

                return true;
            }
        }

        // Arrow keys, Enter, etc. end the session and are handled normally.
        $this->session = null;

        return false;
    }

    /** Delete the current selection, or the char before the caret when there is none. */
    private function deleteAtCaret(): void
    {
        if ($this->document->hasSelection()) {
            $this->document->deleteSelection();

            return;
        }
        $this->document->deleteBackward();
    }

    private function moveStop(int $direction): void
    {
        /** @var array{stops: list<array{line: int, start: int, end: int}>, index: int} $session */
        $session = $this->session;
        $stops = $session['stops'];
        $index = $session['index'] + $direction;

        if ($index >= \count($stops)) {
            // Past the last stop: drop the caret there and end the session.
            $last = $stops[\count($stops) - 1];
            $this->document->setCursor($last['line'], $last['start']);
            $this->session = null;
            ($this->onInvalidate)();

            return;
        }

        $index = \max(0, $index);
        $session['index'] = $index;
        $this->session = $session;
        $this->selectStop($stops[$index]);
        ($this->onInvalidate)();
    }

    /** @param array{line: int, start: int, end: int} $stop */
    private function selectStop(array $stop): void
    {
        $doc = $this->document;
        $doc->setCursor($stop['line'], $stop['start']);
        if ($stop['end'] > $stop['start']) {
            $doc->beginSelect();
            $doc->setCursor($stop['line'], $stop['end'], selection: SelectionMode::Keep);
        }
    }

    /** Shift stops that follow the edited one on the same line by $delta bytes. */
    private function adjustStops(int $activeIndex, int $line, int $delta): void
    {
        /** @var array{stops: list<array{line: int, start: int, end: int}>, index: int} $session */
        $session = $this->session;
        $threshold = $session['stops'][$activeIndex]['end'];
        foreach ($session['stops'] as $i => $stop) {
            if ($i === $activeIndex) {
                $session['stops'][$i]['end'] += $delta;

                continue;
            }
            if ($stop['line'] === $line && $stop['start'] >= $threshold) {
                $session['stops'][$i]['start'] += $delta;
                $session['stops'][$i]['end'] += $delta;
            }
        }
        $this->session = $session;
    }

    /**
     * Convert snippet tab stops (byte offsets into the inserted text) into
     * absolute document positions given the insertion point.
     *
     * @param list<SnippetTabStop> $stops
     *
     * @return list<array{line: int, start: int, end: int}>
     */
    private function absoluteStops(int $startLine, int $startCol, string $text, array $stops): array
    {
        $result = [];
        foreach ($stops as $stop) {
            $result[] = [
                'line' => $startLine + \substr_count(\substr($text, offset: 0, length: $stop->start), needle: "\n"),
                'start' => $this->offsetToColumn($startCol, $text, $stop->start),
                'end' => $this->offsetToColumn($startCol, $text, $stop->end),
            ];
        }

        return $result;
    }

    /** Byte column of a text offset once inserted at $startCol on its line. */
    private function offsetToColumn(int $startCol, string $text, int $offset): int
    {
        $lastNewline = \strrpos(\substr($text, offset: 0, length: $offset), needle: "\n");

        return false === $lastNewline ? $startCol + $offset : $offset - $lastNewline - 1;
    }
}
