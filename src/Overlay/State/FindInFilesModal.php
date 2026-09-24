<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\I18n\Translator;
use Porta\Overlay\Region\ModalBox;
use Porta\Overlay\Region\ModalHotspot;
use Porta\Search\SearchOption;
use Porta\Search\TokenScope;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ClickZones;
use Porta\Ui\CursorStyle;
use Porta\Ui\Density;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\FindModalRows;
use Porta\Widget\Render\ModalFrame;
use Porta\Widget\Render\PopupMetrics;
use Porta\Widget\ResultsView;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\ResultGrouping;
use Porta\Widget\Support\ResultLayout;
use Porta\Widget\Support\RowSelection;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
final class FindInFilesModal
{
    use StylesDefault;

    /** @var array<string, list<string>> the modal's own action bindings (matched with the editor's parser) */
    /** The scope field's key hint — measured by the width and drawn by the row, so one spelling. */
    private const string SCOPE_KEY = '(Alt+S)';

    /**
     * The footer's Find-window key hint.
     *
     * Alt+Enter appeared NOWHERE in this modal. Its two footer siblings both
     * said their key — `⏎ open` names Enter, and `Scope` has `(Alt+S)` on the
     * options row above — so it was the one action here you could only find by
     * reading the source.
     */
    private const string FIND_WINDOW_KEY = '(Alt+Enter)';

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
    /**
     * The Alt+S scope carousel: translated choice labels + which one is active.
     *
     * @var list<string>
     */
    private array $scopeLabels;

    private int $scopeIndex = 0;
    /** @var array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} */
    private array $options = ['regex' => false, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any'];
    /** @var list<ResultRow> */
    private array $results = [];
    /** @var list<\Porta\Widget\Support\DisplayRow> */
    private array $displayRows = [];
    private int $selected = 0;
    /** first visible display-row index; sticky so hover-selection never scrolls the list */
    private int $windowStart = 0;
    /** Which field typing edits (Tab cycles) */
    private FindModalField $activeField = FindModalField::Query;
    private bool $searching = false;
    /** @var list<string> recent queries, most-recent-first (Alt+↑/↓ recall on the query field) */
    private array $history = [];
    /** cursor into $history while browsing it, or -1 when not */
    private int $historyCursor = -1;

    /** last-rendered geometry, for hit-testing (`windowTop()` is the first preview row) */
    private ?ModalBox $box = null;
    /** Option-tag click zones (columns) on the options row. */
    private readonly ClickZones $optionZones;
    /**
     * The side column's zone (columns) beside the fields: the Find window
     * button on the Find band, the scope select on the Mask band — which
     * control is which, the band says.
     */
    private readonly ClickZones $sideZones;
    /** hovered zone name ('regex'|'case'|'word'|'preserve'|'tokens'|'find_window') or null */
    private ?string $hovered = null;

    /** The frame affordance under the pointer (× brightens, the corner heavies), or null. */
    private ?ModalHotspot $hotspot = null;

    /** True while a corner drag is resizing the box. */
    private bool $resizing = false;

    /** @var array{rowOffset: int, colOffset: int}|null a title-bar move drag in progress */
    private ?array $moving = null;

    /**
     * User size override from a corner drag; null = auto (session-only, reset
     * on open). $userRows is the preview WINDOW's row count (the frame chrome
     * is not included); a corner drag writes both components together, so one
     * null check covers the pair.
     */
    private ?int $userWidth = null;

    private ?int $userRows = null;

    /**
     * User position override from a title-bar drag; null = centered
     * (session-only, reset on open). A drag writes both components and the
     * composite clamps both, so one null check covers the pair.
     */
    private ?int $userRow = null;

    private ?int $userCol = null;

    /** display-row index tinted under the pointer; visual only, never the selection — you type into the query field while it rests. */
    private ?int $hoveredRow = null;

    /** Outbound hooks into the search controller; replaced wholesale by wiring/tests. */
    private FindModalCallbacks $callbacks;

    private readonly Translator $translator;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
        ?Translator $translator = null,
    ) {
        $this->initStyles($styles);
        $this->translator = $translator ?? new Translator('en');
        $this->optionZones = new ClickZones();
        $this->sideZones = new ClickZones();
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
        $this->userRows = null;
        $this->userRow = null;
        $this->userCol = null;
        $this->moving = null;
        $this->resizing = false;
        $this->hotspot = null;
        $this->open = true;
        $this->title = $title;
        $this->query->clear();
        $this->replace->clear();
        $this->mask->clear();
        $this->activeField = FindModalField::Query;
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

    /** @param list<ResultRow> $results */
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
     * kitty-aware parser; the modal matches against its own action map. The
     * handler map's KEYS drive both membership and dispatch (the
     * EditorActionRouter doctrine); only the two history recalls stay
     * sequential — their query-field condition gates the match itself — and
     * the printable-insert fallback stays last.
     */
    public function handleInput(string $data, Keybindings $editorKb): void
    {
        $kb = new Keybindings(self::KEYS, $editorKb->getParser());

        if (FindModalField::Query === $this->activeField && $kb->matches($data, 'history_prev')) {
            $this->recallHistory(1);

            return;
        }
        if (FindModalField::Query === $this->activeField && $kb->matches($data, 'history_next')) {
            $this->recallHistory(-1);

            return;
        }
        foreach (self::keyHandlers() as $action => $handler) {
            if (!$kb->matches($data, $action)) {
                continue;
            }

            $handler($this);

            return;
        }
        if ($this->activeTextField()->insert($data)) {
            $this->notifyField();
        }
    }

