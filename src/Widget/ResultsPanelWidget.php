<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Search\TokenScope;
use Porta\Support\AnsiText;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\LtrPane;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\ScopeKind;
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
    private bool $withOptions = false;
    private bool $withReplace = false;
    private bool $withMask = false;
    private bool $withScope = false;
    private bool $withFilter = false;
    private ScopeKind $scopeKind = ScopeKind::Project;
    private string $title = '';
    private readonly TextField $query;
    private readonly TextField $replace;
    private readonly TextField $mask;
    private readonly TextField $filter;
    /** 'query' | 'mask' | 'replace' — which field typing edits; cycled by Tab */
    private string $activeField = 'query';
    /** @var list<string> recent queries, most-recent-first (Alt+↑/↓ recall) */
    private array $history = [];
    /** cursor into $history while browsing it, or -1 when not */
    private int $historyCursor = -1;
    /** @var list<string> recent file masks, most-recent-first (Alt+↑/↓ recall on the Mask field) */
    private array $maskHistory = [];
    /** cursor into $maskHistory while browsing it, or -1 when not */
    private int $maskHistoryCursor = -1;
    private readonly ListSelection $list;
    private bool $searching = false;

    /** @var list<array{name: string, start: int, end: int}> option-tag click zones (columns) from the last render */
    private array $optionZones = [];

    /** @var array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} search toggles shown in the header ('tokens' is a TokenScope value) */
    private array $options = ['regex' => false, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any'];

    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> ranges (search mode only) are display-column match spans */
    private array $results = [];
    private bool $grouped = false;
    private ResultGrouping $grouping = ResultGrouping::File;
    /** @var array<string, bool> group key (file or directory) => collapsed (grouped mode only) */
    private array $collapsed = [];
    /** @var array<int, bool> matchIndex => excluded from replace (preview mode only) */
    private array $excluded = [];
    /** @var array<int, bool> matchIndex => its context lines are expanded (Alt+.) */
    private array $contextExpanded = [];
    /** @var array<int, list<array{line: int, text: string}>> matchIndex => cached neighbouring lines */
    private array $contextCache = [];
    /** @var list<DisplayRow> the flattened rows actually rendered/navigated */
    private array $displayRows = [];

    /** @var (callable(string): void)|null */
    private $onQueryChanged = null;
    /** @var (callable(string): void)|null the file mask changed (re-run the search) */
    private $onMaskChanged = null;
    /** @var (callable(ScopeKind): void)|null the search scope was cycled (Alt+S) */
    private $onScopeCycle = null;
    /** @var (callable(): void)|null re-run the current search (F5) */
    private $onRerun = null;
    /** @var (callable(string, int): void)|null */
    private $onJump = null;
    /** @var (callable(string): void)|null toggled a search option ('regex'|'case'|'word') */
    private $onToggleOption = null;
    /** @var (callable(string, list<int>): void)|null replace kept matches with the given text (excluding the given match indices) */
    private $onReplaceAll = null;
    /** @var (\Closure(string, string): string)|null applies the live query+replacement to a match line for the inline preview */
    private $previewReplacement = null;
    /** @var (callable(): void)|null export the current results to a file (Alt+X) */
    private $onExport = null;
    /** @var (\Closure(string, int): list<array{line: int, text: string}>)|null neighbouring source lines around a match (path, line) => lines */
    private $contextProvider = null;

    private readonly Palette $palette;

    public function __construct(?Palette $palette = null)
    {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->list = new ListSelection();
        $this->query = new TextField();
        $this->replace = new TextField();
        $this->mask = new TextField();
        $this->filter = new TextField();
        $this->visibleRows = 10;
    }

    public function onQueryChanged(callable $callback): self
    {
        $this->onQueryChanged = $callback;

        return $this;
    }

    public function onMaskChanged(callable $callback): self
    {
        $this->onMaskChanged = $callback;

        return $this;
    }

    public function onScopeCycle(callable $callback): self
    {
        $this->onScopeCycle = $callback;

        return $this;
    }

    public function onRerun(callable $callback): self
    {
        $this->onRerun = $callback;

        return $this;
    }

    /** Reflect the active scope kind in the panel (set when it opens). */
    public function setScopeKind(ScopeKind $kind): void
    {
        $this->scopeKind = $kind;
        $this->invalidate();
    }

    /**
     * Seed the recent-query list recalled with Alt+↑/↓ (set when the panel opens).
     *
     * @param list<string> $history most-recent-first
     */
    public function setHistory(array $history): void
    {
        $this->history = $history;
        $this->historyCursor = -1;
    }

    /**
     * Seed the recent-mask list recalled with Alt+↑/↓ on the Mask field.
     *
     * @param list<string> $history most-recent-first
     */
    public function setMaskHistory(array $history): void
    {
        $this->maskHistory = $history;
        $this->maskHistoryCursor = -1;
    }

    /** Set the Mask field's text (restoring a persisted mask when the panel opens). */
    public function setMaskValue(string $mask): void
    {
        $this->mask->setValue($mask);
        $this->invalidate();
    }

    public function onToggleOption(callable $callback): self
    {
        $this->onToggleOption = $callback;

        return $this;
    }

    public function onReplaceAll(callable $callback): self
    {
        $this->onReplaceAll = $callback;

        return $this;
    }

    public function onExport(callable $callback): self
    {
        $this->onExport = $callback;

        return $this;
    }

    /**
     * Set the transformer that renders a match line's `old → new` preview (find-in-project only).
     *
     * @param \Closure(string, string): string $transformer (lineText, replacement) => replacedLine
     */
    public function setPreviewTransformer(\Closure $transformer): void
    {
        $this->previewReplacement = $transformer;
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

    /** @param array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} $options */
    public function setOptions(array $options): void
    {
        $this->options = $options;
        $this->invalidate();
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
        $this->withOptions = $fields->options;
        $this->withReplace = $fields->replace;
        $this->withMask = $fields->mask;
        $this->withScope = $fields->scope;
        $this->withFilter = $fields->filter;
        $this->scopeKind = ScopeKind::Project;
        $this->grouped = $fields->grouped;
        $this->grouping = ResultGrouping::File;
        $this->activeField = 'query';
        $this->title = $title;
        $this->query->clear();
        $this->replace->clear();
        $this->mask->clear();
        $this->filter->clear();
        $this->results = [];
        $this->collapsed = [];
        $this->excluded = [];
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
        $this->excluded = [];
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
            $keys[$this->grouping->keyFor($result['path'])] = true;
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
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_regex')) {
            $this->fireToggle('regex');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_case')) {
            $this->fireToggle('case');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_word')) {
            $this->fireToggle('word');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_preserve')) {
            $this->fireToggle('preserve');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_tokens')) {
            $this->fireToggle('tokens');
        } elseif ($this->withScope && $kb->matches($data, 'cycle_scope')) {
            $this->scopeKind = $this->scopeKind->next();
            if (null !== $this->onScopeCycle) {
                ($this->onScopeCycle)($this->scopeKind);
            }
            $this->invalidate();
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
        } elseif ($this->grouped && $kb->matches($data, 'toggle_context')) {
            $this->toggleContext($this->list->selected());
        } elseif ($kb->matches($data, 'toggle_exclude')) {
            if ($this->inPreview()) {
                $this->toggleExcludeRow($this->list->selected());
            }
        } elseif ($kb->matches($data, 'rerun')) {
            if (null !== $this->onRerun) {
                ($this->onRerun)();
            }
        } elseif ('query' === $this->activeField && $kb->matches($data, 'history_prev')) {
            $this->recallHistory(1);
        } elseif ('query' === $this->activeField && $kb->matches($data, 'history_next')) {
            $this->recallHistory(-1);
        } elseif ('mask' === $this->activeField && $kb->matches($data, 'history_prev')) {
            $this->recallMaskHistory(1);
        } elseif ('mask' === $this->activeField && $kb->matches($data, 'history_next')) {
            $this->recallMaskHistory(-1);
        } elseif ($kb->matches($data, 'switch_field') && \count($this->focusableFields()) > 1) {
            $fields = $this->focusableFields();
            $pos = (int) array_search($this->activeField, $fields, true);
            $this->activeField = $fields[($pos + 1) % \count($fields)];
            $this->invalidate();
        } elseif ($this->withReplace && $kb->matches($data, 'replace_all')) {
            if (null !== $this->onReplaceAll) {
                ($this->onReplaceAll)($this->replace->value(), $this->excludedIndices());
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
        if ($this->withMask) {
            $fields[] = 'mask';
        }
        if ($this->withFilter) {
            $fields[] = 'filter';
        }
        if ($this->withReplace) {
            $fields[] = 'replace';
        }

        return $fields;
    }

    /** The TextField typing currently edits, or null when no field is shown. */
    private function activeTextField(): ?TextField
    {
        return match ($this->activeField) {
            'mask' => $this->withMask ? $this->mask : null,
            'filter' => $this->withFilter ? $this->filter : null,
            'replace' => $this->withReplace ? $this->replace : null,
            default => $this->withQuery ? $this->query : null,
        };
    }

    /** After an edit: query/mask re-run the search, filter re-narrows the view, replace does neither. */
    private function afterFieldEdit(): void
    {
        if ('query' === $this->activeField) {
            $this->historyCursor = -1; // typing leaves history-browsing
            $this->notifyQuery();
        } elseif ('mask' === $this->activeField) {
            $this->maskHistoryCursor = -1; // typing leaves mask-history browsing
            $this->notifyMask();
        } elseif ('filter' === $this->activeField) {
            $this->rebuildView(); // client-side; no re-grep
        }
    }

    /** Walk the recent-mask history into the Mask field: $step +1 = older, -1 = newer. */
    private function recallMaskHistory(int $step): void
    {
        $next = $this->maskHistoryCursor + $step;
        if ($next < -1 || $next >= \count($this->maskHistory)) {
            return; // past either end
        }
        $this->maskHistoryCursor = $next;
        $this->mask->setValue(-1 === $next ? '' : $this->maskHistory[$next]);
        $this->notifyMask();
        $this->invalidate();
    }

    /** Walk the recent-query history into the query field: $step +1 = older, -1 = newer. */
    private function recallHistory(int $step): void
    {
        $next = $this->historyCursor + $step;
        if ($next < -1 || $next >= \count($this->history)) {
            return; // past either end
        }
        $this->historyCursor = $next;
        $this->query->setValue(-1 === $next ? '' : $this->history[$next]);
        $this->notifyQuery();
        $this->invalidate();
    }

    private function notifyMask(): void
    {
        if (null !== $this->onMaskChanged) {
            ($this->onMaskChanged)($this->mask->value());
        }
    }

    /**
     * Route a left-click at the given widget-relative row/col: an option tag in
     * the header toggles it; a file header collapses/expands; a match row jumps.
     */
    public function handleClick(int $relRow, int $relCol): void
    {
        if (0 === $relRow) {
            foreach ($this->optionZones as $zone) {
                if ($relCol >= $zone['start'] && $relCol < $zone['end']) {
                    $this->fireToggle($zone['name']);

                    return;
                }
            }

            return;
        }

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

    /** Replace previews the match lines (checkbox + `old → new`) once a replacement is typed. */
    private function inPreview(): bool
    {
        return $this->withReplace && '' !== $this->replace->value();
    }

    /** Delete on a match row excludes/includes that line; on a file header, toggles all of its matches. */
    private function toggleExcludeRow(int $index): void
    {
        $row = $this->displayRows[$index] ?? null;
        if (null !== $row && null !== $row->filePath) {
            $exclude = !$this->allExcludedForGroup($row->filePath);
            foreach ($this->results as $matchIndex => $result) {
                if ($this->grouping->keyFor($result['path']) === $row->filePath) {
                    $this->excluded[$matchIndex] = $exclude;
                }
            }
            $this->invalidate();
        } elseif (null !== $row && null === $row->contextText) {
            $this->excluded[$row->matchIndex] = !($this->excluded[$row->matchIndex] ?? false);
            $this->invalidate();
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

    /** Whether every match under the group header $key (file or directory) is currently excluded. */
    private function allExcludedForGroup(string $key): bool
    {
        foreach ($this->results as $matchIndex => $result) {
            if ($this->grouping->keyFor($result['path']) === $key && !($this->excluded[$matchIndex] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> the match indices currently excluded from replace-all */
    private function excludedIndices(): array
    {
        $indices = [];
        foreach ($this->excluded as $matchIndex => $isExcluded) {
            if ($isExcluded) {
                $indices[] = $matchIndex;
            }
        }

        return $indices;
    }

    /** How many matches would be replaced (all results minus the excluded ones). */
    private function keptCount(): int
    {
        $kept = 0;
        foreach (array_keys($this->results) as $matchIndex) {
            if (!($this->excluded[$matchIndex] ?? false)) {
                ++$kept;
            }
        }

        return $kept;
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
        // Row 0 is the title/query row; the scope, mask, filter and replace rows follow.
        $headerRows = 1 + ($this->withScope ? 1 : 0) + ($this->withMask ? 1 : 0) + ($this->withFilter ? 1 : 0) + ($this->withReplace ? 1 : 0);
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
        $showCursor = $this->focused && $this->withQuery;
        $queryField = $this->query->render(cursor: $showCursor && 'query' === $this->activeField);
        $prefix = ' '.$this->title.($this->withQuery ? ': '.$queryField : '').$this->palette->of(Role::Dim).$status."\x1b[39m";

        $this->optionZones = [];
        $options = '';
        if ($this->withOptions) {
            // Each tag renders as ' glyph ' (glyph + two padding spaces); the block
            // is prefixed by two spaces. Record each tag's column span for clicks.
            $tokenGlyph = TokenScope::from($this->options['tokens'])->glyph();
            $col = AnsiUtils::visibleWidth($prefix) + 2;
            foreach (['regex' => '.*', 'case' => 'Aa', 'word' => '\\b', 'preserve' => 'Pc', 'tokens' => $tokenGlyph] as $name => $glyph) {
                $width = mb_strwidth($glyph) + 2;
                $this->optionZones[] = ['name' => $name, 'start' => $col, 'end' => $col + $width];
                $col += $width;
            }
            $options =
                '  '
                .$this->optionTag('.*', $this->options['regex'])
                .$this->optionTag('Aa', $this->options['case'])
                .$this->optionTag('\\b', $this->options['word'])
                .$this->optionTag('Pc', $this->options['preserve'])
                .$this->optionTag($tokenGlyph, 'any' !== $this->options['tokens']);
        }

        $header = $prefix.$options;
        $lines[] = HeaderRenderer::render($header, $columns, $this->palette->of(Role::HeaderBar)."\x1b[1m");

        if ($this->withScope) {
            $scopeLine = ' Scope: '.$this->palette->of(Role::TabAccent).$this->scopeKind->label()."\x1b[39m".$this->palette->of(Role::Dim).'  (Alt+S to change)'."\x1b[39m";
            $lines[] = $this->palette->of(Role::RowHighlightBg).AnsiUtils::truncateToWidth($scopeLine, $columns, '…', pad: true)."\x1b[0m";
        }
        if ($this->withMask) {
            $lines[] = $this->fieldRow('Mask', $this->mask, 'mask', $showCursor, '  (e.g. *.php, *.{php,js})', $columns);
        }
        if ($this->withFilter) {
            $lines[] = $this->fieldRow('Filter', $this->filter, 'filter', $showCursor, '  (narrows the results below)', $columns);
        }
        if ($this->withReplace) {
            $replaceHint = $this->inPreview()
                ? \sprintf('  (Del exclude · Alt+A replace %d)', $this->keptCount())
                : '  (Tab switch · Alt+A replace all)';
            $lines[] = $this->fieldRow('Replace', $this->replace, 'replace', $showCursor, $replaceHint, $columns);
        }

        $scroll = $this->list->follow($this->visibleRows);

        foreach (\array_slice($this->displayRows, $scroll, $this->visibleRows) as $i => $row) {
            $isSelected = $this->list->isSelected($scroll + $i);
            $bg = $isSelected ? ($this->focused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::InlayPopupBg)) : $this->palette->of(Role::Toolbar);
            $lines[] = match (true) {
                null !== $row->filePath => $this->fileHeaderLine($row->filePath, $row->count, $row->collapsed, $bg, $columns),
                null !== $row->contextText => $this->contextRowLine($row->contextLine, $row->contextText, $bg, $columns),
                default => $this->matchLine($this->results[$row->matchIndex], $row->matchIndex, $bg, $columns),
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

    /** A dimmed context row shown around an expanded match: `<line>  text`, indented under the match. */
    private function contextRowLine(int $line, string $text, string $bg, int $columns): string
    {
        $content = '      '.$this->palette->of(Role::Dim).(string) ($line + 1).'  '.$text."\x1b[39m";

        return $bg.AnsiUtils::truncateToWidth($content, $columns, '…', pad: true)."\x1b[0m";
    }

    /** A collapsible file-group header: `▾ path  (count)` (grouped mode only). */
    private function fileHeaderLine(string $path, int $count, bool $collapsed, string $bg, int $columns): string
    {
        $arrow = $collapsed ? '▸' : '▾';
        $text = ' '.$arrow.' '.$this->palette->of(Role::TabAccent).$path."\x1b[39m".$this->palette->of(Role::Dim).'  ('.$count.')'."\x1b[39m";

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
    private function matchLine(array $result, int $matchIndex, string $bg, int $columns): string
    {
        if ($this->inPreview()) {
            return $this->previewMatchLine($result, $matchIndex, $bg, $columns);
        }

        if ($this->grouped) {
            $label = $this->groupedLabel($result);
            $prefixWidth = 4 + mb_strwidth($label) + 2; // `    ` + label + `  `
            $text = '    '.$this->palette->of(Role::TabAccent).$label."\x1b[39m".'  '.$result['text'];
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

    /**
     * One match row in replace-preview mode: a `[x]/[ ]` checkbox, the line
     * number, and either the original line (highlighted, when excluded) or
     * `old → new` with the replacement in the "added" colour (when kept).
     *
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function previewMatchLine(array $result, int $matchIndex, string $bg, int $columns): string
    {
        $excluded = $this->excluded[$matchIndex] ?? false;
        $label = $this->groupedLabel($result);
        $old = $result['text'];
        $checkbox = '  '.($excluded ? '[ ]' : '[x]').' ';
        $prefixWidth = mb_strwidth($checkbox) + mb_strwidth($label) + 2; // checkbox + line + `  `
        $head = $checkbox.$this->palette->of(Role::TabAccent).$label."\x1b[39m".'  ';

        if ($excluded) {
            $text = $head.$old.$this->palette->of(Role::Dim).'   (excluded)'."\x1b[39m";
        } else {
            $new = null !== $this->previewReplacement ? ($this->previewReplacement)($old, $this->replace->value()) : $old;
            $text = $head.$old.'  →  '.$this->palette->of(Role::Added).$new."\x1b[39m";
        }

        $line = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
        // Highlight the original match spans so the user sees what is being replaced.
        $searchBg = $this->palette->of(Role::SearchBg);
        foreach ($result['ranges'] ?? [] as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
        }

        return $line;
    }

    private function fireToggle(string $name): void
    {
        if (null !== $this->onToggleOption) {
            ($this->onToggleOption)($name);
        }
    }

    /** Header indicator for one search option, highlighted when enabled. */
    private function optionTag(string $glyph, bool $on): string
    {
        return $on
            ? $this->palette->of(Role::SelectedRow).' '.$glyph." \x1b[0m"
            : $this->palette->of(Role::Faint).' '.$glyph." \x1b[39m";
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
            'toggle_regex' => ['alt+r'],
            'toggle_case' => ['alt+c'],
            'toggle_word' => ['alt+w'],
            'toggle_preserve' => ['alt+p'],
            'toggle_tokens' => ['alt+t'],
            'cycle_scope' => ['alt+s'],
            'toggle_all' => ['alt+e'],
            'toggle_group' => ['alt+g'],
            'toggle_context' => ['alt+m'],
            'toggle_exclude' => [Key::DELETE],
            'export' => ['alt+x'],
            'rerun' => [Key::F5],
            'history_prev' => ['alt+up'],
            'history_next' => ['alt+down'],
            'switch_field' => [Key::TAB],
            'replace_all' => ['alt+a'],
        ];
    }
}
