<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\DiffDocument;
use Porta\Git\DiffLine;
use Porta\Highlight\CodeHighlighter;
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
 * The git view's diff pane (GitHub Desktop's right column): dual old/new
 * line-number gutters, cyan hunk headers, ± rows colored over syntax
 * highlighting (deleted lines stay plain red — the pre-image no longer
 * matches the file's highlight state). Scrollable; read-only for history.
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
    /** @var list<array{kind: 'hunk'|'line', hunk: int, line: int}> */
    private array $rows = [];
    /** @var array<int, string> highlighted text per flat row index (context/add rows only) */
    private array $highlighted = [];
    private int $scroll = 0;
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
        $this->rows = $document?->rows() ?? [];
        $maxRow = max(0, \count($this->rows) - 1);
        $this->cursor = $samePath ? min($this->cursor, $maxRow) : 0;
        $this->scroll = $samePath ? min($this->scroll, $maxRow) : 0;
        $this->anchor = null;
        $this->highlightRows();
        $this->invalidate();
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

        $indices = [];
        $texts = [];
        foreach ($this->rows as $i => $row) {
            if ('line' !== $row['kind']) {
                continue;
            }
            $line = $this->document->hunks[$row['hunk']]->lines[$row['line']];
            if ('+' === $line->origin || ' ' === $line->origin) {
                $indices[] = $i;
                $texts[] = $line->text;
            }
        }

        $highlighted = $this->highlighter->highlight($texts, ++$this->highlightVersion);
        foreach ($indices as $n => $rowIndex) {
            $this->highlighted[$rowIndex] = $highlighted[$n] ?? '';
        }
    }

    public function scrollBy(int $lines): void
    {
        $max = max(0, \count($this->rows) - max(1, $this->lastRows - 1));
        $this->scroll = max(0, min($this->scroll + $lines, $max));
        $this->invalidate();
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
            if (!$this->readOnly && [] !== $this->rows) {
                $this->anchor = null === $this->anchor ? $this->cursor : null;
                $this->invalidate();
            }
        } elseif ($kb->matches($data, 'stage_selection')) {
            $this->fireStageSelection();
        } elseif ($kb->matches($data, 'cycle_context')) {
            if (!$this->readOnly && [] !== $this->rows && null !== $this->onCycleContext) {
                ($this->onCycleContext)();
            }
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

        $selected = [];
        $row = $this->rows[$this->cursor];
        if (null !== $this->anchor) {
            [$from, $to] = $this->anchor <= $this->cursor ? [$this->anchor, $this->cursor] : [$this->cursor, $this->anchor];
            for ($i = $from; $i <= $to; ++$i) {
                $r = $this->rows[$i];
                if ('line' === $r['kind']) {
                    $selected[$r['hunk']][$r['line']] = true;
                }
            }
            $this->anchor = null;
            $this->invalidate();
        } elseif ('hunk' === $row['kind']) {
            \assert(null !== $this->document);
            foreach ($this->document->hunks[$row['hunk']]->lines as $i => $line) {
                $selected[$row['hunk']][$i] = true;
            }
        } else {
            $selected[$row['hunk']][$row['line']] = true;
        }

        ($this->onStageSelection)($selected);
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $title = ' '.($this->path ?? 'Diff').($this->scroll > 0 ? \sprintf('  [scrolled +%d]', $this->scroll) : '');
        $lines = ["\x1b[48;5;237m\x1b[1m".AnsiUtils::truncateToWidth($title, $width, '…', pad: true)."\x1b[0m"];

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

    /** @param array{kind: 'hunk'|'line', hunk: int, line: int} $row */
    private function renderRow(int $rowIndex, array $row, int $width): string
    {
        \assert(null !== $this->document);
        $hunk = $this->document->hunks[$row['hunk']];

        if ('hunk' === $row['kind']) {
            return self::HUNK_FG.AnsiUtils::truncateToWidth(' '.$hunk->header(), $width, '…', pad: true)."\x1b[0m";
        }

        $line = $hunk->lines[$row['line']];
        $gutter = self::GUTTER_FG
            .str_pad(null === $line->oldNo ? '' : (string) $line->oldNo, 4, ' ', \STR_PAD_LEFT)
            .' '
            .str_pad(null === $line->newNo ? '' : (string) $line->newNo, 4, ' ', \STR_PAD_LEFT)
            .' │'."\x1b[39m";

        // The origin char and gutter mark the row kind; added/context text keeps
        // its syntax colors, deletions render whole-row red (the pre-image no
        // longer matches the file's highlight state). Paired ± lines carry an
        // intraline changed range, emphasized with a stronger background.
        $content = match ($line->origin) {
            '+' => self::ADD_FG.'+'."\x1b[39m".$this->withEmphasis($this->highlighted[$rowIndex] ?? $line->text, $line, "\x1b[48;5;22m"),
            '-' => self::DEL_FG.'-'.$this->withEmphasis($line->text, $line, "\x1b[48;5;88m"),
            '\\' => "\x1b[38;5;245m ".$line->text,
            default => ' '.($this->highlighted[$rowIndex] ?? $line->text),
        };

        return $gutter.AnsiUtils::truncateToWidth($content, max(1, $width - 11), '…', pad: true)."\x1b[0m";
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
            'cycle_context' => ['c'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