    /**
     * The action table: one closure per bindable key, taking the modal as its
     * parameter so the closures stay static. Kept in the original guard order
     * (binding-disjoint, so only legibility depends on it).
     *
     * @return array<string, \Closure(self): void>
     */
    private static function keyHandlers(): array
    {
        return [
            'select_cancel' => static fn (self $modal) => $modal->fireClose(),
            'open_in_find_window' => static fn (self $modal) => $modal->fireOpenInFindWindow(),
            'accept_completion' => static fn (self $modal) => $modal->jumpSelected(),
            'cursor_up' => static fn (self $modal) => $modal->step(-1),
            'cursor_down' => static fn (self $modal) => $modal->step(1),
            'switch_field' => static function (self $modal): void {
                $modal->activeField = $modal->activeField->next();
                ($modal->invalidate)();
            },
            'replace_all' => static function (self $modal): void {
                if (null !== $modal->callbacks->onReplaceAll) {
                    ($modal->callbacks->onReplaceAll)($modal->replace->value(), $modal->excludedIndices());
                }
            },
            'toggle_exclude' => static function (self $modal): void {
                if ($modal->inPreview()) {
                    $modal->toggleExcludeSelected();
                }
            },
            'insert_newline' => static function (self $modal): void {
                $modal->activeTextField()->insertNewline();
                $modal->notifyField();
            },
            'cycle_scope' => static fn (self $modal) => $modal->openScopeMenu(),
            'toggle_regex' => static fn (self $modal) => $modal->fireToggle(SearchOption::Regex),
            'toggle_case' => static fn (self $modal) => $modal->fireToggle(SearchOption::CaseSensitive),
            'toggle_word' => static fn (self $modal) => $modal->fireToggle(SearchOption::WholeWord),
            'toggle_preserve' => static fn (self $modal) => $modal->fireToggle(SearchOption::PreserveCase),
            'toggle_tokens' => static fn (self $modal) => $modal->fireToggle(SearchOption::Tokens),
            'delete_char_backward' => static function (self $modal): void {
                if ($modal->activeTextField()->backspace()) {
                    $modal->notifyField();
                }
            },
        ];
    }

    /** Esc, the title-bar × and an outside click all leave through here. */
    private function fireClose(): void
    {
        if (null !== $this->callbacks->onClose) {
            ($this->callbacks->onClose)();
        }
    }

