<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Container\AsWidget;
use Porta\Git\DiffDocument;
use Porta\Git\DiffLine;
use Porta\Git\DiffRowKind;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\DarkTerminalTheme;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\InputHook;
use Porta\Widget\OptionTagState;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;
use Tempest\Highlight\TerminalTheme;

/**
 * The git view's diff pane (GitHub Desktop's right column): unified (dual
 * old/new gutters) or side-by-side rendering, cyan hunk headers, ± rows
 * colored over syntax highlighting (deleted lines stay plain red — the
 * pre-image no longer matches the file's highlight state). The title row is
 * a toolbar with clickable display toggles. Scrollable; read-only for history.
 */
#[AsWidget('git-diff', flex: 1)]
final class DiffViewWidget extends AbstractWidget implements FocusableInterface, LtrPane, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

    private readonly Styles $styles;

    private ?DiffDocument $document = null;
    private ?string $path = null;
    private string $placeholder = 'No changes selected';
    /** @var list<array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int}> unified or split render rows */
    private array $rows = [];
    /** @var array<string, string> highlighted text per "hunk:line" key (context/add lines only) */
    private array $highlighted = [];
    /** Side-by-side rendering instead of unified. */
    private bool $split = false;
    /** Mirror of the controller's hide-whitespace state, for the toolbar tag. */
    private bool $whitespaceHidden = false;
    /** @var list<array{name: string, start: int, end: int}> clickable toolbar tag spans from the last render */
    private array $buttonZones = [];
    /** Toolbar tag under the pointer ('split'|'whitespace'), or null. Visual only. */
    private ?string $hoveredButton = null;
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
    /**
     * Char-level mouse selection (press seeds, drag extends): display columns
     * into the raw line texts, hScroll-independent. In split mode it binds to
     * the half the press landed in; null side = unified.
     */
    private ?DiffCharSelection $charSel = null;
    /** Last render width, for mapping click columns to split halves. */
    private int $lastWidth = 80;

    private readonly CodeHighlighter $highlighter;
    private int $highlightVersion = 0;

    /** @var (callable(array<int, array<int, true>>): void)|null stage/unstage the selected lines (hunk index => line set) */
    private $onStageSelection = null;
    /** @var (callable(): void)|null `c`: cycle the diff context (default → ±10 → whole file) */
    private $onCycleContext = null;
    /** @var (callable(): void)|null the split/unified toggle flipped (for persistence; read state via isSplit) */
    private $onLayoutToggle = null;
    /** @var (callable(): void)|null `w`: hide/show whitespace changes (state lives in the controller) */
    private $onToggleWhitespace = null;
    /** @var (callable(string): void)|null ctrl+c: selected line texts, plain (no ± markers) */
    private $onCopy = null;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?TerminalTheme $syntaxTheme = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->highlighter = new CodeHighlighter(syntaxTheme: $syntaxTheme ?? new DarkTerminalTheme());
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

    /** @param callable(): void $callback */
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
    public function hideWhitespace(): void
    {
        if (!$this->whitespaceHidden) {
            $this->whitespaceHidden = true;
            $this->invalidate();
        }
    }

    /** Reflect the controller's show-whitespace state on the toolbar tag. */
    public function showWhitespace(): void
    {
        if ($this->whitespaceHidden) {
            $this->whitespaceHidden = false;
            $this->invalidate();
        }
    }

    /** Apply a split preference without firing the persistence hook. */
    public function useSplitView(): void
    {
        if (!$this->split) {
            $this->split = true;
            $this->rebuildRows();
        }
    }

    /** Apply a unified preference without firing the persistence hook. */
    public function useUnifiedView(): void
    {
        if ($this->split) {
            $this->split = false;
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
            ($this->onLayoutToggle)();
        }
    }

    /** Rebuild the render rows for the current mode, keeping cursor/scroll sane. */
    private function rebuildRows(): void
    {
        $this->rows = [];
        if (null !== $this->document) {
            $this->rows = $this->split ? $this->document->splitRows() : $this->document->rows();
        }
        $maxRow = max(0, \count($this->rows) - 1);
        $this->cursor = min($this->cursor, $maxRow);
        $this->scroll = min($this->scroll, $maxRow);
        $this->anchor = null;
        $this->charSel = null;
        $this->invalidate();
    }

    public function makeReadOnly(): void
    {
        $this->readOnly = true;
    }

    public function makeEditable(): void
    {
        $this->readOnly = false;
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
        foreach ($document->hunks ?? [] as $hunk) {
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
                if (!('+' === $line->origin || ' ' === $line->origin)) {
                    continue;
                }

                $keys[] = $h.':'.$l;
                $texts[] = $line->text;
            }
        }

        $highlighted = $this->highlighter->highlight($texts, ++$this->highlightVersion);
        foreach ($keys as $n => $key) {
            $this->highlighted[$key] = $highlighted[$n] ?? '';
        }
    }

    /**
     * Re-highlight the shown diff under the current theme, after a live theme
     * switch. The pre-highlighted lines cache by content (not theme), so they
     * must be rebuilt explicitly; the bumped version forces a fresh parse.
     */
    public function refreshTheme(): void
    {
        $this->highlighter->refreshTheme();
        $this->highlightRows();
        $this->invalidate();
    }

    public function scrollBy(int $lines): void
    {
        $max = max(0, \count($this->rows) - max(1, $this->lastRows - 1));
        $this->scroll = IntMath::clamp($this->scroll + $lines, 0, $max);
        $this->invalidate();
    }

    /** Scroll the line texts horizontally; gutters and ± markers stay pinned. */
    public function scrollHBy(int $cols): void
    {
        $this->hScroll = IntMath::clamp($this->hScroll + $cols, 0, max(0, $this->maxLineWidth - 20));
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
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'cursor_up')) {
            $this->moveCursor(-1);

            return;
        }
        if ($kb->matches($data, 'cursor_down')) {
            $this->moveCursor(1);

            return;
        }
        if ($kb->matches($data, 'page_up')) {
            $this->moveCursor(-max(1, $this->lastRows - 2));

            return;
        }
        if ($kb->matches($data, 'page_down')) {
            $this->moveCursor(max(1, $this->lastRows - 2));

            return;
        }
        if ($kb->matches($data, 'range_anchor')) {
            // Allowed in read-only mode too: the range doubles as a COPY
            // selection there (staging stays blocked in fireStageSelection).
            if ([] !== $this->rows) {
                $this->anchor = null === $this->anchor ? $this->cursor : null;
                $this->invalidate();
            }

            return;
        }
        if ($kb->matches($data, 'copy')) {
            $this->fireCopy();

            return;
        }
        if ($kb->matches($data, 'stage_selection')) {
            $this->fireStageSelection();

            return;
        }
        if ($kb->matches($data, 'cycle_context')) {
            if (!$this->readOnly && [] !== $this->rows && null !== $this->onCycleContext) {
                ($this->onCycleContext)();
            }

            return;
        }
        if ($kb->matches($data, 'toggle_layout')) {
            $this->toggleLayout(); // display-only: allowed in read-only mode too

            return;
        }
        if ($kb->matches($data, 'toggle_whitespace')) {
            if (null !== $this->onToggleWhitespace) {
                ($this->onToggleWhitespace)(); // allowed in read-only mode too
            }

            return;
        }
        if ($kb->matches($data, 'scroll_left')) {
            $this->scrollHBy(-8);

            return;
        }
        if ($kb->matches($data, 'scroll_right')) {
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
                if (!($relCol >= $zone['start'] && $relCol < $zone['end'])) {
                    continue;
                }

                $this->fireButton($zone['name']);

                return;
            }

            return;
        }
        if ([] !== $this->rows) {
            $this->anchor = null; // a fresh click starts a new selection
            $this->cursor = IntMath::clamp($this->scroll + $relRow - 1, 0, \count($this->rows) - 1);
            // Seed a (zero-width) char selection at the press point; a drag
            // extends it, a plain click leaves it degenerate (row-copy rules).
            $side = $this->sideAt($relCol);
            $col = $this->pointCol($relCol, $side);
            $this->charSel = new DiffCharSelection($this->cursor, $col, $this->cursor, $col, $side);
            $this->invalidate();
        }
    }

    /**
     * Extend the char selection to the pointer: the press point stays the
     * origin, the cursor follows, and in split mode the pointer's column is
     * projected onto the half the selection started in.
     */
    public function dragSelectTo(int $relRow, int $relCol): void
    {
        $sel = $this->charSel;
        if ([] === $this->rows || null === $sel) {
            return;
        }
        $row = IntMath::clamp($this->scroll + $relRow - 1, 0, \count($this->rows) - 1);
        $this->charSel = new DiffCharSelection($sel->fromRow, $sel->fromCol, $row, $this->pointCol($relCol, $sel->side), $sel->side);
        $this->cursor = $row;
        $this->invalidate();
    }

    /** Which split half a widget column falls in (null in unified mode). */
    private function sideAt(int $relCol): ?string
    {
        if (!$this->split) {
            return null;
        }

        return $relCol <= intdiv($this->lastWidth - 1, num2: 2) ? 'left' : 'right';
    }

    /**
     * A widget column as a display column into the (side's) line text,
     * accounting for gutters, the ± marker and the horizontal scroll.
     */
    private function pointCol(int $relCol, ?string $side): int
    {
        $textStart = match ($side) {
            null => 12,
            'left' => 6,
            default => intdiv($this->lastWidth - 1, num2: 2) + 7,
        };

        return max(0, $relCol - $textStart + $this->hScroll);
    }

    private function fireButton(string $name): void
    {
        if ('split' === $name) {
            $this->toggleLayout();

            return;
        }
        if ('whitespace' === $name && null !== $this->onToggleWhitespace) {
            ($this->onToggleWhitespace)();
        }
    }

    private function moveCursor(int $delta): void
    {
        if ([] === $this->rows) {
            return;
        }
        $this->cursor = IntMath::clamp($this->cursor + $delta, 0, \count($this->rows) - 1);
        // Keep the cursor inside the visible window (header takes one row).
        $visible = max(1, $this->lastRows - 1);
        if ($this->cursor < $this->scroll) {
            $this->scroll = $this->cursor;
        }
        if ($this->cursor > ($this->scroll + $visible - 1)) {
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
        if ($this->readOnly || null === $this->onStageSelection || !\array_key_exists($this->cursor, $this->rows)) {
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
        if (null === $onCopy || null === $document || !\array_key_exists($this->cursor, $this->rows)) {
            return;
        }

        // A real (non-degenerate) char selection wins; it copies the exact
        // span, no trailing newline — a literal text selection.
        $sel = $this->normalizedCharSel();
        if (null !== $sel) {
            $text = $this->charSelectionText($sel);
            $this->charSel = null;
            $this->invalidate();
            if ('' !== $text) {
                $onCopy($text);
            }

            return;
        }

        $selected = $this->collectSelection();
        $texts = [];
        foreach ($document->hunks as $h => $hunk) {
            foreach ($hunk->lines as $l => $line) {
                if (!(($selected[$h][$l] ?? null) !== null && '\\' !== $line->origin)) {
                    continue;
                }

                $texts[] = $line->text;
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

            return $selected;
        }
        if (DiffRowKind::Hunk === $row['kind']) {
            \assert(null !== $this->document, description: 'document is loaded');
            foreach ($this->document->hunks[$row['hunk']]->lines as $i => $line) {
                $selected[$row['hunk']][$i] = true;
            }

            return $selected;
        }
        $this->selectRowLines($row, $selected);

        return $selected;
    }

    /**
     * Collect a render row's line indices: a unified row holds one, a split
     * row up to two (its old/new pair — Space stages both sides together).
     *
     * @param array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int} $row
     * @param array<int, array<int, true>>                                        $selected
     */
    private function selectRowLines(array $row, array &$selected): void
    {
        if (DiffRowKind::Line === $row['kind']) {
            $selected[$row['hunk']][$row['line'] ?? -1] = true;

            return;
        }
        if (DiffRowKind::Pair === $row['kind']) {
            foreach ([$row['left'] ?? -1, $row['right'] ?? -1] as $index) {
                if ($index < 0) {
                    continue;
                }

                $selected[$row['hunk']][$index] = true;
            }
        }
    }

    /** The char selection ordered top-left to bottom-right; null when degenerate. */
    private function normalizedCharSel(): ?DiffCharSelection
    {
        return $this->charSel?->normalized();
    }

    /**
     * The plain text under a normalized char selection: per covered row the
     * (side's) line text sliced by display column — first row from its start
     * column, last row to its end column, middles whole. Hunk headers, `\`
     * markers and blank split sides contribute nothing.
     */
    private function charSelectionText(DiffCharSelection $sel): string
    {
        \assert(null !== $this->document, description: 'document is loaded');
        $texts = [];
        for ($r = $sel->fromRow; $r <= min($sel->toRow, \count($this->rows) - 1); ++$r) {
            $line = $this->lineAtRow($this->rows[$r], $sel->side);
            if (null === $line || '\\' === $line->origin) {
                continue;
            }
            $from = $r === $sel->fromRow ? $sel->fromCol : 0;
            $to = $r === $sel->toRow ? $sel->toCol : \PHP_INT_MAX;
            $start = self::textOffsetAt($line->text, $from);
            $end = \PHP_INT_MAX === $to ? \strlen($line->text) : self::textOffsetAt($line->text, $to);
            $texts[] = substr($line->text, $start, max(0, $end - $start));
        }

        return implode("\n", $texts);
    }

    /**
     * The DiffLine a render row shows (on $side in split mode), or null.
     *
     * @param array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int} $row
     */
    private function lineAtRow(array $row, ?string $side): ?DiffLine
    {
        \assert(null !== $this->document, description: 'document is loaded');
        if (DiffRowKind::Hunk === $row['kind']) {
            return null;
        }
        $index = $row['line'] ?? -1;
        if (DiffRowKind::Line !== $row['kind']) {
            $index = 'left' === ($side ?? 'left') ? $row['left'] ?? -1 : $row['right'] ?? -1;
        }

        return $index >= 0 ? $this->document->hunks[$row['hunk']]->lines[$index] : null;
    }

    /** The byte offset where a display column starts within a raw line text. */
    private static function textOffsetAt(string $text, int $col): int
    {
        $offset = 0;
        $width = 0;
        $len = mb_strlen($text);
        for ($i = 0; $i < $len && $width < $col; ++$i) {
            $ch = mb_substr($text, $i, length: 1);
            $width += mb_strwidth($ch);
            $offset += \strlen($ch);
        }

        return $offset;
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;
        $this->lastWidth = $width;

        $title =
            ' '
            .($this->path ?? $this->translator->trans('Diff'))
            .($this->scroll > 0 ? '  '.$this->translator->trans('[scrolled +%count%]', ['%count%' => $this->scroll]) : '')
            .($this->hScroll > 0 ? \sprintf('  [→%d]', $this->hScroll) : '');
        $lines = [$this->renderToolbar($title, $width)];

        if (null === $this->document || [] === $this->rows) {
            $message = $this->styles->of(Role::Dim)->apply(' '.$this->translator->trans($this->placeholder));
            $lines[] = AnsiText::truncate($message, $width, pad: true);
            while (\count($lines) < $rows) {
                $lines[] = str_repeat(' ', max(0, $width));
            }

            return $this->withMenuOverlay($lines, $width);
        }

        foreach (\array_slice($this->rows, $this->scroll, $rows - 1) as $i => $row) {
            $rowIndex = $this->scroll + $i;
            $line = $this->applyCharSelectionTint($rowIndex, $row, $this->renderRow($rowIndex, $row, $width), $width);
            $lines[] = $this->decorateRowLine($rowIndex, $line);
        }
        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', max(0, $width));
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /** The cursor/range background applied to a rendered diff row. */
    private function decorateRowLine(int $rowIndex, string $line): string
    {
        if ($this->focused && $rowIndex === $this->cursor) {
            return $this->styles->of(Role::HeaderBar)->apply($line);
        }
        if ($this->inRange($rowIndex)) {
            return $this->styles->of(Role::RowHighlightBg)->apply($line);
        }

        return $line;
    }

    /**
     * Tint the visible span of the char selection on a rendered row (the
     * selection's side only, in split mode), like the editor's selection bg.
     *
     * @param array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int} $row
     */
    private function applyCharSelectionTint(int $rowIndex, array $row, string $line, int $width): string
    {
        $sel = $this->normalizedCharSel();
        if (null === $sel || $rowIndex < $sel->fromRow || $rowIndex > $sel->toRow) {
            return $line;
        }
        $target = $this->lineAtRow($row, $sel->side);
        if (null === $target) {
            return $line;
        }
        $textStart = match ($sel->side) {
            null => 12,
            'left' => 6,
            default => intdiv($width - 1, num2: 2) + 7,
        };
        $segEnd = 'left' === $sel->side ? intdiv($width - 1, num2: 2) : $width;
        $fromCol = $rowIndex === $sel->fromRow ? $sel->fromCol : 0;
        $toCol = $rowIndex === $sel->toRow ? $sel->toCol : mb_strwidth($target->text);
        $start = max($textStart, $textStart + $fromCol - $this->hScroll);
        $end = min($segEnd, $textStart + $toCol - $this->hScroll);

        // Re-apply the bg after any reset inside the span (syntax SGR resets,
        // truncateToWidth's pad boundary) — without it the highlight dies
        // mid-selection on highlighted lines.
        $selectionBg = $this->styles->of(Role::SelectionBg)->getAnsiRestore();

        return $end <= $start ? $line : LineDecorator::apply($line, $start, $end, $selectionBg, Sgr::DEFAULT_BG, $selectionBg);
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
        $startCol = mb_strwidth(substr($line->text, offset: 0, length: $from));
        $endCol = $startCol + mb_strwidth(substr($line->text, $from, $to - $from));

        return LineDecorator::apply($rendered, $startCol, $endCol, $bg, Sgr::DEFAULT_BG, $bg);
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
        // The key-hint dialect: a bare key is written as its own name, like
        // the Esc/Tab hints elsewhere — never invented shorthand.
        $tags = [
            ['name' => 'split', 'label' => $this->translator->trans(' S split '), 'on' => $this->split],
            ['name' => 'whitespace', 'label' => $this->translator->trans(' W hide-ws '), 'on' => $this->whitespaceHidden],
        ];

        $rendered = '';
        $tagsWidth = 0;
        foreach ($tags as $tag) {
            $tagsWidth += mb_strwidth($tag['label']) + 1;
        }
        $col = max(0, $width - $tagsWidth);
        $left = AnsiText::truncate($title, $col, pad: true);
        foreach ($tags as $tag) {
            $tagWidth = mb_strwidth($tag['label']);
            $this->buttonZones[] = ['name' => $tag['name'], 'start' => $col, 'end' => $col + $tagWidth];
            $rendered .= $this->tagStyle(OptionTagState::of($tag['on'], $tag['name'] === $this->hoveredButton))->apply($tag['label']).' ';
            $col += $tagWidth + 1;
        }

        return $this->styles
            ->of(Role::HeaderBar)
            ->withBold()
            ->apply($left.$rendered);
    }

    /**
     * A toolbar toggle's shading. An ON tag already carries the bright
     * selection background and has no room for a second one, so the pointer
     * underlines it instead — the same resolution CommitBoxWidget's button
     * uses. An OFF tag takes the row-highlight tint.
     */
    private function tagStyle(OptionTagState $state): Style
    {
        return match ($state) {
            OptionTagState::On => $this->styles->of(Role::SelectedRow),
            OptionTagState::Hovered => $this->styles->merge(Role::RowHighlightBg, Role::Muted),
            OptionTagState::Off => $this->styles->merge(Role::TagOffBg, Role::Muted),
        };
    }

    /**
     * Tint the toolbar toggle under the pointer (widget-relative cell);
     * returns whether it changed. Only row 0 carries toggles.
     */
    public function hoverHeader(int $relRow, int $relCol): bool
    {
        $hovered = null;
        foreach (0 === $relRow ? $this->buttonZones : [] as $zone) {
            if ($relCol < $zone['start'] || $relCol >= $zone['end']) {
                continue;
            }

            $hovered = $zone['name'];
            break;
        }
        if ($hovered === $this->hoveredButton) {
            return false;
        }
        $this->hoveredButton = $hovered;
        $this->invalidate();

        return true;
    }

    /** The pointer left the diff pane: drop the toolbar tint. Returns whether it changed. */
    public function clearHeaderHover(): bool
    {
        return $this->hoverHeader(-1, -1);
    }

    /** @param array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int} $row */
    private function renderRow(int $rowIndex, array $row, int $width): string
    {
        \assert(null !== $this->document, description: 'document is loaded');
        $hunk = $this->document->hunks[$row['hunk']];

        if (DiffRowKind::Hunk === $row['kind']) {
            return $this->styles->of(Role::Hunk)->apply(AnsiText::truncate(' '.$hunk->header(), $width, pad: true));
        }
        if (DiffRowKind::Pair === $row['kind']) {
            $leftW = intdiv($width - 1, num2: 2);
            $rightW = $width - 1 - $leftW;

            return (
                $this->renderHalf($row['hunk'], $row['left'] ?? -1, $leftW, DiffSide::Old)
                .$this->styles->of(Role::Gutter)->apply('│')
                .$this->renderHalf($row['hunk'], $row['right'] ?? -1, $rightW, DiffSide::New)
                .Sgr::RESET
            );
        }

        $line = $hunk->lines[$row['line'] ?? -1];
        $gutter = $this->styles->of(Role::Gutter)->apply(
            str_pad(null === $line->oldNo ? '' : (string) $line->oldNo, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT)
            .' '
            .str_pad(null === $line->newNo ? '' : (string) $line->newNo, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT)
            .' │',
        );

        [$marker, $text] = $this->lineBody($line, $row['hunk'].':'.($row['line'] ?? -1));

        return $gutter.$marker.$this->scrolledText($text, max(1, $width - 12)).Sgr::RESET;
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
        // The '-' and '\' markers deliberately leave their foreground open so
        // the whole row inherits it (deletions render whole-row red).
        return match ($line->origin) {
            '+' => [$this->styles->of(Role::Added)->apply('+'), $this->withEmphasis($this->highlighted[$key] ?? $line->text, $line, $this->styles->of(Role::AddEmphasisBg)->getAnsiRestore())],
            '-' => [$this->styles->of(Role::Deleted)->getAnsiRestore().'-', $this->withEmphasis($line->text, $line, $this->styles->of(Role::DelEmphasisBg)->getAnsiRestore())],
            '\\' => [$this->styles->of(Role::Dim)->getAnsiRestore().' ', $line->text],
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
            return AnsiText::truncate($text, $inner, pad: true);
        }

        $slice = AnsiUtils::sliceByColumn($text, $this->hScroll, $inner);

        // A LEADING mark: the cut is at the head, so the row reads as scrolled.
        return Glyphs::ELLIPSIS.AnsiText::truncate($slice, max(1, $inner - 1), pad: true);
    }

    /**
     * One side of a side-by-side row: 4-digit gutter + marker + content in
     * $width columns; -1 (no line on this side) renders blank. Colors reset
     * to the default foreground at the end so nothing bleeds past the divider.
     */
    private function renderHalf(int $hunkIndex, int $lineIndex, int $width, DiffSide $side): string
    {
        \assert(null !== $this->document, description: 'document is loaded');
        if ($lineIndex < 0) {
            return str_repeat(' ', max(0, $width));
        }
        $line = $this->document->hunks[$hunkIndex]->lines[$lineIndex];
        $no = $side->lineNo($line);
        $gutter = $this->styles->of(Role::Gutter)->apply(str_pad(null === $no ? '' : (string) $no, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT).' ');

        [$marker, $text] = $this->lineBody($line, $hunkIndex.':'.$lineIndex);

        return $gutter.$marker.$this->scrolledText($text, max(1, $width - 6)).Sgr::DEFAULT_FG;
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
