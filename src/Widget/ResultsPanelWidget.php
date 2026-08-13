<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsChromeRoot;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Event\ResultRowActivated;
use Porta\Event\ResultsExportRequested;
use Porta\Event\ResultsRerunRequested;
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
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\DisplayRow;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\ListPanelInteraction;
use Porta\Widget\Support\PanelRows;
use Porta\Widget\Support\ResultGrouping;
use Porta\Widget\Support\ResultIdentity;
use Porta\Widget\Support\ResultLayout;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Widget\Support\ResultsViewState;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom results panel: project-wide search (Ctrl+Shift+F) and, later,
 * find-usages results. Hidden (zero rows) when closed.
 */
#[AsChromeRoot]
#[AsIdeService]
#[AsWidget('results')]
final class ResultsPanelWidget extends DockPanelWidget implements FocusableInterface, HoverableList, LtrPane, MenuOverlayHost
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use ListPanelInteraction;
    use MenuOverlayHostTrait;
    use PanelRows;
    use StylesDefault;

    private bool $withFilter = false;
    private string $title = '';
    private readonly TextField $filter;
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

    /** @var (\Closure(string, int): list<array{line: int, text: string}>)|null neighbouring source lines around a match (path, line) => lines */
    private $contextProvider = null;

    private readonly Translator $translator;

    public function __construct(
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?Translator $translator = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->translator = $translator ?? new Translator('en');
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->filter = new TextField(style: $cursorStyle);
        $this->visibleRows = 10;
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

    /** The tool title shown on the dock strip's Results tab. */
    public function getTitle(): string
    {
        return $this->title;
    }

    public function open(string $title, ResultsPanelFields $fields): void
    {
        $this->markOpen();
        $this->withFilter = $fields->filter;
        $this->grouped = $fields->grouped;
        $this->grouping = ResultGrouping::File;
        $this->title = $title;
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
        $this->selectFirstMatch();
        $this->searching = false;
        $this->invalidate();
    }

    /** Start on the first match, not the leading file header, so Enter jumps. */
    private function selectFirstMatch(): void
    {
        foreach ($this->displayRows as $i => $row) {
            if (null !== $row->filePath) {
                continue;
            }

            $this->list->select($i);

            break;
        }
    }

    /**
     * Replace the rows of the tab ALREADY on display (a live refresh — new
     * diagnostics, a rerun, a log append) while preserving the user's view:
     * filter, grouping and collapse stay; context re-maps by identity; the
     * selection re-locates by (path, line). A previously location-less
     * selection (log tools, path === '') or one that never located re-selects
     * the first match — for the newest-first logs that IS the contract: the
     * latest line stays visible on every append.
     *
     * @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $rows
     */
    public function refreshResults(array $rows): void
    {
        [$selectedPath, $selectedLine, $selectedGroupKey] = ResultIdentity::ofSelection($this->displayRows[$this->list->selected()] ?? null, $this->results);
        [$expanded, $cache] = ResultIdentity::freezeContext($this->results, $this->contextExpanded, $this->contextCache);

        $this->results = $rows;
        [$this->contextExpanded, $this->contextCache] = ResultIdentity::thawContext($this->results, $expanded, $cache);

        $this->rebuildView();
        if (null !== $selectedPath || null !== $selectedGroupKey) {
            $this->relocateSelection($selectedPath, $selectedLine, $selectedGroupKey, $this->list->selected());
        } else {
            $this->selectFirstMatch();
        }
        $this->searching = false;
        $this->invalidate();
    }

    /**
     * Freeze this tab's view state before the dock rebinds the shared widget
     * to another tab. Selection and context are keyed by (path, line) identity
     * — a parked tab's rows may be rewritten by a live refresh, so positions
     * captured now can be stale at restore ({@see ResultsViewState}).
     */
    public function captureViewState(): ResultsViewState
    {
        [$selectedPath, $selectedLine, $selectedGroupKey] = ResultIdentity::ofSelection($this->displayRows[$this->list->selected()] ?? null, $this->results);
        [$expanded, $cache] = ResultIdentity::freezeContext($this->results, $this->contextExpanded, $this->contextCache);

        return new ResultsViewState(
            $this->filter->value(),
            $this->grouping,
            $this->collapsed,
            $selectedPath,
            $selectedLine,
            $selectedGroupKey,
            $this->list->selected(),
            $expanded,
            $cache,
        );
    }

    /**
     * Re-adopt a frozen view state after {@see open()}/{@see setResults()}
     * rebound this widget to the state's tab. Context entries whose identity
     * no longer matches a result drop; the selection re-locates by identity,
     * then group key, then the clamped stored index.
     */
    public function restoreViewState(ResultsViewState $state): void
    {
        $this->filter->setValue($state->filter);
        $this->grouping = $state->grouping;
        $this->collapsed = $state->collapsed;
        [$this->contextExpanded, $this->contextCache] = ResultIdentity::thawContext($this->results, $state->contextExpanded, $state->contextCache);

        $this->rebuildView();
        $this->relocateSelection($state->selectedPath, $state->selectedLine, $state->selectedGroupKey, $state->selectedIndex);
        $this->invalidate();
    }

    /**
     * Re-locate the selection after the display rows changed: (path, line)
     * identity first, then the group header's key, then the clamped index.
     */
    private function relocateSelection(?string $path, ?int $line, ?string $groupKey, int $fallbackIndex): void
    {
        if (null !== $path && null !== $line) {
            foreach ($this->displayRows as $i => $row) {
                if ($row->matchIndex >= 0 && null !== ($result = $this->results[$row->matchIndex] ?? null) && $result['path'] === $path && $result['line'] === $line) {
                    $this->list->select($i);

                    return;
                }
            }
        }
        if (null !== $groupKey) {
            foreach ($this->displayRows as $i => $row) {
                if ($row->filePath === $groupKey) {
                    $this->list->select($i);

                    return;
                }
            }
        }
        $count = \count($this->displayRows);
        if ($count > 0) {
            $this->list->select(min(max($fallbackIndex, 0), $count - 1));
        }
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

    #[\Override]
    protected function resetState(): void
    {
        $this->results = [];
    }

    #[\Override]
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
            $this->events?->dispatch(new ResultsExportRequested());

            return;
        }
        if ($this->grouped && $kb->matches($data, 'toggle_context')) {
            $this->toggleContext($this->list->selected());

            return;
        }
        if ($kb->matches($data, 'rerun')) {
            $this->events?->dispatch(new ResultsRerunRequested());

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

    /** The filter TextField typing edits, or null when the panel shows no filter. */
    private function activeTextField(): ?TextField
    {
        return $this->withFilter ? $this->filter : null;
    }

    /** After a filter edit: re-narrow the loaded view (client-side; no re-grep). */
    private function afterFieldEdit(): void
    {
        $this->rebuildView();
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
        $this->events?->dispatch(new ResultRowActivated($result['path'], $result['line']));
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

    /** The filter row (when shown); list tools otherwise carry no header row. */
    protected function headerRowCount(): int
    {
        return $this->withFilter ? 1 : 0;
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        if (!$this->isVisible()) {
            return [];
        }

        $columns = $context->getColumns();
        $lines = [];

        // List tools have no header row of their own — the dock strip carries
        // the tool title (tab label) and the status (right side). The filter row
        // is the one exception (the Find tool window narrows its loaded results).
        if ($this->withFilter) {
            $lines[] = $this->fieldRow(
                $this->translator->trans('Filter'),
                $this->filter,
                $this->isFocused() ? CaretVisibility::Shown : CaretVisibility::Hidden,
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

        // Reserve the full configured height even with few/no rows, so the panel
        // always renders a rect the bottom-dock resize grab can land on (an empty
        // results tool stays draggable). A hidden panel returned [] above.
        $blank = $this->styles->of(Role::Toolbar)->getAnsiRestore();
        while (\count($lines) < ($this->headerRowCount() + $this->visibleRows)) {
            $lines[] = AnsiText::padRight($blank, $columns);
        }

        return $this->withMenuOverlay($lines, $columns);
    }

    /** The dock strip's right-aligned status (result/shown counts). */
    #[\Override]
    public function wheelBy(int $rows): void
    {
        $this->scrollBy($rows);
    }

    #[\Override]
    public function clickRow(int $row): void
    {
        $this->handleClick($row);
    }

    #[\Override]
    public function statusText(): string
    {
        return $this->buildStatus();
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

    /** A labelled input header row (mask/replace), with a cursor when it is the active field. */
    private function fieldRow(string $label, TextField $field, CaretVisibility $caret, string $hint, int $columns): string
    {
        return SearchFieldRenderer::row($label, $field, $caret, $hint, $columns, Role::FieldFocusBg, $this->styles);
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
        $searchStyle = $this->styles->of(Role::SearchBg);

        return MatchRowDecorator::highlight($text, $columns, $bg, $prefixWidth, $searchStyle->getAnsiRestore(), Sgr::resetFor($searchStyle), $result['ranges'] ?? []);
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
        ];
    }
}
