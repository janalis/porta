<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\I18n\Translator;
use Porta\Overlay\Region\ModalBox;
use Porta\Search\TokenScope;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ClickZones;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FindModalRows;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\ResultsView;
use Porta\Widget\SearchFieldRenderer;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\ResultGrouping;
use Porta\Widget\Support\ResultLayout;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * The PhpStorm-style Find-in-Files search dialog: a centered modal composited
 * over the editor pane (like {@see OverlayHost}'s Search Everywhere box) holding
 * the query field, scope + mask + option toggles, and a **live grouped results
 * preview** that updates as you type. Enter opens the selected result; Alt+Enter
 * promotes the whole set to the results tool window; Esc cancels.
 *
 * It owns UI state only; the {@see \Porta\Search\ProjectSearchController} wires
 * the callbacks and feeds it results.
 */
final class FindInFilesModal
{
    /** @var array<string, list<string>> the modal's own action bindings (matched with the editor's parser) */
    private const array KEYS = [
        'select_cancel' => [Key::ESCAPE],
        'accept_completion' => [Key::ENTER],
        'open_in_find_window' => ['alt+enter'],
        'cursor_up' => [Key::UP],
        'cursor_down' => [Key::DOWN],
        'delete_char_backward' => [Key::BACKSPACE],
        'switch_field' => [Key::TAB],
        'insert_newline' => ['shift+enter'],
        'cycle_scope' => ['alt+s'],
        'toggle_regex' => ['alt+r'],
        'toggle_case' => ['alt+c'],
        'toggle_word' => ['alt+w'],
        'toggle_preserve' => ['alt+v'], // alt+p's legacy encoding (\x1bp) collides with alt+up (history recall)
        'toggle_tokens' => ['alt+t'],
        'replace_all' => ['alt+a'],
        'toggle_exclude' => [Key::DELETE],
        'history_prev' => ['alt+up'],
        'history_next' => ['alt+down'],
    ];

    private bool $open = false;
    private string $title = '';
    private readonly TextField $query;
    private readonly TextField $replace;
    private readonly TextField $mask;
    /** @var array<int, bool> matchIndex => excluded from replace (preview mode only) */
    private array $excluded = [];
    /** @var list<string> the Alt+S scope choices */
    private array $scopeLabels;
    private int $scopeIndex = 0;
    /** @var array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} */
    private array $options = ['regex' => false, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any'];
    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> */
    private array $results = [];
    /** @var list<\Porta\Widget\Support\DisplayRow> */
    private array $displayRows = [];
    private int $selected = 0;
    /** first visible display-row index; sticky so hover-selection never scrolls the list */
    private int $windowStart = 0;
    /** 'query' | 'replace' | 'mask' — which field typing edits (Tab cycles) */
    private string $activeField = 'query';
    private bool $searching = false;
    /** @var list<string> recent queries, most-recent-first (Alt+↑/↓ recall on the query field) */
    private array $history = [];
    /** cursor into $history while browsing it, or -1 when not */
    private int $historyCursor = -1;

    /** last-rendered geometry, for hit-testing (`windowTop()` is the first preview row) */
    private ?ModalBox $box = null;
    /** Option-tag + scope click zones (columns) on the options row. */
    private readonly ClickZones $optionZones;
    /** Clickable action zones (columns) on the footer row. */
    private readonly ClickZones $footerZones;
    private int $optionsRow = -1;
    private int $footerRowIndex = -1;
    /** hovered header/footer zone name ('regex'|'case'|'word'|'preserve'|'tokens'|'scope'|'find_window'|'footer_scope') or null */
    private ?string $hovered = null;

    /** Whether the title bar's × is under the pointer (brightened like the tab strips'). */
    private bool $closeHovered = false;

    /** Whether the resize corner is under the pointer (heavies like the pane dividers). */
    private bool $cornerHovered = false;

    /** True while a corner drag is resizing the box. */
    private bool $resizing = false;

    /** @var array{rowOffset: int, colOffset: int}|null a title-bar move drag in progress */
    private ?array $moving = null;

    /** User size overrides from a corner drag; null = auto (session-only, reset on open). */
    private ?int $userWidth = null;
    private ?int $userPreviewRows = null;

    /** User position override from a title-bar drag; null = centered (session-only, reset on open). */
    private ?int $userTop = null;
    private ?int $userCol = null;
    /** display-row index tinted under the pointer; visual only, never the selection — you type into the query field while it rests. */
    private ?int $hoveredRow = null;

    /** Outbound hooks into the search controller; replaced wholesale by wiring/tests. */
    private FindModalCallbacks $callbacks;

    private readonly Styles $styles;
    private readonly Translator $translator;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?Translator $translator = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->translator = $translator ?? new Translator('en');
        $this->optionZones = new ClickZones();
        $this->footerZones = new ClickZones();
        $this->callbacks = new FindModalCallbacks(); // a bare modal fires nothing
        $this->scopeLabels = [$this->translator->trans('Whole Project')];
        $this->query = new TextField(style: $cursorStyle);
        $this->replace = new TextField(style: $cursorStyle);
        $this->mask = new TextField(style: $cursorStyle);
    }

    /** Install the controller's outbound hooks (see {@see FindModalCallbacks}). */
    public function setCallbacks(FindModalCallbacks $callbacks): void
    {
        $this->callbacks = $callbacks;
    }

    public function open(string $title): void
    {
        // Session-only, like the Welcome modal's drag position: a reopen
        // re-derives the size from content.
        $this->userWidth = null;
        $this->userPreviewRows = null;
        $this->userTop = null;
        $this->userCol = null;
        $this->moving = null;
        $this->resizing = false;
        $this->cornerHovered = false;
        $this->closeHovered = false;
        $this->open = true;
        $this->title = $title;
        $this->query->clear();
        $this->replace->clear();
        $this->mask->clear();
        $this->activeField = 'query';
        $this->results = [];
        $this->displayRows = [];
        $this->excluded = [];
        $this->selected = 0;
        $this->windowStart = 0;
        $this->searching = false;
        $this->hovered = null;
        $this->hoveredRow = null;
        ($this->invalidate)();
    }

    public function close(): void
    {
        $this->open = false;
        ($this->invalidate)();
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Seed the recent-query list recalled with Alt+↑/↓ on the query field.
     *
     * @param list<string> $history most-recent-first
     */
    public function setHistory(array $history): void
    {
        $this->history = $history;
        $this->historyCursor = -1;
    }

    /** @param list<string> $labels */
    public function setScopes(array $labels, int $index): void
    {
        $this->scopeLabels = [] === $labels ? [$this->translator->trans('Whole Project')] : $labels;
        $this->scopeIndex = $index;
        ($this->invalidate)();
    }

    /** @param array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} $options */
    public function setOptions(array $options): void
    {
        $this->options = $options;
        ($this->invalidate)();
    }

    public function setMaskValue(string $mask): void
    {
        $this->mask->setValue($mask);
        ($this->invalidate)();
    }

    public function setSearching(): void
    {
        $this->searching = true;
        ($this->invalidate)();
    }

    /** @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results */
    public function setPreview(array $results): void
    {
        $this->results = $results;
        $this->displayRows = ResultsView::build($results, [], ResultLayout::Grouped, '', ResultGrouping::File);
        $this->excluded = [];
        $this->searching = false;
        $this->hoveredRow = null; // the rows move under a pointer that has not

        // Land on the first match row so Enter opens it.
        $this->selected = 0;
        $this->windowStart = 0;
        foreach ($this->displayRows as $i => $row) {
            if (null !== $row->filePath) {
                continue;
            }

            $this->selected = $i;

            break;
        }
        ($this->invalidate)();
    }

    /**
     * Handle one key. The editor's keybindings are passed only for their
     * kitty-aware parser; the modal matches against its own action map.
     */
    public function handleInput(string $data, Keybindings $editorKb): void
    {
        $kb = new Keybindings(self::KEYS, $editorKb->getParser());

        if ($kb->matches($data, 'select_cancel')) {
            if (null !== $this->callbacks->onClose) {
                ($this->callbacks->onClose)();
            }

            return;
        }
        if ($kb->matches($data, 'open_in_find_window')) {
            if (null !== $this->callbacks->onOpenInFindWindow) {
                ($this->callbacks->onOpenInFindWindow)();
            }

            return;
        }
        if ($kb->matches($data, 'accept_completion')) {
            $this->jumpSelected();

            return;
        }
        if ('query' === $this->activeField && $kb->matches($data, 'history_prev')) {
            $this->recallHistory(1);

            return;
        }
        if ('query' === $this->activeField && $kb->matches($data, 'history_next')) {
            $this->recallHistory(-1);

            return;
        }
        if ($kb->matches($data, 'cursor_up')) {
            $this->step(-1);

            return;
        }
        if ($kb->matches($data, 'cursor_down')) {
            $this->step(1);

            return;
        }
        if ($kb->matches($data, 'switch_field')) {
            $this->activeField = match ($this->activeField) {
                'query' => 'replace',
                'replace' => 'mask',
                default => 'query',
            };
            ($this->invalidate)();

            return;
        }
        if ($kb->matches($data, 'replace_all')) {
            if (null !== $this->callbacks->onReplaceAll) {
                ($this->callbacks->onReplaceAll)($this->replace->value(), $this->excludedIndices());
            }

            return;
        }
        if ($kb->matches($data, 'toggle_exclude')) {
            if ($this->inPreview()) {
                $this->toggleExcludeSelected();
            }

            return;
        }
        if ($kb->matches($data, 'insert_newline')) {
            $this->activeTextField()->insertNewline();
            $this->notifyField();

            return;
        }
        if ($kb->matches($data, 'cycle_scope')) {
            $this->cycleScope();

            return;
        }
        if ($kb->matches($data, 'toggle_regex')) {
            $this->fireToggle('regex');

            return;
        }
        if ($kb->matches($data, 'toggle_case')) {
            $this->fireToggle('case');

            return;
        }
        if ($kb->matches($data, 'toggle_word')) {
            $this->fireToggle('word');

            return;
        }
        if ($kb->matches($data, 'toggle_preserve')) {
            $this->fireToggle('preserve');

            return;
        }
        if ($kb->matches($data, 'toggle_tokens')) {
            $this->fireToggle('tokens');

            return;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            if ($this->activeTextField()->backspace()) {
                $this->notifyField();
            }

            return;
        }
        if ($this->activeTextField()->insert($data)) {
            $this->notifyField();
        }
    }

    private function activeTextField(): TextField
    {
        return match ($this->activeField) {
            'replace' => $this->replace,
            'mask' => $this->mask,
            default => $this->query,
        };
    }

    /** Advance to the next search scope (Alt+S / a click on the scope label). */
    private function cycleScope(): void
    {
        $this->scopeIndex = ($this->scopeIndex + 1) % \count($this->scopeLabels);
        if (null !== $this->callbacks->onScopeCycle) {
            ($this->callbacks->onScopeCycle)($this->scopeIndex);
        }
        ($this->invalidate)();
    }

    /** Focus a field (a click on its row) and re-render. */
    private function setActiveField(string $field): void
    {
        $this->activeField = $field;
        ($this->invalidate)();
    }

    private function notifyField(): void
    {
        if ('mask' === $this->activeField && null !== $this->callbacks->onMaskChanged) {
            ($this->callbacks->onMaskChanged)($this->mask->value());
        }
        if ('query' === $this->activeField) {
            $this->historyCursor = -1; // typing leaves history-browsing
            if (null !== $this->callbacks->onQuery) {
                ($this->callbacks->onQuery)($this->query->value());
            }
        }
        // Replace edits only re-render the old → new preview; they don't re-search.
        ($this->invalidate)();
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
        if (null !== $this->callbacks->onQuery) {
            ($this->callbacks->onQuery)($this->query->value());
        }
        ($this->invalidate)();
    }

    /** Preview shows the match lines as `old → new` with exclude checkboxes once a replacement is typed. */
    private function inPreview(): bool
    {
        return '' !== $this->replace->value();
    }

    /** @return list<int> the match indices currently excluded from replace-all */
    private function excludedIndices(): array
    {
        $indices = [];
        foreach ($this->excluded as $matchIndex => $isExcluded) {
            if (!$isExcluded) {
                continue;
            }

            $indices[] = $matchIndex;
        }

        return $indices;
    }

    private function toggleExcludeSelected(): void
    {
        $row = $this->displayRows[$this->selected] ?? null;
        if (null !== $row && null === $row->filePath) {
            $this->excluded[$row->matchIndex] = !($this->excluded[$row->matchIndex] ?? false);
            ($this->invalidate)();
        }
    }

    private function fireToggle(string $name): void
    {
        if (null !== $this->callbacks->onToggleOption) {
            ($this->callbacks->onToggleOption)($name);
        }
    }

    /** Scroll the preview by $delta match rows (mouse wheel); like arrow keys, skips headers. */
    public function scrollPreview(int $delta): void
    {
        $dir = $delta <=> 0;
        for ($i = 0, $n = abs($delta); $i < $n; ++$i) {
            $this->step($dir);
        }
    }

    /** Move the selection $dir match rows, skipping headers, clamped to the list. */
    private function step(int $dir): void
    {
        $count = \count($this->displayRows);
        for ($s = 1; $s <= $count; ++$s) {
            $i = $this->selected + ($dir * $s);
            if ($i < 0 || $i >= $count) {
                break;
            }
            if (null === $this->displayRows[$i]->filePath) {
                $this->selected = $i;
                ($this->invalidate)();

                return;
            }
        }
    }

    private function jumpSelected(): void
    {
        $row = $this->displayRows[$this->selected] ?? null;
        if (null !== $row && null === $row->filePath && null !== $this->callbacks->onJump) {
            $result = $this->results[$row->matchIndex];
            ($this->callbacks->onJump)($result['path'], $result['line']);
        }
    }

    /**
     * Lay the modal out inside the paintable screen bounds `[top, left,
     * bottom, right)` — the pane-rect union its presenter computed. Builds the
     * framed panel, positions it (centered until a title-bar drag froze an
     * absolute position), clamps into the bounds, and records the SCREEN-space
     * geometry every hit-test reads. Null while closed. Compositing (the span
     * push, shadow and backdrop) belongs to the presenter — the modal floats
     * over the whole workspace, not inside one pane's lines.
     *
     * @param array{int, int, int, int} $bounds
     *
     * @return array{rows: list<string>, top: int, col: int}|null
     */
    public function layout(array $bounds, CaretVisibility $focused): ?array
    {
        $this->box = null;
        if (!$this->open) {
            return null;
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        $maxPreview = max(1, min(\count($this->displayRows), $rows - 13, $this->userPreviewRows ?? 10));
        $windowStart = $this->windowStart($maxPreview);
        $visible = \array_slice($this->displayRows, $windowStart, $maxPreview);

        $width = $this->boxWidth($columns, $visible);
        $col = $boundLeft + max(0, intdiv($columns - $width - ModalFrame::CHROME_COLS, num2: 2));

        $fields = $this->headerRows($width, $focused);
        // Box rows: title bar(0), pad(1), find(2), replace(3), options(4),
        // mask(5), tee(6), preview from 7 — the sectioned-frame layout.
        $this->optionsRow = ModalFrame::CHROME_ROWS_TOP + 2;
        $previewRows = [];
        foreach ($visible as $i => $row) {
            $selected = match ($windowStart + $i) {
                $this->selected => RowSelection::Selected,
                $this->hoveredRow => RowSelection::Hovered,
                default => RowSelection::Unselected,
            };
            if (null !== $row->filePath) {
                $previewRows[] = FindModalRows::fileHeader($row->filePath, $row->count, $width, $this->styles);

                continue;
            }
            $result = $this->results[$row->matchIndex];
            if ($this->inPreview()) {
                $excluded = $this->excluded[$row->matchIndex] ?? false;
                $previewRows[] = FindModalRows::previewMatch($result, $selected, $excluded, $excluded ? '' : $this->replacementFor($result['text']), $width, $this->styles);

                continue;
            }
            $previewRows[] = FindModalRows::match($result, $selected, $width, $this->styles);
        }
        if ([] === $visible) {
            $previewRows[] = FindModalRows::empty('' === $this->query->value() ? $this->translator->trans('type to search…') : $this->translator->trans('no results'), $width, $this->styles);
        }
        $previewIndex = ModalFrame::CHROME_ROWS_TOP + \count($fields) + 1; // + the tee above the preview
        $footerIndex = $previewIndex + \count($previewRows) + 1; // + the tee above the footer

        $popup = [
            ModalFrame::titleBar($this->title.'  '.$this->statusText(), $width + ModalFrame::CHROME_COLS, $this->styles, $this->closeHovered),
            ...ModalFrame::wrapSections([$fields, $previewRows, [$this->footerRow($width)]], $width, $this->styles, resizeCornerHot: $this->cornerHovered || $this->resizing),
        ];
        // Centered a third down (the pickers' placement) until a title-bar
        // drag freezes an absolute position; either clamps into the bounds —
        // rows outside the pane union would simply not paint.
        $boxWidth = $width + ModalFrame::CHROME_COLS;
        $startRow = $boundTop + max(0, intdiv($rows - \count($popup), num2: 3));
        if (null !== $this->userTop || null !== $this->userCol) {
            $startRow = IntMath::clamp($this->userTop ?? $startRow, $boundTop, max($boundTop, $boundBottom - \count($popup)));
            $col = IntMath::clamp($this->userCol ?? $col, $boundLeft, max($boundLeft, $boundRight - $boxWidth));
            $this->userTop = $startRow;
            $this->userCol = $col;
        }

        $this->optionsRow += $startRow;
        $this->footerRowIndex = $startRow + $footerIndex;
        $this->box = new ModalBox($startRow, $col, $boxWidth, \count($popup), headerRows: $previewIndex, windowStart: $windowStart, count: \count($visible));

        return ['rows' => $popup, 'top' => $startRow, 'col' => $col];
    }

    /**
     * The scrolling window's first display-row index. Sticky: it only moves as far
     * as needed to keep $selected visible (no recentering), so hovering a visible
     * row never shifts the list under the pointer.
     */
    private function windowStart(int $maxPreview): int
    {
        $count = \count($this->displayRows);
        if ($count <= $maxPreview) {
            return $this->windowStart = 0;
        }
        // Scrolling up onto a group's first match also reveals its file-header row.
        $top = $this->selected - (null !== ($this->displayRows[$this->selected - 1] ?? null)?->filePath ? 1 : 0);
        $start = min($this->windowStart, $count - $maxPreview, $top);

        return $this->windowStart = max($start, $this->selected - $maxPreview + 1, 0);
    }

    /**
     * @param list<\Porta\Widget\Support\DisplayRow> $visible
     */
    private function boxWidth(int $columns, array $visible): int
    {
        $status = $this->statusText();
        $width = max(mb_strwidth($this->title) + mb_strwidth($status) + 10, mb_strwidth($this->query->value()) + 9, PopupMetrics::FIND_MIN_WIDTH); // + 10: the frame's title-bar chrome
        foreach ($visible as $row) {
            $text = null !== $row->filePath ? $row->filePath : $this->results[$row->matchIndex]['text'] ?? '';
            $width = max($width, mb_strwidth($text) + 8);
        }

        // Inner width; the frame adds a rail and a padding column each side.
        // A corner-drag override replaces the content-driven width (and its
        // 80-column comfort cap — user intent wins), still floored/clamped.
        if (null !== $this->userWidth) {
            return max(PopupMetrics::FIND_MIN_WIDTH, min($this->userWidth, $columns - PopupMetrics::CENTERED_MARGIN));
        }

        return min($width, $columns - PopupMetrics::CENTERED_MARGIN, 80);
    }

    /**
     * The fixed header rows below the frame's title bar: query, replace,
     * options+scope, mask.
     *
     * @return list<string>
     */
    private function headerRows(int $width, CaretVisibility $focused): array
    {
        $replaceHint = $this->inPreview()
            ? '  '.$this->translator->trans('(Del exclude · Alt+A replace %count%)', ['%count%' => $this->keptCount()])
            : '  '.$this->translator->trans('(Tab · Alt+A)');

        return [
            $this->fieldRow(
                $this->translator->trans('Find'),
                $this->query,
                CaretVisibility::Shown === $focused && 'query' === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden,
                '',
                $width,
            ),
            $this->fieldRow(
                $this->translator->trans('Replace'),
                $this->replace,
                CaretVisibility::Shown === $focused && 'replace' === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden,
                $replaceHint,
                $width,
            ),
            $this->optionsRowLine($width),
            $this->fieldRow(
                $this->translator->trans('Mask'),
                $this->mask,
                CaretVisibility::Shown === $focused && 'mask' === $this->activeField ? CaretVisibility::Shown : CaretVisibility::Hidden,
                '  (Tab)',
                $width,
            ),
        ];
    }

    /** A labelled input row (`Find:`/`Replace:`/`Mask:` + field), scrolled to keep the caret visible. */
    private function fieldRow(string $label, TextField $field, CaretVisibility $shown, string $hint, int $width): string
    {
        return SearchFieldRenderer::row($label, $field, $shown, $hint, $width, Role::Toolbar, $this->styles);
    }

    /** How many matches would be replaced (all minus the excluded). */
    private function keptCount(): int
    {
        $kept = 0;
        foreach (array_keys($this->results) as $matchIndex) {
            if ($this->excluded[$matchIndex] ?? false) {
                continue;
            }

            ++$kept;
        }

        return $kept;
    }

    private function statusText(): string
    {
        return $this->searching ? $this->translator->trans('searching…') : $this->translator->trans('%count% results', ['%count%' => \count($this->results)]);
    }

    /** The option tags + scope label row; records the tag + scope click zones. */
    private function optionsRowLine(int $width): string
    {
        $tokenGlyph = TokenScope::from($this->options['tokens'])->glyph();
        $this->optionZones->clear();
        $col = 1; // leading space
        $tags = '';
        foreach (['regex' => '.*', 'case' => 'Aa', 'word' => '\\b', 'preserve' => 'Pc', 'tokens' => $tokenGlyph] as $name => $glyph) {
            $w = mb_strwidth($glyph) + 2;
            $this->optionZones->add($name, $col, $col + $w);
            $col += $w;
            $on = 'tokens' === $name ? 'any' !== $this->options['tokens'] : $this->options[$name];
            $tags .= FindModalRows::optionTag($glyph, OptionTagState::of($on, $name === $this->hovered), $this->styles);
        }

        $label = $this->scopeLabels[$this->scopeIndex] ?? $this->translator->trans('Whole Project');
        $labelPart = 'scope' === $this->hovered
            ? $this->styles->merge(Role::RowHighlightBg, Role::Bright)->apply($label)
            : $this->styles->of(Role::TabAccent)->apply($label);
        $scope = '  '.$this->styles->of(Role::Dim)->apply($this->translator->trans('Scope:').' ').$labelPart.$this->styles->of(Role::Dim)->apply(' (Alt+S)');
        // The scope zone starts past the two-space gap; clamp so a truncated box can't hit past the edge.
        $this->optionZones->add('scope', $col + 2, min($col + 2 + mb_strwidth($this->translator->trans('Scope:').' '.$label.' (Alt+S)'), $width));

        return $this->styles->of(Role::Toolbar)->apply(AnsiText::truncate(' '.$tags.$scope, $width, pad: true));
    }

    /** The replacement column for a preview row (the raw text when no preview closure is wired). */
    private function replacementFor(string $old): string
    {
        return null !== $this->callbacks->previewReplacement ? ($this->callbacks->previewReplacement)($old, $this->replace->value()) : $old;
    }

    /** The footer hint row; the action words are clickable/hoverable zones. */
    private function footerRow(int $width): string
    {
        $this->footerZones->clear();
        // A hovered label closes its own foreground, so the dim pen must be
        // re-opened explicitly (the row wrap only re-applies the background).
        $dim = $this->styles->of(Role::Dim)->getAnsiRestore();
        $sep = ' · ';
        $line = ' '.$this->translator->trans('⏎ open').$sep;
        $col = mb_strwidth($line);
        foreach ([$this->translator->trans('Find window') => 'find_window', $this->translator->trans('Scope') => 'footer_scope'] as $label => $name) {
            $this->footerZones->add($name, $col, $col + mb_strwidth($label));
            $line .= $name === $this->hovered
                ? $this->styles->merge(Role::RowHighlightBg, Role::Bright)->apply($label).$dim
                : $label;
            $col += mb_strwidth($label);
            if ('footer_scope' !== $name) {
                $line .= $sep;
                $col += mb_strwidth($sep);
            }
        }

        return $this->styles->merge(Role::HeaderBar, Role::Dim)->apply(AnsiText::truncate($line, $width, pad: true));
    }

    /**
     * The last composited box geometry (for mouse hit-testing and tests); the
     * options row sits at `windowTop() - 2`.
     */
    public function lastBox(): ?ModalBox
    {
        return $this->box;
    }

    /** The last composited footer row (for mouse hit-testing and tests). */
    public function lastFooterRow(): int
    {
        return $this->footerRowIndex;
    }

    /**
     * A left-click at editor-pane-relative coords: fields focus, option tags /
     * scope toggle, footer actions fire, preview rows jump (or toggle a match's
     * exclude checkbox), a click outside the box closes.
     */
    public function clickAt(int $relRow, int $relCol): bool
    {
        if (!$this->open) {
            return false;
        }
        $box = $this->box;
        if (null !== $box && $box->resizeHit($relRow, $relCol)) {
            $this->resizing = true;

            return true;
        }
        if (null !== $box && $box->closeHit($relRow, $relCol)) {
            if (null !== $this->callbacks->onClose) {
                ($this->callbacks->onClose)();
            }

            return true;
        }
        if (null !== $box && $relRow === $box->startRow && $box->contains($relRow, $relCol)) {
            // A title-bar press arms a move drag: freeze the centered position
            // into an absolute one, then capture the grab offset.
            $this->userTop = $box->startRow;
            $this->userCol = $box->col;
            $this->moving = ['rowOffset' => $relRow - $box->startRow, 'colOffset' => $relCol - $box->col];

            return true;
        }
        if (null === $box || $relCol < $box->col || $relCol >= ($box->col + $box->width)) {
            if (null !== $this->callbacks->onClose) {
                ($this->callbacks->onClose)();
            }

            return true;
        }
        $innerCol = $box->innerCol($relCol);
        if ($relRow === ($this->optionsRow - 2)) {
            $this->setActiveField('query');

            return true;
        }
        if ($relRow === ($this->optionsRow - 1)) {
            $this->setActiveField('replace');

            return true;
        }
        if ($relRow === ($this->optionsRow + 1)) {
            $this->setActiveField('mask');

            return true;
        }
        if ($relRow === $this->optionsRow) {
            $zone = $this->optionZones->at($innerCol);
            if ('scope' === $zone) {
                $this->cycleScope();

                return true;
            }
            if (null !== $zone) {
                $this->fireToggle($zone);
            }

            return true;
        }
        if ($relRow === $this->footerRowIndex) {
            $this->clickFooter($this->footerZones->at($innerCol));

            return true;
        }
        $index = $this->previewIndexAt($relRow);
        if (null !== $index) {
            $this->selected = $index;
            if ($this->inPreview() && $innerCol >= 2 && $innerCol < 5) {
                $this->toggleExcludeSelected();

                return true;
            }
            $this->jumpSelected();
        }

        return true;
    }

    /**
     * Continue (or finish, on release) whichever drag is armed. A corner
     * resize maps the pointer back to the inner width and the preview-window
     * rows (the box's 11 chrome rows — title, pad, four fields, two tees,
     * footer, pad, border — subtracted), floored at 48×3; a title-bar move
     * tracks it with the grab offset, clamped at the next composite. False
     * when nothing is armed, so the router chain falls through.
     */
    public function continueDragTo(int $relRow, int $relCol, bool $release): bool
    {
        $box = $this->box;
        if (null === $box) {
            return false;
        }
        if ($this->resizing) {
            $this->userWidth = $relCol - $box->col + 1 - ModalFrame::CHROME_COLS;
            $this->userPreviewRows = max(3, $relRow - $box->startRow + 1 - 11);
            if ($release) {
                $this->resizing = false;
            }
            ($this->invalidate)();

            return true;
        }
        $moving = $this->moving;
        if (null === $moving) {
            return false;
        }
        $this->userTop = $relRow - $moving['rowOffset'];
        $this->userCol = $relCol - $moving['colOffset'];
        if ($release) {
            $this->moving = null;
        }
        ($this->invalidate)();

        return true;
    }

    /** A fresh press anywhere disarms a stale drag (the router's cancel sweep). */
    public function cancelDrag(): void
    {
        $this->resizing = false;
        $this->moving = null;
    }

    /** Fire the footer action under a click ('find_window' | 'footer_scope'). */
    private function clickFooter(?string $name): void
    {
        if ('find_window' === $name) {
            if (null !== $this->callbacks->onOpenInFindWindow) {
                ($this->callbacks->onOpenInFindWindow)();
            }

            return;
        }
        if ('footer_scope' === $name) {
            $this->cycleScope();
        }
    }

    /**
     * Hover at editor-pane-relative coords: highlight the option tag / scope /
     * footer action or the preview row under the pointer. Returns whether the
     * highlight changed (so the caller only re-renders on a real move).
     */
    public function hoverAt(int $relRow, int $relCol): bool
    {
        if (!$this->open) {
            return false;
        }
        $box = $this->box;
        $onClose = null !== $box && $box->closeHit($relRow, $relCol);
        $onCorner = null !== $box && $box->resizeHit($relRow, $relCol);
        $closeChanged = $onClose !== $this->closeHovered || $onCorner !== $this->cornerHovered;
        if ($closeChanged) {
            $this->closeHovered = $onClose;
            $this->cornerHovered = $onCorner;
            ($this->invalidate)();
        }
        if (null !== $box && $relRow === $this->optionsRow) {
            return $this->hoverZone($this->optionZones->at($box->innerCol($relCol))) || $closeChanged;
        }
        if (null !== $box && $relRow === $this->footerRowIndex) {
            return $this->hoverZone($this->footerZones->at($box->innerCol($relCol))) || $closeChanged;
        }
        $cleared = $this->hoverZone(null); // moving off the header/footer drops any tag hover
        $index = $this->previewIndexAt($relRow);
        // File headers aren't pickable, so they don't light up.
        $row = null !== $index && null === $this->displayRows[$index]->filePath ? $index : null;
        if ($row !== $this->hoveredRow) {
            $this->hoveredRow = $row;
            ($this->invalidate)();

            return true;
        }

        return $cleared || $closeChanged;
    }

    /** Set the hovered zone; no-op returning false when unchanged, else re-renders. */
    private function hoverZone(?string $name): bool
    {
        if ($name === $this->hovered) {
            return false;
        }
        $this->hovered = $name;
        ($this->invalidate)();

        return true;
    }

    /** The display-row index at a screen row, or null if outside the preview area. */
    private function previewIndexAt(int $relRow): ?int
    {
        $box = $this->box;
        if (null === $box) {
            return null;
        }
        $i = $relRow - $box->windowTop();
        if ($i < 0 || $i >= $box->count) {
            return null;
        }

        return $box->windowStart + $i;
    }
}
