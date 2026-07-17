<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom results panel: project-wide search (Ctrl+Shift+F) and, later,
 * find-usages results. Hidden (zero rows) when closed.
 */
#[AsWidget('results')]
final class ResultsPanelWidget extends OverlayWidget implements FocusableInterface, HoverableList, LtrPane, MenuOverlayHost
{
    use FocusableTrait;
    use MenuOverlayHostTrait;
    use InputHook;
    use KeybindingsTrait;
    use ListPanelInteraction;
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
    /** @var (\Closure(string, int): list<array{line: int, text: string}>)|null neighbouring source lines around a match (path, line) => lines */
    private $contextProvider = null;

    private readonly Styles $styles;
    private readonly Translator $translator;

    public function __construct(?Styles $styles = null, ?CursorStyle $cursorStyle = null, ?Translator $translator = null)
    {
        $this->styles = $styles ?? Styles::dark();
        $this->translator = $translator ?? new Translator('en');
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->query = new TextField(style: $cursorStyle);
        $this->filter = new TextField(style: $cursorStyle);
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

    /** The tool title shown on the dock strip's Results tab. */
    public function getTitle(): string
    {
        return $this->title;
    }

    public function open(string $title, ResultsPanelFields $fields): void
    {
        $this->markOpen();
        $this->withQuery = $fields->query;
        $this->withFilter = $fields->filter;
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
            if (null !== $row->filePath) {
                continue;
            }

            $this->list->select($i);

            break;
        }
        $this->searching = false;
        $this->invalidate();
    }

    /**
     * Recompute the flattened display rows from the current results + collapse
     * state. Drops the hover tint: the rows move under a pointer that has not,
     * so whatever it was tinting is no longer there.
     */
    private function rebuildView(): void
    {
        $this->clearHover();
        $this->displayRows = ResultsView::build(
            $this->results,
            $this->collapsed,
            $this->grouped ? ResultLayout::Grouped : ResultLayout::Flat,
            $this->filter->value(),
            $this->grouping,
            $this->contextExpanded,
            $this->contextCache,
        );
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

                continue;
            }

