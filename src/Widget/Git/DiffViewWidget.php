<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\DiffDocument;
use Porta\Git\DiffLine;
use Porta\Highlight\CodeHighlighter;
use Porta\Ui\Style;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\InputHook;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The git view's diff pane (GitHub Desktop's right column): unified (dual
 * old/new gutters) or side-by-side rendering, cyan hunk headers, ± rows
 * colored over syntax highlighting (deleted lines stay plain red — the
 * pre-image no longer matches the file's highlight state). The title row is
 * a toolbar with clickable display toggles. Scrollable; read-only for history.
 */
final class DiffViewWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;

    private const string ADD_FG = "\x1b[38;5;114m";
    private const string DEL_FG = "\x1b[38;5;203m";
    private const string HUNK_FG = "\x1b[38;5;75m";
    private const string GUTTER_FG = "\x1b[38;5;240m";

    private ?DiffDocument $document = null;
    private ?string $path = null;
    private string $placeholder = 'No changes selected';
    /** @var list<array{kind: string, hunk: int, line?: int, left?: int, right?: int}> unified or split render rows */
    private array $rows = [];
    /** @var array<string, string> highlighted text per "hunk:line" key (context/add lines only) */
    private array $highlighted = [];
    /** Side-by-side rendering instead of unified. */
    private bool $split = false;
    /** Mirror of the controller's hide-whitespace state, for the toolbar tag. */
    private bool $whitespaceHidden = false;
    /** @var list<array{name: string, start: int, end: int}> clickable toolbar tag spans from the last render */
    private array $buttonZones = [];
    private int $scroll = 0;
    /** Horizontal scroll offset (display columns into the line texts). */
    private int $hScroll = 0;
    /** Widest line text in the shown document, to clamp the h-scroll. */
    private int $maxLineWidth = 0;
    private int $lastRows = 12;
    private bool $readOnly = false;
    /** Cursor row (index into $rows); staging operates at/around it. */
    private int $cursor = 0;
    /** Range-selection anchor row, armed with `v`; null when no range is active. */
    private ?int $anchor = null;

    private readonly CodeHighlighter $highlighter;
    private int $highlightVersion = 0;

    /** @var (callable(): void)|null Tab: move focus to the next git pane */
    private $onFocusNext = null;
    /** @var (callable(): void)|null Esc: leave the git view */
    private $onExit = null;
    /** @var (callable(array<int, array<int, true>>): void)|null stage/unstage the selected lines (hunk index => line set) */
    private $onStageSelection = null;
    /** @var (callable(): void)|null `c`: cycle the diff context (default → ±10 → whole file) */
    private $onCycleContext = null;
    /** @var (callable(bool): void)|null the split/unified toggle flipped (for persistence) */
    private $onLayoutToggle = null;
    /** @var (callable(): void)|null `w`: hide/show whitespace changes (state lives in the controller) */
    private $onToggleWhitespace = null;
    /** @var (callable(string): void)|null ctrl+c: selected line texts, plain (no ± markers) */
    private $onCopy = null;

    public function __construct()
    {
        $this->highlighter = new CodeHighlighter();
    }

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    /** @param callable(array<int, array<int, true>>): void $callback */
    public function onStageSelection(callable $callback): self
    {
        $this->onStageSelection = $callback;

        return $this;
    }

    /** @param callable(): void $callback */
    public function onCycleContext(callable $callback): self
    {
        $this->onCycleContext = $callback;

        return $this;
    }

    /** @param callable(bool): void $callback */
    public function onLayoutToggle(callable $callback): self
    {
        $this->onLayoutToggle = $callback;

        return $this;
    }

    /** @param callable(): void $callback */
    public function onToggleWhitespace(callable $callback): self
    {
        $this->onToggleWhitespace = $callback;

        return $this;
    }

    /** @param callable(string): void $callback */
    public function onCopy(callable $callback): self
    {
        $this->onCopy = $callback;

        return $this;
    }

    /** Reflect the controller's hide-whitespace state on the toolbar tag. */
    public function setWhitespaceHidden(bool $hidden): void
    {
        if ($hidden !== $this->whitespaceHidden) {
            $this->whitespaceHidden = $hidden;
            $this->invalidate();
        }
    }

    /** Apply a split/unified preference without firing the persistence hook. */
    public function setSplit(bool $split): void
    {
        if ($split !== $this->split) {
            $this->split = $split;
            $this->rebuildRows();
        }
    }

    public function isSplit(): bool
    {
        return $this->split;
    }

    private function toggleLayout(): void
    {
        $this->split = !$this->split;
        $this->rebuildRows();
        if (null !== $this->onLayoutToggle) {
            ($this->onLayoutToggle)($this->split);
        }
    }

    /** Rebuild the render rows for the current mode, keeping cursor/scroll sane. */
    private function rebuildRows(): void
    {
        $this->rows = null === $this->document ? [] : ($this->split ? $this->document->splitRows() : $this->document->rows());
        $maxRow = max(0, \count($this->rows) - 1);
        $this->cursor = min($this->cursor, $maxRow);
        $this->scroll = min($this->scroll, $maxRow);
        $this->anchor = null;
        $this->invalidate();
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Show $document for $path (null document = the placeholder message). A
     * reload of the SAME path (after staging part of it) keeps the cursor and
     * scroll clamped in place instead of jumping back to the top.
     */
    public function setDocument(?DiffDocument $document, ?string $path, string $placeholder = 'No changes selected'): void
    {
        $samePath = null !== $path && $path === $this->path;
        $this->document = $document;
        $this->path = $path;
        $this->placeholder = $placeholder;
        if (!$samePath) {
            $this->cursor = 0;
            $this->scroll = 0;
            $this->hScroll = 0;
        }
        $this->maxLineWidth = 0;
        foreach ($document?->hunks ?? [] as $hunk) {
            foreach ($hunk->lines as $line) {
                $this->maxLineWidth = max($this->maxLineWidth, mb_strwidth($line->text));
            }
        }
        $this->rebuildRows();
        $this->highlightRows();
    }

    /**
     * Syntax-highlight the context/addition texts in one batch (deleted lines
     * stay plain). The highlighter caches by version, so one bumped counter
     * per load keeps repeated renders free.
     */
    private function highlightRows(): void
    {
        $this->highlighted = [];
        if (null === $this->document || null === $this->path) {
            return;
        }
        $this->highlighter->setFile($this->path);

        $keys = [];
        $texts = [];
        foreach ($this->document->hunks as $h => $hunk) {
            foreach ($hunk->lines as $l => $line) {
                if ('+' === $line->origin || ' ' === $line->origin) {
                    $keys[] = $h.':'.$l;
                    $texts[] = $line->text;
                }
            }
        }

        $highlighted = $this->highlighter->highlight($texts, ++$this->highlightVersion);
        foreach ($keys as $n => $key) {
            $this->highlighted[$key] = $highlighted[$n] ?? '';
        }
    }

    public function scrollBy(int $lines): void
    {
        $max = max(0, \count($this->rows) - max(1, $this->lastRows - 1));
        $this->scroll = max(0, min($this->scroll + $lines, $max));
        $this->invalidate();
    }

    /** Scroll the line texts horizontally; gutters and ± markers stay pinned. */
    public function scrollHBy(int $cols): void
    {
        $this->hScroll = max(0, min($this->hScroll + $cols, max(0, $this->maxLineWidth - 20)));
        $this->invalidate();
    }

    public function hScroll(): int
    {
        return $this->hScroll;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            if (null !== $this->onExit) {
                ($this->onExit)();
            }
        } elseif ($kb->matches($data, 'focus_next_pane')) {
            if (null !== $this->onFocusNext) {
                ($this->onFocusNext)();
            }
        } elseif ($kb->matches($data, 'cursor_up')) {
            $this->moveCursor(-1);
        } elseif ($kb->matches($data, 'cursor_down')) {
            $this->moveCursor(1);
        } elseif ($kb->matches($data, 'page_up')) {
            $this->moveCursor(-max(1, $this->lastRows - 2));
        } elseif ($kb->matches($data, 'page_down')) {
            $this->moveCursor(max(1, $this->lastRows - 2));
        } elseif ($kb->matches($data, 'range_anchor')) {
            // Allowed in read-only mode too: the range doubles as a COPY
            // selection there (staging stays blocked in fireStageSelection).
            if ([] !== $this->rows) {
                $this->anchor = null === $this->anchor ? $this->cursor : null;
                $this->invalidate();
            }
        } elseif ($kb->matches($data, 'copy')) {
            $this->fireCopy();
        } elseif ($kb->matches($data, 'stage_selection')) {
            $this->fireStageSelection();
        } elseif ($kb->matches($data, 'cycle_context')) {
            if (!$this->readOnly && [] !== $this->rows && null !== $this->onCycleContext) {
                ($this->onCycleContext)();
            }
        } elseif ($kb->matches($data, 'toggle_layout')) {
            $this->toggleLayout(); // display-only: allowed in read-only mode too
        } elseif ($kb->matches($data, 'toggle_whitespace')) {
            if (null !== $this->onToggleWhitespace) {
                ($this->onToggleWhitespace)(); // allowed in read-only mode too
            }
        } elseif ($kb->matches($data, 'scroll_left')) {
            $this->scrollHBy(-8);
        } elseif ($kb->matches($data, 'scroll_right')) {
            $this->scrollHBy(8);
        }
    }

    /**
     * A click on the toolbar row toggles the button under it; a click on a
     * diff row moves the cursor there (so mouse users can stage where they
     * click). Focus is handled by the router.
     */
    public function handleClick(int $relRow, int $relCol): void
    {
        if (0 === $relRow) {
            foreach ($this->buttonZones as $zone) {
                if ($relCol >= $zone['start'] && $relCol < $zone['end']) {
                    $this->fireButton($zone['name']);

                    return;
                }
            }

            return;
        }
        if ([] !== $this->rows) {
            $this->anchor = null; // a fresh click starts a new selection
            $this->cursor = max(0, min($this->scroll + $relRow - 1, \count($this->rows) - 1));
            $this->invalidate();
        }
    }

    /**
     * Extend a mouse drag selection: the press row becomes the anchor (once)
     * and the cursor follows the pointer, mirroring the keyboard `v` range.
     */
    public function dragSelectTo(int $relRow): void
    {
        if ([] === $this->rows) {
            return;
        }
        $this->anchor ??= $this->cursor;
        $this->cursor = max(0, min($this->scroll + $relRow - 1, \count($this->rows) - 1));
        $this->invalidate();
    }

    private function fireButton(string $name): void
    {
        if ('split' === $name) {
            $this->toggleLayout();
        } elseif ('whitespace' === $name && null !== $this->onToggleWhitespace) {
            ($this->onToggleWhitespace)();
        }
    }

    private function moveCursor(int $delta): void
    {
        if ([] === $this->rows) {
            return;
        }
        $this->cursor = max(0, min($this->cursor + $delta, \count($this->rows) - 1));
        // Keep the cursor inside the visible window (header takes one row).
        $visible = max(1, $this->lastRows - 1);
        if ($this->cursor < $this->scroll) {
            $this->scroll = $this->cursor;
        } elseif ($this->cursor > $this->scroll + $visible - 1) {
            $this->scroll = $this->cursor - $visible + 1;
        }
        $this->invalidate();
    }

    /**
     * Space: on a hunk header the whole hunk, on a ± line that line, with an
     * armed `v` anchor the whole row range. The selection is handed to the
     * controller, which knows the staging direction for the shown diff.
     */
    private function fireStageSelection(): void
    {
        if ($this->readOnly || null === $this->onStageSelection || !isset($this->rows[$this->cursor])) {
            return;
        }

        ($this->onStageSelection)($this->collectSelection());
    }

    /**
     * ctrl+c: put the selected lines on the clipboard as plain code — the
     * line texts in document order, no ± markers or numbers. Works on
     * read-only history diffs too; clears the range like staging does.
     */
    private function fireCopy(): void
    {
        $document = $this->document;
        $onCopy = $this->onCopy;
        if (null === $onCopy || null === $document || !isset($this->rows[$this->cursor])) {
            return;
        }

        $selected = $this->collectSelection();
        $texts = [];
        foreach ($document->hunks as $h => $hunk) {
            foreach ($hunk->lines as $l => $line) {
                if (isset($selected[$h][$l]) && '\\' !== $line->origin) {
                    $texts[] = $line->text;
                }
            }
        }
        if ([] !== $texts) {
            $onCopy(implode("\n", $texts)."\n");
        }
    }

    /**
     * The selection under the cursor: with an armed `v`/drag anchor the whole
     * row range (consumed), on a hunk header the whole hunk, else the single
     * row — as a hunk index => line-index set.
     *
     * @return array<int, array<int, true>>
     */
    private function collectSelection(): array
    {
        $selected = [];
        $row = $this->rows[$this->cursor];
        if (null !== $this->anchor) {
            [$from, $to] = $this->anchor <= $this->cursor ? [$this->anchor, $this->cursor] : [$this->cursor, $this->anchor];
            for ($i = $from; $i <= $to; ++$i) {
                $this->selectRowLines($this->rows[$i], $selected);
            }
            $this->anchor = null;
            $this->invalidate();
        } elseif ('hunk' === $row['kind']) {
            \assert(null !== $this->document);
            foreach ($this->document->hunks[$row['hunk']]->lines as $i => $line) {
                $selected[$row['hunk']][$i] = true;
            }
        } else {
            $this->selectRowLines($row, $selected);
        }

        return $selected;
    }

    /**
     * Collect a render row's line indices: a unified row holds one, a split
     * row up to two (its old/new pair — Space stages both sides together).
     *
     * @param array{kind: string, hunk: int, line?: int, left?: int, right?: int} $row
     * @param array<int, array<int, true>>                                        $selected
     */
    private function selectRowLines(array $row, array &$selected): void
    {
        if ('line' === $row['kind']) {
            $selected[$row['hunk']][$row['line'] ?? -1] = true;
        } elseif ('pair' === $row['kind']) {
            foreach ([$row['left'] ?? -1, $row['right'] ?? -1] as $index) {
                if ($index >= 0) {
                    $selected[$row['hunk']][$index] = true;
                }
            }
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $title = ' '.($this->path ?? 'Diff')
            .($this->scroll > 0 ? \sprintf('  [scrolled +%d]', $this->scroll) : '')
            .($this->hScroll > 0 ? \sprintf('  [→%d]', $this->hScroll) : '');
        $lines = [$this->renderToolbar($title, $width)];

        if (null === $this->document || [] === $this->rows) {
            $message = "\x1b[38;5;245m ".$this->placeholder."\x1b[0m";
            $lines[] = AnsiUtils::truncateToWidth($message, $width, '…', pad: true);
            while (\count($lines) < $rows) {
                $lines[] = str_repeat(' ', $width);
            }

            return $this->withMenuOverlay($lines, $width);
        }

        foreach (\array_slice($this->rows, $this->scroll, $rows - 1) as $i => $row) {
            $rowIndex = $this->scroll + $i;
            $line = $this->renderRow($rowIndex, $row, $width);
            if ($this->focused && $rowIndex === $this->cursor) {
                $line = "\x1b[48;5;237m".$line;
            } elseif ($this->inRange($rowIndex)) {
                $line = "\x1b[48;5;236m".$line;
            }
            $lines[] = $line;
        }
        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', $width);
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /**
     * Overlay the intraline changed range (byte offsets on the raw text,
     * converted to display columns) with a stronger background; the
     * ANSI-aware LineDecorator keeps any syntax SGR spans intact.
     */
    private function withEmphasis(string $rendered, DiffLine $line, string $bg): string
    {
        if (null === $line->changedRange) {
            return $rendered;
        }
        [$from, $to] = $line->changedRange;
        if ($from >= $to) {
            return $rendered;
        }
        $startCol = mb_strwidth(substr($line->text, 0, $from));
        $endCol = $startCol + mb_strwidth(substr($line->text, $from, $to - $from));

        return LineDecorator::apply($rendered, $startCol, $endCol, $bg, "\x1b[49m", '');
    }

    /** Whether $rowIndex sits inside the armed `v` range. */
    private function inRange(int $rowIndex): bool
    {
        if (null === $this->anchor) {
            return false;
        }
        [$from, $to] = $this->anchor <= $this->cursor ? [$this->anchor, $this->cursor] : [$this->cursor, $this->anchor];

        return $rowIndex >= $from && $rowIndex <= $to;
    }

    /**
     * The title row doubles as a toolbar: path + scroll hint on the left,
     * right-aligned clickable display toggles (spans recorded for the mouse).
     */
    private function renderToolbar(string $title, int $width): string
    {
        $this->buttonZones = [];
        $tags = [
            ['name' => 'split', 'label' => ' s:split ', 'on' => $this->split],
            ['name' => 'whitespace', 'label' => ' w:hide-ws ', 'on' => $this->whitespaceHidden],
        ];

        $rendered = '';
        $tagsWidth = 0;
        foreach ($tags as $tag) {
            $tagsWidth += mb_strwidth($tag['label']) + 1;
        }
        $col = max(0, $width - $tagsWidth);
        $left = AnsiUtils::truncateToWidth($title, $col, '…', pad: true);
        foreach ($tags as $tag) {
            $tagWidth = mb_strwidth($tag['label']);
            $this->buttonZones[] = ['name' => $tag['name'], 'start' => $col, 'end' => $col + $tagWidth];
            $style = $tag['on'] ? Style::SELECTED_ROW : "\x1b[48;5;238m\x1b[38;5;250m";
            $rendered .= $style.$tag['label']."\x1b[0m\x1b[48;5;237m\x1b[1m".' ';
            $col += $tagWidth + 1;
        }

        return "\x1b[48;5;237m\x1b[1m".$left.$rendered."\x1b[0m";
    }

    /** @param array{kind: string, hunk: int, line?: int, left?: int, right?: int} $row */
    private function renderRow(int $rowIndex, array $row, int $width): string
    {
        \assert(null !== $this->document);
        $hunk = $this->document->hunks[$row['hunk']];

        if ('hunk' === $row['kind']) {
            return self::HUNK_FG.AnsiUtils::truncateToWidth(' '.$hunk->header(), $width, '…', pad: true)."\x1b[0m";
        }
        if ('pair' === $row['kind']) {
            $leftW = intdiv($width - 1, 2);
            $rightW = $width - 1 - $leftW;

            return $this->renderHalf($row['hunk'], $row['left'] ?? -1, $leftW, old: true)
                .self::GUTTER_FG.'│'."\x1b[39m"
                .$this->renderHalf($row['hunk'], $row['right'] ?? -1, $rightW, old: false)."\x1b[0m";
        }

        $line = $hunk->lines[$row['line'] ?? -1];
        $gutter = self::GUTTER_FG
            .str_pad(null === $line->oldNo ? '' : (string) $line->oldNo, 4, ' ', \STR_PAD_LEFT)
            .' '
            .str_pad(null === $line->newNo ? '' : (string) $line->newNo, 4, ' ', \STR_PAD_LEFT)
            .' │'."\x1b[39m";

        [$marker, $text] = $this->lineBody($line, $row['hunk'].':'.($row['line'] ?? -1));

        return $gutter.$marker.$this->scrolledText($text, max(1, $width - 12))."\x1b[0m";
    }

    /**
     * A body line split into its pinned one-column ± marker and the scrollable
     * styled text. The origin char and gutter mark the row kind; added/context
     * text keeps its syntax colors, deletions render whole-row red (the
     * pre-image no longer matches the file's highlight state). Paired ± lines
     * carry an intraline changed range, emphasized with a stronger background.
     *
     * @return array{0: string, 1: string}
     */
    private function lineBody(DiffLine $line, string $key): array
    {
        return match ($line->origin) {
            '+' => [self::ADD_FG.'+'."\x1b[39m", $this->withEmphasis($this->highlighted[$key] ?? $line->text, $line, "\x1b[48;5;22m")],
            '-' => [self::DEL_FG.'-', $this->withEmphasis($line->text, $line, "\x1b[48;5;88m")],
            '\\' => ["\x1b[38;5;245m ", $line->text],
            default => [' ', $this->highlighted[$key] ?? $line->text],
        };
    }

    /**
     * The visible window of a styled line text: sliced by the horizontal
     * scroll (ANSI-aware, so syntax/emphasis SGR survives) with a leading …
     * marking cut-off text, right-truncated and padded to $inner columns.
     */
    private function scrolledText(string $text, int $inner): string
    {
        if ($this->hScroll <= 0) {
            return AnsiUtils::truncateToWidth($text, $inner, '…', pad: true);
        }

        $slice = AnsiUtils::sliceByColumn($text, $this->hScroll, $inner);

        return '…'.AnsiUtils::truncateToWidth($slice, max(1, $inner - 1), '…', pad: true);
    }

    /**
     * One side of a side-by-side row: 4-digit gutter + marker + content in
     * $width columns; -1 (no line on this side) renders blank. Colors reset
     * to the default foreground at the end so nothing bleeds past the divider.
     */
    private function renderHalf(int $hunkIndex, int $lineIndex, int $width, bool $old): string
    {
        \assert(null !== $this->document);
        if ($lineIndex < 0) {
            return str_repeat(' ', $width);
        }
        $line = $this->document->hunks[$hunkIndex]->lines[$lineIndex];
        $no = $old ? $line->oldNo : $line->newNo;
        $gutter = self::GUTTER_FG.str_pad(null === $no ? '' : (string) $no, 4, ' ', \STR_PAD_LEFT).' '."\x1b[39m";

        [$marker, $text] = $this->lineBody($line, $hunkIndex.':'.$lineIndex);

        return $gutter.$marker.$this->scrolledText($text, max(1, $width - 6))."\x1b[39m";
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // always fills its pane
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'range_anchor' => ['v'],
            'stage_selection' => [Key::SPACE],
            'copy' => ['ctrl+c'],
            'cycle_context' => ['c'],
            'toggle_layout' => ['s'],
            'toggle_whitespace' => ['w'],
            'scroll_left' => [Key::LEFT],
            'scroll_right' => [Key::RIGHT],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
