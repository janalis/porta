<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\LtrPane;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\Style;
use Porta\Ui\TextField;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom results panel: project-wide search (Ctrl+Shift+F) and, later,
 * find-usages results. Hidden (zero rows) when closed.
 */
final class ResultsPanelWidget extends OverlayWidget implements FocusableInterface, LtrPane
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use PanelRows;

    private bool $withQuery = false;
    private bool $withFilter = false;
    private string $title = '';
    private readonly TextField $query;
    private readonly TextField $filter;
    /** 'query' | 'filter' — which field typing edits; cycled by Tab */
    private string $activeField = 'query';
    private readonly ListSelection $list;
    private bool $searching = false;

    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> ranges (search mode only) are display-column match spans */
    private array $results = [];
    private bool $grouped = false;
    private ResultGrouping $grouping = ResultGrouping::File;
    /** @var array<string, bool> group key (file or directory) => collapsed (grouped mode only) */
    private array $collapsed = [];
    /** @var array<int, bool> matchIndex => its context lines are expanded (Alt+.) */
    private array $contextExpanded = [];
    /** @var array<int, list<array{line: int, text: string}>> matchIndex => cached neighbouring lines */
    private array $contextCache = [];
    /** @var list<DisplayRow> the flattened rows actually rendered/navigated */
    private array $displayRows = [];

    /** @var (callable(string): void)|null */
    private $onQueryChanged = null;
    /** @var (callable(): void)|null re-run the current search (F5) */
    private $onRerun = null;
    /** @var (callable(string, int): void)|null */
    private $onJump = null;
    /** @var (callable(): void)|null export the current results to a file (Alt+X) */
    private $onExport = null;
    /** @var (callable(): void)|null pin/restore the result set (Alt+K) */
    private $onPin = null;
    /** the pinned search's label shown in the header, or null when nothing is pinned */
    private ?string $pinLabel = null;
    /** @var (\Closure(string, int): list<array{line: int, text: string}>)|null neighbouring source lines around a match (path, line) => lines */
    private $contextProvider = null;

    private readonly Palette $palette;

    public function __construct(?Palette $palette = null)
    {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->list = new ListSelection();
        $this->query = new TextField();
        $this->filter = new TextField();
        $this->visibleRows = 10;
    }

    public function onQueryChanged(callable $callback): self
    {
        $this->onQueryChanged = $callback;

        return $this;
    }

    public function onRerun(callable $callback): self
    {
        $this->onRerun = $callback;

        return $this;
    }

    public function onExport(callable $callback): self
    {
        $this->onExport = $callback;

        return $this;
    }

    public function onPin(callable $callback): self
    {
        $this->onPin = $callback;

        return $this;
    }

    /** Show (or clear) the "pinned: <label>" indicator in the header. */
    public function setPinIndicator(?string $label): void
    {
        $this->pinLabel = $label;
        $this->invalidate();
    }

    /**
     * Set the provider of the neighbouring source lines shown around an expanded
     * match (Alt+.).
     *
     * @param \Closure(string, int): list<array{line: int, text: string}> $provider (relativePath, line) => lines
     */
    public function setContextProvider(\Closure $provider): void
    {
        $this->contextProvider = $provider;
    }

    public function onJump(callable $callback): self
    {
        $this->onJump = $callback;

        return $this;
    }

    public function open(string $title, ResultsPanelFields $fields): void
    {
        $this->open = true;
        $this->withQuery = $fields->query;
        $this->withFilter = $fields->filter;
        $this->pinLabel = null;
        $this->grouped = $fields->grouped;
        $this->grouping = ResultGrouping::File;
        // Focus the first shown field (the results-window mode has only the filter).
        $this->activeField = $this->focusableFields()[0] ?? 'query';
        $this->title = $title;
        $this->query->clear();
        $this->filter->clear();
        $this->results = [];
        $this->collapsed = [];
        $this->contextExpanded = [];
        $this->contextCache = [];
        $this->rebuildView();
        $this->list->reset();
        $this->searching = false;
        $this->invalidate();
    }

    /** @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results */
    public function setResults(array $results): void
    {
        $this->results = $results;
        $this->collapsed = [];
        $this->contextExpanded = [];
        $this->contextCache = [];
        $this->rebuildView();
        $this->list->reset();
        // Start on the first match, not the leading file header, so Enter jumps.
        foreach ($this->displayRows as $i => $row) {
            if (null === $row->filePath) {
                $this->list->select($i);

                break;
            }
        }
        $this->searching = false;
        $this->invalidate();
    }

    /** Recompute the flattened display rows from the current results + collapse state. */
    private function rebuildView(): void
    {
        $this->displayRows = ResultsView::build($this->results, $this->collapsed, $this->grouped, $this->filter->value(), $this->grouping, $this->contextExpanded, $this->contextCache);
        // Collapsing/filtering can shrink the list under the selection.
        $count = \count($this->displayRows);
        if ($count > 0 && $this->list->selected() >= $count) {
            $this->list->select($count - 1);
        }
    }

    /** Alt+E: expand every group when all are collapsed, otherwise collapse every group. */
    private function toggleAllGroups(): void
    {
        $keys = [];
        foreach ($this->results as $result) {
            if (ResultGrouping::Nested === $this->grouping) {
                $keys[\dirname($result['path'])] = true;
                $keys[$result['path']] = true;
            } else {
                $keys[$this->grouping->keyFor($result['path'])] = true;
            }
        }
        $allCollapsed = true;
        foreach (array_keys($keys) as $key) {
            if (!($this->collapsed[$key] ?? false)) {
                $allCollapsed = false;

                break;
            }
        }
        $this->collapsed = [];
        if (!$allCollapsed) {
            foreach (array_keys($keys) as $key) {
                $this->collapsed[$key] = true;
            }
        }
        $this->rebuildView();
        $this->invalidate();
    }

    public function setSearching(): void
    {
        $this->searching = true;
        $this->invalidate();
    }

    protected function resetState(): void
    {
        $this->results = [];
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'close')) {
            $this->close();
        } elseif (ListNavigation::handleKey($data, $kb, $this->list, \count($this->displayRows))) {
            $this->invalidate();
        } elseif ($kb->matches($data, 'jump')) {
            $this->activateRow($this->list->selected());
        } elseif ($this->grouped && $kb->matches($data, 'toggle_all')) {
            $this->toggleAllGroups();
        } elseif ($this->grouped && $kb->matches($data, 'toggle_group')) {
            $this->grouping = $this->grouping->next();
            $this->collapsed = [];
            $this->rebuildView();
            $this->invalidate();
        } elseif ($this->grouped && $kb->matches($data, 'export')) {
            if (null !== $this->onExport) {
                ($this->onExport)();
            }
        } elseif ($this->grouped && $kb->matches($data, 'pin')) {
            if (null !== $this->onPin) {
                ($this->onPin)();
            }
        } elseif ($this->grouped && $kb->matches($data, 'toggle_context')) {
            $this->toggleContext($this->list->selected());
        } elseif ($kb->matches($data, 'rerun')) {
            if (null !== $this->onRerun) {
                ($this->onRerun)();
            }
        } elseif ($kb->matches($data, 'switch_field') && \count($this->focusableFields()) > 1) {
            $fields = $this->focusableFields();
            $pos = (int) array_search($this->activeField, $fields, true);
            $this->activeField = $fields[($pos + 1) % \count($fields)];
            $this->invalidate();
        } elseif ($kb->matches($data, 'insert_newline') && 'query' === $this->activeField) {
            $field = $this->activeTextField();
            if (null !== $field) {
                $field->insertNewline();
                $this->afterFieldEdit();
                $this->invalidate();
            }
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            $field = $this->activeTextField();
            if (null !== $field && $field->backspace()) {
                $this->afterFieldEdit();
                $this->invalidate();
            }
        } else {
            $field = $this->activeTextField();
            if (null !== $field && $field->insert($data)) {
                $this->afterFieldEdit();
                $this->invalidate();
            }
        }
    }

    /** @return list<string> the shown text fields, in Tab-cycle order */
    private function focusableFields(): array
    {
        $fields = [];
        if ($this->withQuery) {
            $fields[] = 'query';
        }
        if ($this->withFilter) {
            $fields[] = 'filter';
        }

        return $fields;
    }

    /** The TextField typing currently edits, or null when no field is shown. */
    private function activeTextField(): ?TextField
    {
        return match ($this->activeField) {
            'filter' => $this->withFilter ? $this->filter : null,
            default => $this->withQuery ? $this->query : null,
        };
    }

    /** After an edit: the query re-runs the search, the filter re-narrows the loaded view. */
    private function afterFieldEdit(): void
    {
        if ('query' === $this->activeField) {
            $this->notifyQuery();
        } elseif ('filter' === $this->activeField) {
            $this->rebuildView(); // client-side; no re-grep
        }
    }

    /**
     * Route a left-click at the given widget-relative row: a file header
     * collapses/expands; a match row jumps.
     */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $this->activateRow($index);
    }

    /** Activate a display row: a file header collapses/expands, a match row jumps. */
    private function activateRow(int $index): void
    {
        $row = $this->displayRows[$index] ?? null;
        if (null === $row) {
            return;
        }
        if (null !== $row->filePath) {
            $this->collapsed[$row->filePath] = !($this->collapsed[$row->filePath] ?? false);
            $this->rebuildView();
            $this->invalidate();

            return;
        }
        if (null !== $row->contextText) {
            return; // context rows are inert
        }
        $result = $this->results[$row->matchIndex];
        if (null !== $this->onJump) {
            $this->close();
            ($this->onJump)($result['path'], $result['line']);
        }
    }

    /** Alt+. on a match row toggles the neighbouring source lines shown beneath it. */
    private function toggleContext(int $index): void
    {
        $row = $this->displayRows[$index] ?? null;
        if (null === $row || null !== $row->filePath || null !== $row->contextText) {
            return; // only real match rows expand
        }
        $matchIndex = $row->matchIndex;
        if ($this->contextExpanded[$matchIndex] ?? false) {
            unset($this->contextExpanded[$matchIndex]);
        } else {
            $this->contextExpanded[$matchIndex] = true;
            if (!isset($this->contextCache[$matchIndex]) && null !== $this->contextProvider) {
                $result = $this->results[$matchIndex];
                $this->contextCache[$matchIndex] = ($this->contextProvider)($result['path'], $result['line']);
            }
        }
        $this->rebuildView();
        $this->invalidate();
    }

    /** Highlight the display row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        if ($this->list->selectIfChanged($this->rowIndexAt($relRow))) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** Display-row index at the given widget-relative row, or null if it is not a result/header row. */
    private function rowIndexAt(int $relRow): ?int
    {
        // Row 0 is the title/query row; the filter row (when shown) follows.
        $headerRows = 1 + ($this->withFilter ? 1 : 0);
        $i = $relRow - $headerRows;
        if ($i < 0) {
            return null;
        }
        $index = $this->list->scroll() + $i;

        return $index < \count($this->displayRows) ? $index : null;
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();
        $lines = [];

        $status = $this->searching ? ' searching…' : \sprintf(' %d results', \count($this->results));
        if (!$this->searching && '' !== $this->filter->value()) {
            $shown = 0;
            foreach ($this->displayRows as $row) {
                if (null === $row->filePath) {
                    ++$shown;
                }
            }
            $status .= \sprintf(' · %d shown', $shown);
        }
        if (null !== $this->pinLabel) {
            $status .= \sprintf(' · pinned: %s', $this->pinLabel);
        }
        $showCursor = $this->focused && $this->withQuery;
        $queryField = $this->query->render(cursor: $showCursor && 'query' === $this->activeField);
        $prefix = ' '.$this->title.($this->withQuery ? ': '.$queryField : '').$this->palette->of(Role::Dim).$status."\x1b[39m";

        $lines[] = HeaderRenderer::render($prefix, $columns, $this->palette->of(Role::HeaderBar)."\x1b[1m");

        if ($this->withFilter) {
            $lines[] = $this->fieldRow('Filter', $this->filter, 'filter', $showCursor, '  (narrows the results below)', $columns);
        }

        $scroll = $this->list->follow($this->visibleRows);

        foreach (\array_slice($this->displayRows, $scroll, $this->visibleRows) as $i => $row) {
            $isSelected = $this->list->isSelected($scroll + $i);
            $bg = $isSelected ? ($this->focused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::InlayPopupBg)) : $this->palette->of(Role::Toolbar);
            $lines[] = match (true) {
                null !== $row->filePath => $this->headerLine($row, $bg, $columns),
                null !== $row->contextText => $this->contextRowLine($row->contextLine, $row->contextText, $bg, $columns),
                default => $this->matchLine($this->results[$row->matchIndex], $bg, $columns),
            };
        }

        if ([] === $this->results && !$this->searching && '' !== $this->query->value()) {
            $lines[] = $this->palette->of(Role::Toolbar).$this->palette->of(Role::CompletionHint).AnsiUtils::truncateToWidth(' no results', $columns, '', pad: true)."\x1b[0m";
        }

        return $lines;
    }

    private function notifyQuery(): void
    {
        if (null !== $this->onQueryChanged) {
            ($this->onQueryChanged)($this->query->value());
        }
    }

    /** A labelled input header row (mask/replace), with a cursor when it is the active field. */
    private function fieldRow(string $label, TextField $field, string $name, bool $showCursor, string $hint, int $columns): string
    {
        $rendered = $field->render(cursor: $showCursor && $name === $this->activeField);
        $line = ' '.$label.': '.$rendered.$this->palette->of(Role::Dim).$hint."\x1b[39m";

        return $this->palette->of(Role::RowHighlightBg).AnsiText::scrollLineToCaret($line, $columns, AnsiUtils::cursorMarker(CursorShape::Bar))."\x1b[0m";
    }

    /** The indent for grouped match rows — deeper in the nested view (below a dir + file header). */
    private function matchIndent(): string
    {
        return ResultGrouping::Nested === $this->grouping ? '      ' : '    ';
    }

    /** A dimmed context row shown around an expanded match: `<line>  text`, indented under the match. */
    private function contextRowLine(int $line, string $text, string $bg, int $columns): string
    {
        $content = $this->matchIndent().'  '.$this->palette->of(Role::Dim).(string) ($line + 1).'  '.$text."\x1b[39m";

        return $bg.AnsiUtils::truncateToWidth($content, $columns, '…', pad: true)."\x1b[0m";
    }

    /**
     * A collapsible group header `▾ label  (count)`: a file (`path`, or its
     * basename indented under a directory in the nested view) or, in the nested
     * view, a directory (`dir/`).
     */
    private function headerLine(DisplayRow $row, string $bg, int $columns): string
    {
        $path = (string) $row->filePath;
        if ($row->isDirectory) {
            $display = $path.'/';
            $indent = '';
        } elseif (ResultGrouping::Nested === $this->grouping) {
            $display = basename($path);
            $indent = '  ';
        } else {
            $display = $path;
            $indent = '';
        }
        $arrow = $row->collapsed ? '▸' : '▾';
        $text = $indent.' '.$arrow.' '.$this->palette->of(Role::TabAccent).$display."\x1b[39m".$this->palette->of(Role::Dim).'  ('.$row->count.')'."\x1b[39m";

        return $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
    }

    /**
     * The per-match label under a group header: the line number alone (grouped
     * by file), or `basename:line` (grouped by directory, so files within a
     * directory stay distinguishable).
     *
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function groupedLabel(array $result): string
    {
        $line = (string) ($result['line'] + 1);

        return ResultGrouping::Directory === $this->grouping ? basename($result['path']).':'.$line : $line;
    }

    /**
     * One match row — `path:line  text` when flat, or an indented `line  text`
     * under its file header when grouped — with the match spans highlighted.
     *
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function matchLine(array $result, string $bg, int $columns): string
    {
        if ($this->grouped) {
            $indent = $this->matchIndent();
            $label = $this->groupedLabel($result);
            $prefixWidth = mb_strwidth($indent) + mb_strwidth($label) + 2; // indent + label + `  `
            $text = $indent.$this->palette->of(Role::TabAccent).$label."\x1b[39m".'  '.$result['text'];
        } else {
            $location = $result['path'].':'.($result['line'] + 1);
            $prefixWidth = 1 + mb_strwidth($location) + 2; // ` ` + `path:line` + `  `
            $text = ' '.$this->palette->of(Role::TabAccent).$location."\x1b[39m".'  '.$result['text'];
        }
        $line = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
        // Highlight each match span; close with the row bg so the tail keeps its colour.
        $searchBg = $this->palette->of(Role::SearchBg);
        foreach ($result['ranges'] ?? [] as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
        }

        return $line;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'close' => [Key::ESCAPE],
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'jump' => [Key::ENTER],
            'delete_char_backward' => [Key::BACKSPACE],
            'toggle_all' => ['alt+e'],
            'toggle_group' => ['alt+g'],
            'toggle_context' => ['alt+m'],
            'pin' => ['alt+k'],
            'export' => ['alt+x'],
            'rerun' => [Key::F5],
            'switch_field' => [Key::TAB],
            'insert_newline' => ['shift+enter'],
        ];
    }
}