            $keys[$this->grouping->keyFor($result['path'])] = true;
        }
        $allCollapsed = true;
        foreach (array_keys($keys) as $key) {
            if ($this->collapsed[$key] ?? false) {
                continue;
            }

            $allCollapsed = false;

            break;
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
            $this->dismiss();

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->displayRows))) {
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'jump')) {
            $this->activateRow($this->list->selected());

            return;
        }
        if ($this->grouped && $kb->matches($data, 'toggle_all')) {
            $this->toggleAllGroups();

            return;
        }
        if ($this->grouped && $kb->matches($data, 'toggle_group')) {
            $this->grouping = $this->grouping->next();
            $this->collapsed = [];
            $this->rebuildView();
            $this->invalidate();

            return;
        }
        if ($this->grouped && $kb->matches($data, 'export')) {
            if (null !== $this->onExport) {
                ($this->onExport)();
            }

            return;
        }
        if ($this->grouped && $kb->matches($data, 'toggle_context')) {
            $this->toggleContext($this->list->selected());

            return;
        }
        if ($kb->matches($data, 'rerun')) {
            if (null !== $this->onRerun) {
                ($this->onRerun)();
            }

            return;
        }
        if ($kb->matches($data, 'switch_field') && \count($this->focusableFields()) > 1) {
            $fields = $this->focusableFields();
            $pos = (int) array_search($this->activeField, $fields, strict: true);
            $this->activeField = $fields[($pos + 1) % \count($fields)];
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'insert_newline') && 'query' === $this->activeField) {
            $field = $this->activeTextField();
            if (null !== $field) {
                $field->insertNewline();
                $this->afterFieldEdit();
                $this->invalidate();
            }

            return;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            $field = $this->activeTextField();
            if (null !== $field && $field->backspace()) {
                $this->afterFieldEdit();
                $this->invalidate();
            }

            return;
        }

        $field = $this->activeTextField();
        if (null !== $field && $field->insert($data)) {
            $this->afterFieldEdit();
            $this->invalidate();
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

            return;
        }
        if ('filter' === $this->activeField) {
            $this->rebuildView(); // client-side; no re-grep
        }
    }

    /** Activate a display row: a file header collapses/expands, a match row jumps. */
    protected function activateRow(int $index): void
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
        $expanded = $this->contextExpanded[$matchIndex] ?? false;
        if ($expanded) {
            unset($this->contextExpanded[$matchIndex]);
        }
        if (!$expanded) {
            $this->contextExpanded[$matchIndex] = true;
            if (!array_key_exists($matchIndex, $this->contextCache) && null !== $this->contextProvider) {
                $result = $this->results[$matchIndex];
                $this->contextCache[$matchIndex] = ($this->contextProvider)($result['path'], $result['line']);
            }
        }
        $this->rebuildView();
        $this->invalidate();
    }

    protected function selection(): ListSelection
    {
        return $this->list;
    }

    protected function rowCount(): int
    {
        return \count($this->displayRows);
    }

    /** The title/query row (query modes only), then the filter row (when shown). */
    protected function headerRowCount(): int
    {
        return ($this->withQuery ? 1 : 0) + ($this->withFilter ? 1 : 0);
    }

    public function render(RenderContext $context): array
    {
        if (!$this->isVisible()) {
            return [];
        }

        $columns = $context->getColumns();
        $lines = [];

        // List modes have no header row of their own — the dock strip carries
        // the tool title (tab label) and the status (right side); only the
        // query modes keep their title row, which hosts the query field.
        $showCursor = $this->focused && $this->withQuery;
        if ($this->withQuery) {
            $queryField = $this->query->render(cursor: $showCursor && 'query' === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden);
            $prefix = ' '.$this->title.': '.$queryField.$this->styles->of(Role::Dim)->apply(' '.$this->buildStatus());
            $lines[] = HeaderRenderer::render($prefix, $columns, $this->styles->of(Role::HeaderBar)->withBold());
        }

        if ($this->withFilter) {
            $lines[] = $this->fieldRow(
                $this->translator->trans('Filter'),
                $this->filter,
                $showCursor && 'filter' === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden,
                '  '.$this->translator->trans('(narrows the results below)'),
                $columns,
            );
        }

        $scroll = $this->list->follow($this->visibleRows);

        foreach (\array_slice($this->displayRows, $scroll, $this->visibleRows) as $i => $row) {
            // A raw SGR prefix, not a Style: the match highlighter closes each
            // span with the row bg so the tail keeps its colour. The selection
            // shade comes from the theme's .list::selected(:focus) rules.
            $bg = (match (true) {
                $this->list->isSelected($scroll + $i) => $this->resolveElement('selected'),
                ($scroll + $i) === $this->hoveredIndex => $this->styles->of(Role::RowHighlightBg),
                default => $this->styles->of(Role::Toolbar),
            })->getAnsiRestore();
            $lines[] = match (true) {
                null !== $row->filePath => $this->headerLine($row, $bg, $columns),
                null !== $row->contextText => $this->contextRowLine($row->contextLine, $row->contextText, $bg, $columns),
                default => $this->matchLine($this->results[$row->matchIndex], $bg, $columns),
            };
        }

        if ([] === $this->results && !$this->searching && '' !== $this->query->value()) {
            // '' ellipsis: a fixed short filler row — never worth a truncation mark.
            $lines[] = $this->styles->merge(Role::Toolbar, Role::CompletionHint)->apply(AnsiText::truncate(' '.$this->translator->trans('no results'), $columns, pad: true, ellipsis: ''));
        }

        return $this->withMenuOverlay($lines, $columns);
    }

    /** The dock strip's right-aligned status — empty in query modes, whose own header shows it. */
    public function statusText(): string
    {
        return $this->withQuery ? '' : $this->buildStatus();
    }

    /** 'N results · M shown', or 'searching…' while a search runs. */
    private function buildStatus(): string
    {
        $status = $this->searching ? $this->translator->trans('searching…') : $this->translator->trans('%count% results', ['%count%' => \count($this->results)]);
        if (!$this->searching && '' !== $this->filter->value()) {
            $shown = 0;
            foreach ($this->displayRows as $row) {
                if (null !== $row->filePath) {
                    continue;
                }

                ++$shown;
            }
            $status .= ' · '.$this->translator->trans('%count% shown', ['%count%' => $shown]);
        }

        return $status;
    }

    private function notifyQuery(): void
    {
        if (null !== $this->onQueryChanged) {
            ($this->onQueryChanged)($this->query->value());
        }
    }

    /** A labelled input header row (mask/replace), with a cursor when it is the active field. */
    private function fieldRow(string $label, TextField $field, CaretVisibility $caret, string $hint, int $columns): string
    {
        return SearchFieldRenderer::row($label, $field, $caret, $hint, $columns, Role::RowHighlightBg, $this->styles);
    }

    /** The indent for grouped match rows — deeper in the nested view (below a dir + file header). */
    private function matchIndent(): string
    {
        return ResultGrouping::Nested === $this->grouping ? '      ' : '    ';
    }

    /** A dimmed context row shown around an expanded match: `<line>  text`, indented under the match. */
    private function contextRowLine(int $line, string $text, string $bg, int $columns): string
    {
        $content = $this->matchIndent().'  '.$this->styles->of(Role::Dim)->apply(($line + 1).'  '.$text);

        return $bg.AnsiText::truncate($content, $columns, pad: true).Sgr::RESET;
    }

    /**
     * A collapsible group header `▾ label  (count)`: a file (`path`, or its
     * basename indented under a directory in the nested view) or, in the nested
     * view, a directory (`dir/`).
     */
    private function headerLine(DisplayRow $row, string $bg, int $columns): string
    {
        $path = (string) $row->filePath;
        [$display, $indent] = match (true) {
            $row->isDirectory => [$path.'/', ''],
            ResultGrouping::Nested === $this->grouping => [basename($path), '  '],
            default => [$path, ''],
        };
        $arrow = $row->collapsed ? Glyphs::COLLAPSED : Glyphs::EXPANDED;
        $text = $indent.' '.$arrow.' '.$this->styles->of(Role::TabAccent)->apply($display).$this->styles->of(Role::Dim)->apply('  ('.$row->count.')');

        return $bg.AnsiText::truncate($text, $columns, pad: true).Sgr::RESET;
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
            $text = $indent.$this->styles->of(Role::TabAccent)->apply($label).'  '.$result['text'];

            return $this->highlightMatch($text, $prefixWidth, $result, $bg, $columns);
        }
        if ('' === $result['path']) {
            // Location-less rows (the log tools): plain text, no path accent.
            return $this->highlightMatch(' '.$result['text'], 1, $result, $bg, $columns);
        }
        $location = $result['path'].':'.($result['line'] + 1);
        $prefixWidth = 1 + mb_strwidth($location) + 2; // ` ` + `path:line` + `  `
        $text = ' '.$this->styles->of(Role::TabAccent)->apply($location).'  '.$result['text'];

        return $this->highlightMatch($text, $prefixWidth, $result, $bg, $columns);
    }

    /**
     * Truncate a match row to width and highlight each match span, closing with
     * the row bg so the tail keeps its colour.
     *
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function highlightMatch(string $text, int $prefixWidth, array $result, string $bg, int $columns): string
    {
        return MatchRowDecorator::highlight($text, $columns, $bg, $prefixWidth, $this->styles->of(Role::SearchBg)->getAnsiRestore(), $result['ranges'] ?? []);
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
            'export' => ['alt+x'],
            'rerun' => [Key::F5],
            'switch_field' => [Key::TAB],
            'insert_newline' => ['shift+enter'],
        ];
    }
}