    /** Alt+Enter and the footer's "Find window" zone both promote through here. */
    private function fireOpenInFindWindow(): void
    {
        if (null !== $this->callbacks->onOpenInFindWindow) {
            ($this->callbacks->onOpenInFindWindow)();
        }
    }

    private function activeTextField(): TextField
    {
        return match ($this->activeField) {
            FindModalField::Query => $this->query,
            FindModalField::Replace => $this->replace,
            FindModalField::Mask => $this->mask,
        };
    }

    /**
     * Drop the scope select's list (Alt+S, or a click on the select) under
     * the select, in screen space; the pick comes back through
     * {@see chooseScope()}. With no host to show a list — or before the
     * modal has been drawn — the scope steps to the next, so the key still
     * moves it.
     */
    private function openScopeMenu(): void
    {
        $box = $this->box;
        $menu = $this->callbacks->onScopeMenu;
        if (null === $box || null === $menu) {
            $this->chooseScope(($this->scopeIndex + 1) % \max(1, \count($this->scopeLabels)));

            return;
        }
        $top = $box->optionsRow + self::fieldHeight($box); // the mask band, below the options'
        $side = $this->sideWidthFor($box->width - ModalFrame::CHROME_COLS);
        $col = $box->col + $box->width - (ModalFrame::CHROME_COLS / 2) - $side;
        $menu($this->scopeLabels, $this->scopeIndex, $top + self::fieldHeight($box), $col, $top, $side);
    }

    /** Search the scope at $index (a pick from the scope list, or a step). */
    public function chooseScope(int $index): void
    {
        $this->scopeIndex = $index;
        if (null !== $this->callbacks->onScopeChosen) {
            ($this->callbacks->onScopeChosen)($this->scopeIndex);
        }
        ($this->invalidate)();
    }

    /** Focus a field (a click on its row) and re-render. */
    private function setActiveField(FindModalField $field): void
    {
        $this->activeField = $field;
        ($this->invalidate)();
    }

    private function notifyField(): void
    {
        if (FindModalField::Mask === $this->activeField && null !== $this->callbacks->onMaskChanged) {
            ($this->callbacks->onMaskChanged)($this->mask->value());
        }
        if (FindModalField::Query === $this->activeField) {
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

    private function fireToggle(SearchOption $option): void
    {
        if (null !== $this->callbacks->onToggleOption) {
            ($this->callbacks->onToggleOption)($option);
        }
    }

    /** Scroll the preview by $delta match rows (mouse wheel); like arrow keys, skips headers. */
    public function scrollPreview(int $delta): void
    {
        $dir = $delta <=> 0;
        for ($i = 0, $n = \abs($delta); $i < $n; ++$i) {
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
    public function layout(array $bounds, CaretVisibility $focused, Density $density): ?array
    {
        $this->box = null;
        if (!$this->open) {
            return null;
        }
        [$boundTop, $boundLeft, $boundBottom, $boundRight] = $bounds;
        $columns = $boundRight - $boundLeft;
        $rows = $boundBottom - $boundTop;

        // On a compact screen the three boxed fields would eat the preview:
        // each takes its one-row form ({@see FieldBox::rowsFor()}). Hit-testing
        // recovers the height drawn from the box's own geometry
        // ({@see fieldHeight()}).
        $fieldRows = FieldBox::rowsFor($density);
        // Everything but the preview: the frame and its tees (8), the footer
        // (1), and the three fields and the options band, each $fieldRows.
        $maxPreview = \max(1, \min(\count($this->displayRows), $rows - 9 - (4 * $fieldRows), $this->userRows ?? 10));
        $windowStart = $this->windowStart($maxPreview);
        $visible = \array_slice($this->displayRows, $windowStart, $maxPreview);

        $width = $this->boxWidth($columns, $visible, $fieldRows);
        $col = $boundLeft + \max(0, \intdiv($columns - $width - ModalFrame::CHROME_COLS, num2: 2));

        $fields = $this->headerRows($width, $focused, $fieldRows);
        // Box rows: title bar, pad, find, replace, options, mask, tee, then
        // the preview — each field $fieldRows tall (the sectioned-frame layout).
        $optionsIndex = ModalFrame::CHROME_ROWS_TOP + (2 * $fieldRows);
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
                $previewRows[] = FindModalRows::previewMatch(
                    $result,
                    $selected,
                    $excluded ? null : $this->replacementFor($result['text']),
                    $this->translator->trans('(excluded)'),
                    $width,
                    $this->styles,
                    self::BOX_ZONE === $this->hovered && ($windowStart + $i) === $this->hoveredRow,
                );

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
            ModalFrame::titleBar($this->title.'  '.$this->statusText(), $width + ModalFrame::CHROME_COLS, $this->styles, ModalHotspot::Close === $this->hotspot),
            ...ModalFrame::wrapSections([$fields, $previewRows, [$this->footerRow($width)]], $width, $this->styles, resizeCornerHot: ModalHotspot::ResizeCorner === $this->hotspot || $this->resizing),
        ];
        // Centered a third down (the pickers' placement) until a title-bar
        // drag freezes an absolute position; either clamps into the bounds —
        // rows outside the pane union would simply not paint.
        $boxWidth = $width + ModalFrame::CHROME_COLS;
        $startRow = $boundTop + \max(0, \intdiv($rows - \count($popup), num2: 3));
        if (null !== $this->userRow && null !== $this->userCol) {
            $startRow = IntMath::clamp($this->userRow, $boundTop, \max($boundTop, $boundBottom - \count($popup)));
            $col = IntMath::clamp($this->userCol, $boundLeft, \max($boundLeft, $boundRight - $boxWidth));
            $this->userRow = $startRow;
            $this->userCol = $col;
        }

        $this->box = new ModalBox(
            $startRow,
            $col,
            $boxWidth,
            \count($popup),
            headerRows: $previewIndex,
            windowStart: $windowStart,
            count: \count($visible),
            optionsRow: $startRow + $optionsIndex,
            footerRow: $startRow + $footerIndex,
        );

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
        $start = \min($this->windowStart, $count - $maxPreview, $top);

        return $this->windowStart = \max($start, $this->selected - $maxPreview + 1, 0);
    }

    /**
     * @param list<\Porta\Widget\Support\DisplayRow> $visible
     */
    private function boxWidth(int $columns, array $visible, int $fieldRows): int
    {
        $status = $this->statusText();
        $width = \max(\mb_strwidth($this->title) + \mb_strwidth($status) + 10, \mb_strwidth($this->query->value()) + 9 + FieldBox::CHROME_COLS, PopupMetrics::FIND_MIN_WIDTH); // + 10: the frame's title-bar chrome
        // Room for the Replace hint beside a usable compact value: it carries
        // the replace count, so the box grows to seat it rather than drop it.
        $label = \max(\array_map(AnsiUtils::visibleWidth(...), $this->labels(1)));
        // The side column (Find window, scope) sits beside every field band,
        // and a query needs room to be read, not just to exist: twice a
        // box's minimum, beside the padded hint column of the compact form.
        $width = \max(
            $width,
            $this->optionsRowWidth($fieldRows),
            $this->footerRowWidth(),
            $label + 1 + (2 * FieldBox::MIN_BOXED) + 1 + AnsiUtils::visibleWidth($this->replaceHint()) + 1 + $this->sideWidth(),
        );
        foreach ($visible as $row) {
            $text = $row->filePath ?? $this->results[$row->matchIndex]['text'] ?? '';
            $width = \max($width, \mb_strwidth($text) + 8);
        }

        // Inner width; the frame adds a rail and a padding column each side.
        // A corner-drag override replaces the content-driven width (and its
        // 80-column comfort cap — user intent wins), still floored/clamped.
        if (null !== $this->userWidth) {
            return \max(PopupMetrics::FIND_MIN_WIDTH, \min($this->userWidth, $columns - PopupMetrics::CENTERED_MARGIN));
        }

        return \min($width, $columns - PopupMetrics::CENTERED_MARGIN, 80);
    }

    /**
     * The fixed header rows below the frame's title bar, laid out as a form:
     * the query, replace, options and mask rows — each field boxed or compact
     * per $fieldRows — with a side column beside the fields: the Find window
     * button next to Find and the scope select next to Mask, a blank beside
     * Replace so the fields' rails stand in one column.
     *
     * @param positive-int $fieldRows
     *
     * @return list<string>
     */
    private function headerRows(int $width, CaretVisibility $focused, int $fieldRows): array
    {
        [$find, $replace, $mask] = $this->labels($fieldRows);
        [$findHint, $replaceHint, $maskHint] = $this->hints($fieldRows);
        $side = $this->sideWidthFor($width);
        $fieldWidth = \max(0, $width - $side - 1);
        $this->sideZones->clear();
        $this->sideZones->add('side', $fieldWidth + 1, $width);
        $toolbar = $this->styles->of(Role::Toolbar);
        $boxed = FieldBox::ROWS === $fieldRows;

        $findWindow = \array_map(
            $toolbar->apply(...),
            FieldBox::button($this->translator->trans('Find window'), FieldState::Idle, 'find_window' === $this->hovered, $side, $fieldRows, $this->styles, $boxed ? self::FIND_WINDOW_KEY : ''),
        );
        $scope = FieldBox::select(
            new FieldSpec($boxed ? $this->translator->trans('Scope') : '', trailing: $boxed ? self::SCOPE_KEY : '', surface: Role::Toolbar),
            $this->scopeLabel(),
            FieldState::Idle,
            $side,
            $fieldRows,
            $this->styles,
        );

        return [
            ...$this->beside($this->fieldRows(new FieldSpec($find, trailing: $findHint, surface: Role::Toolbar), $this->query, FindModalField::Query, $focused, $fieldWidth, $fieldRows), $findWindow),
            ...$this->beside(
                $this->fieldRows(new FieldSpec($replace, trailing: $replaceHint, surface: Role::Toolbar), $this->replace, FindModalField::Replace, $focused, $fieldWidth, $fieldRows),
                \array_fill(0, \max(1, $fieldRows), $toolbar->apply(AnsiText::blank($side))),
            ),
            ...$this->optionsRows($width, $fieldRows),
            ...$this->beside($this->fieldRows(new FieldSpec($mask, trailing: $maskHint, surface: Role::Toolbar), $this->mask, FindModalField::Mask, $focused, $fieldWidth, $fieldRows), $scope),
        ];
    }

    /**
     * A field band's rows with its side control beside them, a gutter between.
     *
     * @param list<string> $field
     * @param list<string> $side
     *
     * @return list<string>
     */
    private function beside(array $field, array $side): array
    {
        $gutter = $this->styles->of(Role::Toolbar)->apply(' ');

        return \array_map(static fn (string $left, string $right): string => $left.$gutter.$right, $field, $side);
    }

    /** The side column's natural width: the wider of the Find window button and the scope select, over every scope. */
    private function sideWidth(): int
    {
        return \max(FieldBox::controlWidth($this->translator->trans('Find window')), FieldBox::selectWidth($this->scopeLabels));
    }

    /**
     * The side column's width in a box $width wide: natural where it fits, and
     * never more than half the box beyond the button's own need — the
     * fields keep their room, and a long scope name elides inside its select
     * instead of squeezing the query to nothing.
     */
    private function sideWidthFor(int $width): int
    {
        return \min($this->sideWidth(), \max(FieldBox::controlWidth($this->translator->trans('Find window')), \intdiv($width, num2: 2)));
    }

    /**
     * The three fields' hints (Find has none). Compact, a hint follows its
     * field's closing rail, so they are padded to one width: every value gets
     * the same room, the rails line up in a column and the hints in another
     * — unpadded, each rail stopped where its own hint began. Boxed, each
     * hint sits in its own border and is left as it is.
     *
     * @return array{string, string, string}
     */
    private function hints(int $fieldRows): array
    {
        $hints = ['', $this->replaceHint(), $this->translator->trans('(Tab)')];

        return FieldBox::ROWS === $fieldRows ? $hints : self::toOneWidth($hints);
    }

    /** The Replace field's hint: what Alt+A will do, and to how many matches once previewing. */
    private function replaceHint(): string
    {
        return (
            $this->inPreview()
                ? $this->translator->trans('(Del exclude · Alt+A replace %count%)', ['%count%' => $this->keptCount()])
                : $this->translator->trans('(Tab · Alt+A)')
        );
    }

    /**
     * The three field labels. Compact, they sit on the rows beside their
     * values, so they are padded to one width and the rails line up in a
     * column, as a form's inputs do; boxed, each is its own box's legend.
     *
     * @return array{string, string, string}
     */
    private function labels(int $fieldRows): array
    {
        $labels = [$this->translator->trans('Find'), $this->translator->trans('Replace'), $this->translator->trans('Mask')];

        return FieldBox::ROWS === $fieldRows ? $labels : self::toOneWidth($labels);
    }

    /**
     * @param array{string, string, string} $texts
     *
     * @return array{string, string, string} each right-padded to the widest
     */
    private static function toOneWidth(array $texts): array
    {
        $width = \max(\array_map(AnsiUtils::visibleWidth(...), $texts));

        return [AnsiText::padRight($texts[0], $width), AnsiText::padRight($texts[1], $width), AnsiText::padRight($texts[2], $width)];
    }

    /**
     * One field's rows: it takes the keys when the modal is focused and it is
     * the active field — the one caret this frame carries.
     *
     * @return list<string>
     */
    private function fieldRows(FieldSpec $spec, TextField $field, FindModalField $which, CaretVisibility $focused, int $width, int $height): array
    {
        $active = CaretVisibility::Shown === $focused && $which === $this->activeField;
        $caret = $active ? CaretVisibility::Shown : CaretVisibility::Hidden;

        return FieldBox::field($spec, $field, FieldState::of($active), $caret, $width, $height, $this->styles);
    }

    /**
     * The field whose band holds a row $offset from the options row: Find
     * and Replace stacked above it, Mask below, each {@see fieldHeight()} tall.
     */
    private function fieldAt(ModalBox $box, int $offset): ?FindModalField
    {
        $height = self::fieldHeight($box);

        return match (true) {
            $offset >= (-2 * $height) && $offset < -$height => FindModalField::Query,
            $offset >= -$height && $offset < 0 => FindModalField::Replace,
            $offset >= $height && $offset < (2 * $height) => FindModalField::Mask,
            default => null,
        };
    }

    /** Whether $relRow falls in the options band, which is a field's height: a toggle answers on any of its rows. */
    private static function onOptions(ModalBox $box, int $relRow): bool
    {
        return $relRow >= $box->optionsRow && $relRow < ($box->optionsRow + self::fieldHeight($box));
    }

    /** One field's height as laid out: Find and Replace fill the rows between the frame's top chrome and the options band. */
    private static function fieldHeight(ModalBox $box): int
    {
        return \intdiv($box->optionsRow - $box->startRow - ModalFrame::CHROME_ROWS_TOP, num2: 2);
    }

    /** How many matches would be replaced (all minus the excluded). */
    private function keptCount(): int
    {
        $kept = 0;
        foreach (\array_keys($this->results) as $matchIndex) {
            if ($this->excluded[$matchIndex] ?? false) {
                continue;
            }

            ++$kept;
        }

        return $kept;
    }

    private function statusText(): string
    {
        return $this->searching ? $this->translator->trans('searching…') : $this->translator->trans('%count% result|%count% results', ['%count%' => \count($this->results)]);
    }

    /**
     * The option tags in row order, name => glyph.
     *
     * One list, read by the row that draws them and by the width that has to
     * seat them — two copies of a fixed five-cell run is two things to get out
     * of step, and the duplication gate says so.
     *
     * @return array<string, string>
     */
    private function optionTags(): array
    {
        return ['regex' => '.*', 'case' => 'Aa', 'word' => '\\b', 'preserve' => 'Pc', 'tokens' => TokenScope::from($this->options['tokens'])->glyph()];
    }

    /**
     * The options row's natural width: the five tags.
     *
     * {@see boxWidth()} counts it because this row is the only one in the modal
     * that cannot shrink — a preview row elides its text and the query field
     * scrolls, but the tags are five fixed cells and the key hint is the whole
     * point of the row. Left out of the width it was, reliably, the row that
     * overflowed, and the row-wide truncate spent the overflow on the tail:
     * `(Alt+S)` rendered as `(Al…`, hiding the only thing that says how to
     * change the scope.
     */
    private function optionsRowWidth(int $fieldRows): int
    {
        $tags = 0;
        foreach ($this->optionTags() as $glyph) {
            $tags += FieldBox::toggleWidth($glyph, $fieldRows);
        }

        return 1 + $tags;
    }

    /** The scope the modal is currently searching. */
    private function scopeLabel(): string
    {
        return $this->scopeLabels[$this->scopeIndex] ?? $this->translator->trans('Whole Project');
    }

    /**
     * The option toggles' band, as tall as a field — boxed beside boxed
     * fields — and records their click zones, which span the band.
     *
     * @param positive-int $fieldRows
     *
     * @return list<string>
     */
    private function optionsRows(int $width, int $fieldRows): array
    {
        $this->optionZones->clear();
        $col = 1; // leading space
        $rows = \array_fill(0, $fieldRows, value: ' ');
        foreach ($this->optionTags() as $name => $glyph) {
            $w = FieldBox::toggleWidth($glyph, $fieldRows);
            $this->optionZones->add($name, $col, $col + $w);
            $col += $w;
            $on = 'tokens' === $name ? 'any' !== $this->options['tokens'] : true === $this->options[$name];
            foreach (FieldBox::toggle($glyph, OptionTagState::of($on, $name === $this->hovered), $fieldRows, $this->styles) as $i => $line) {
                $rows[$i] .= $line;
            }
        }

        return \array_map(fn (string $row): string => $this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($row, $width, pad: true)), $rows);
    }

    /** The replacement column for a preview row (the raw text when no preview closure is wired). */
    private function replacementFor(string $old): string
    {
        return null !== $this->callbacks->previewReplacement ? ($this->callbacks->previewReplacement)($old, $this->replace->value()) : $old;
    }

    /**
     * The footer: a key legend, not a row of buttons — the Find window and the
     * scope are controls of their own now, beside the fields they belong to.
     * It still names their keys: a compact button has no border to carry one.
     */
    private function footerLegend(): string
    {
        return ' '.$this->translator->trans('⏎ open').' · Alt+Enter '.$this->translator->trans('Find window').' · Alt+S '.$this->translator->trans('Scope').' · Esc';
    }

    /**
     * The footer's natural width, counted by {@see boxWidth()} for the reason
     * {@see optionsRowWidth()} is: a fixed row that cannot shrink is, left out
     * of the width, a row that silently truncates.
     */
    private function footerRowWidth(): int
    {
        return \mb_strwidth($this->footerLegend());
    }

    private function footerRow(int $width): string
    {
        return $this->styles->merge(Role::HeaderBar, Role::Dim)->apply(AnsiText::truncate($this->footerLegend(), $width, pad: true));
    }

    /**
     * The last composited box geometry (for mouse hit-testing and tests); the
     * options row sits at `windowTop() - 3`.
     */
    public function lastBox(): ?ModalBox
    {
        return $this->box;
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
            $this->fireClose();

            return true;
        }
        if (null !== $box && $relRow === $box->startRow && $box->contains($relRow, $relCol)) {
            // A title-bar press arms a move drag: freeze the centered position
            // into an absolute one, then capture the grab offset.
            $this->userRow = $box->startRow;
            $this->userCol = $box->col;
            $this->moving = ['rowOffset' => $relRow - $box->startRow, 'colOffset' => $relCol - $box->col];

            return true;
        }
        if (null === $box || $relCol < $box->col || $relCol >= ($box->col + $box->width)) {
            $this->fireClose();

            return true;
        }
        $innerCol = $box->innerCol($relCol);
        $field = $this->fieldAt($box, $relRow - $box->optionsRow);
        if (null !== $field) {
            $this->clickBand($field, null !== $this->sideZones->at($innerCol));

            return true;
        }
        if (self::onOptions($box, $relRow)) {
            $zone = $this->optionZones->at($innerCol);
            $option = null === $zone ? null : SearchOption::tryFrom($zone);
            if (null !== $option) {
                $this->fireToggle($option);
            }

            return true;
        }
        if ($relRow === $box->footerRow) {
            return true; // a key legend: nothing on it to press
        }
        $index = $box->itemAt($relRow, $relCol);
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
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    public function continueDragTo(int $relRow, int $relCol, bool $release): bool
    {
        $box = $this->box;
        if (null === $box) {
            return false;
        }
        if ($this->resizing) {
            $this->userWidth = $relCol - $box->col + 1 - ModalFrame::CHROME_COLS;
            $this->userRows = \max(3, $relRow - $box->startRow + 1 - 11);
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
        $this->userRow = $relRow - $moving['rowOffset'];
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

    /**
     * A click in a field band: on the side column it presses that band's
     * control — the Find window button, the scope select — elsewhere it
     * focuses the band's field. Beside Replace the side column is blank, so
     * the click focuses Replace.
     */
    private function clickBand(FindModalField $field, bool $side): void
    {
        match (true) {
            $side && FindModalField::Query === $field => $this->fireOpenInFindWindow(),
            $side && FindModalField::Mask === $field => $this->openScopeMenu(),
            default => $this->setActiveField($field),
        };
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
        $hotspot = match (true) {
            null !== $box && $box->closeHit($relRow, $relCol) => ModalHotspot::Close,
            null !== $box && $box->resizeHit($relRow, $relCol) => ModalHotspot::ResizeCorner,
            default => null,
        };
        $closeChanged = $hotspot !== $this->hotspot;
        if ($closeChanged) {
            $this->hotspot = $hotspot;
            ($this->invalidate)();
        }
        if (null !== $box && self::onOptions($box, $relRow)) {
            return $this->hoverZone($this->optionZones->at($box->innerCol($relCol))) || $closeChanged;
        }
        if (null !== $box && FindModalField::Query === $this->fieldAt($box, $relRow - $box->optionsRow) && null !== $this->sideZones->at($box->innerCol($relCol))) {
            return $this->hoverZone('find_window') || $closeChanged; // the button under the pointer
        }
        $index = $this->previewIndexAt($relRow);
        // File headers aren't pickable, so they don't light up.
        $row = null !== $index && null === $this->displayRows[$index]->filePath ? $index : null;
        // The box, where a click toggles rather than jumps (the click's own
        // columns), answers the pointer on its own: one more hover zone,
        // on the hovered row. Anywhere else drops the tags' and button's.
        $onBox = null !== $row && null !== $box && $this->inPreview() && $box->innerCol($relCol) >= 2 && $box->innerCol($relCol) < 5;
        $cleared = $this->hoverZone($onBox ? self::BOX_ZONE : null);
        if ($row !== $this->hoveredRow) {
            $this->hoveredRow = $row;
            ($this->invalidate)();

            return true;
        }

        return $cleared || $closeChanged;
    }

    /** Set the hovered zone; no-op returning false when unchanged, else re-renders. */
    /** The hover zone of the hovered preview row's box. */
    private const string BOX_ZONE = 'exclude_box';

    private function hoverZone(?string $name): bool
    {
        if ($name === $this->hovered) {
            return false;
        }
        $this->hovered = $name;
        ($this->invalidate)();

        return true;
    }

    /**
     * The display-row index at a screen row, or null if outside the preview
     * area. Deliberately NOT {@see ModalBox::itemAt}: hover arrives with no
     * column pre-gate (HoverRouter routes whole-screen moves while the modal
     * is open), and a pointer horizontally beside the box must still tint the
     * row at that height — itemAt's column guard would change that. clickAt
     * uses itemAt (its outside-click guard has already consumed off-box
     * columns).
     */
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
