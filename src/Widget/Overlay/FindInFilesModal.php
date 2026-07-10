<?php

declare(strict_types=1);

namespace Porta\Widget\Overlay;

use Porta\Search\TokenScope;
use Porta\Support\AnsiText;
use Porta\Ui\ColorPalette;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\OverlayCompositor;
use Porta\Widget\ResultGrouping;
use Porta\Widget\ResultsView;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\CursorShape;

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
    private array $scopeLabels = ['Whole Project'];
    private int $scopeIndex = 0;
    /** @var array{regex: bool, case: bool, word: bool, preserve: bool, tokens: string} */
    private array $options = ['regex' => false, 'case' => false, 'word' => false, 'preserve' => false, 'tokens' => 'any'];
    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> */
    private array $results = [];
    /** @var list<\Porta\Widget\DisplayRow> */
    private array $displayRows = [];
    private int $selected = 0;
    /** 'query' | 'replace' | 'mask' — which field typing edits (Tab cycles) */
    private string $activeField = 'query';
    private bool $searching = false;
    /** @var list<string> recent queries, most-recent-first (Alt+↑/↓ recall on the query field) */
    private array $history = [];
    /** cursor into $history while browsing it, or -1 when not */
    private int $historyCursor = -1;

    /** @var array{col: int, width: int, previewRow: int, windowStart: int, count: int}|null last-rendered geometry, for hit-testing */
    private ?array $box = null;
    /** @var list<array{name: string, start: int, end: int}> option-tag + scope click zones (columns) on the options row */
    private array $optionZones = [];
    /** @var list<array{name: string, start: int, end: int}> clickable action zones (columns) on the footer row */
    private array $footerZones = [];
    private int $optionsRow = -1;
    private int $footerRowIndex = -1;
    /** hovered header/footer zone name ('regex'|'case'|'word'|'preserve'|'tokens'|'scope'|'find_window'|'footer_scope'|'close') or null */
    private ?string $hovered = null;

    /** @var (callable(string): void)|null */
    private $onQuery = null;
    /** @var (callable(string): void)|null */
    private $onMaskChanged = null;
    /** @var (callable(int): void)|null the scope choice index */
    private $onScopeCycle = null;
    /** @var (callable(string): void)|null option short key */
    private $onToggleOption = null;
    /** @var (callable(string, int): void)|null path, line */
    private $onJump = null;
    /** @var (callable(): void)|null */
    private $onOpenInFindWindow = null;
    /** @var (callable(string, list<int>): void)|null replace kept matches (excluding the given indices) */
    private $onReplaceAll = null;
    /** @var (\Closure(string, string): string)|null renders a match line's old → new preview */
    private $previewReplacement = null;
    /** @var (callable(): void)|null */
    private $onClose = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->query = new TextField();
        $this->replace = new TextField();
        $this->mask = new TextField();
    }

    public function onQuery(callable $cb): self
    {
        $this->onQuery = $cb;

        return $this;
    }

    public function onMaskChanged(callable $cb): self
    {
        $this->onMaskChanged = $cb;

        return $this;
    }

    public function onScopeCycle(callable $cb): self
    {
        $this->onScopeCycle = $cb;

        return $this;
    }

    public function onToggleOption(callable $cb): self
    {
        $this->onToggleOption = $cb;

        return $this;
    }

    public function onJump(callable $cb): self
    {
        $this->onJump = $cb;

        return $this;
    }

    public function onOpenInFindWindow(callable $cb): self
    {
        $this->onOpenInFindWindow = $cb;

        return $this;
    }

    public function onReplaceAll(callable $cb): self
    {
        $this->onReplaceAll = $cb;

        return $this;
    }

    /** @param \Closure(string, string): string $transformer (lineText, replacement) => replacedLine */
    public function setPreviewTransformer(\Closure $transformer): void
    {
        $this->previewReplacement = $transformer;
    }

    public function onClose(callable $cb): self
    {
        $this->onClose = $cb;

        return $this;
    }

    public function open(string $title): void
    {
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
        $this->searching = false;
        $this->hovered = null;
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
        $this->scopeLabels = [] === $labels ? ['Whole Project'] : $labels;
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
        $this->displayRows = ResultsView::build($results, [], true, '', ResultGrouping::File);
        $this->excluded = [];
        $this->searching = false;
        // Land on the first match row so Enter opens it.
        $this->selected = 0;
        foreach ($this->displayRows as $i => $row) {
            if (null === $row->filePath) {
                $this->selected = $i;

                break;
            }
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
            if (null !== $this->onClose) {
                ($this->onClose)();
            }
        } elseif ($kb->matches($data, 'open_in_find_window')) {
            if (null !== $this->onOpenInFindWindow) {
                ($this->onOpenInFindWindow)();
            }
        } elseif ($kb->matches($data, 'accept_completion')) {
            $this->jumpSelected();
        } elseif ('query' === $this->activeField && $kb->matches($data, 'history_prev')) {
            $this->recallHistory(1);
        } elseif ('query' === $this->activeField && $kb->matches($data, 'history_next')) {
            $this->recallHistory(-1);
        } elseif ($kb->matches($data, 'cursor_up')) {
            $this->step(-1);
        } elseif ($kb->matches($data, 'cursor_down')) {
            $this->step(1);
        } elseif ($kb->matches($data, 'switch_field')) {
            $this->activeField = match ($this->activeField) {
                'query' => 'replace',
                'replace' => 'mask',
                default => 'query',
            };
            ($this->invalidate)();
        } elseif ($kb->matches($data, 'replace_all')) {
            if (null !== $this->onReplaceAll) {
                ($this->onReplaceAll)($this->replace->value(), $this->excludedIndices());
            }
        } elseif ($kb->matches($data, 'toggle_exclude')) {
            if ($this->inPreview()) {
                $this->toggleExcludeSelected();
            }
        } elseif ($kb->matches($data, 'insert_newline')) {
            $this->activeTextField()->insertNewline();
            $this->notifyField();
        } elseif ($kb->matches($data, 'cycle_scope')) {
            $this->cycleScope();
        } elseif ($kb->matches($data, 'toggle_regex')) {
            $this->fireToggle('regex');
        } elseif ($kb->matches($data, 'toggle_case')) {
            $this->fireToggle('case');
        } elseif ($kb->matches($data, 'toggle_word')) {
            $this->fireToggle('word');
        } elseif ($kb->matches($data, 'toggle_preserve')) {
            $this->fireToggle('preserve');
        } elseif ($kb->matches($data, 'toggle_tokens')) {
            $this->fireToggle('tokens');
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            if ($this->activeTextField()->backspace()) {
                $this->notifyField();
            }
        } elseif ($this->activeTextField()->insert($data)) {
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
        if (null !== $this->onScopeCycle) {
            ($this->onScopeCycle)($this->scopeIndex);
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
        if ('mask' === $this->activeField) {
            if (null !== $this->onMaskChanged) {
                ($this->onMaskChanged)($this->mask->value());
            }
        } elseif ('query' === $this->activeField) {
            $this->historyCursor = -1; // typing leaves history-browsing
            if (null !== $this->onQuery) {
                ($this->onQuery)($this->query->value());
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
        if (null !== $this->onQuery) {
            ($this->onQuery)($this->query->value());
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
            if ($isExcluded) {
                $indices[] = $matchIndex;
            }
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
        if (null !== $this->onToggleOption) {
            ($this->onToggleOption)($name);
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
        if (null !== $row && null === $row->filePath && null !== $this->onJump) {
            $result = $this->results[$row->matchIndex];
            ($this->onJump)($result['path'], $result['line']);
        }
    }

    /**
     * Composite the modal over the rendered editor lines, recording its box
     * geometry for hit-testing. Returns the lines unchanged when closed.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function composite(array $lines, int $columns, int $rows, bool $focused): array
    {
        $this->box = null;
        if (!$this->open) {
            return $lines;
        }

        $maxPreview = max(1, min(\count($this->displayRows), $rows - 9, 10));
        $windowStart = $this->windowStart($maxPreview);
        $visible = \array_slice($this->displayRows, $windowStart, $maxPreview);

        $width = $this->boxWidth($columns, $visible);
        $col = max(0, intdiv($columns - $width, 2));

        $popup = $this->headerRows($width, $focused);
        $this->optionsRow = 3; // title(0), query(1), replace(2), options(3) — index into $popup
        $previewIndex = \count($popup);
        foreach ($visible as $i => $row) {
            $selected = ($windowStart + $i) === $this->selected;
            $popup[] = match (true) {
                null !== $row->filePath => $this->fileHeaderRow($row->filePath, $row->count, $width),
                $this->inPreview() => $this->previewMatchRow($this->results[$row->matchIndex], $row->matchIndex, $selected, $width),
                default => $this->matchRow($this->results[$row->matchIndex], $selected, $width),
            };
        }
        if ([] === $visible) {
            $popup[] = $this->emptyRow($width);
        }
        $popup[] = $this->footerRow($width);
        $footerIndex = \count($popup) - 1;

        $startRow = max(0, intdiv($rows - \count($popup), 3));
        foreach ($popup as $i => $popupLine) {
            $screen = $startRow + $i;
            if ($screen >= \count($lines)) {
                break;
            }
            $lines[$screen] = OverlayCompositor::into($lines[$screen], $col, $width, $columns, $popupLine);
        }

        $this->optionsRow += $startRow;
        $this->footerRowIndex = $startRow + $footerIndex;
        $this->box = [
            'col' => $col,
            'width' => $width,
            'previewRow' => $startRow + $previewIndex,
            'windowStart' => $windowStart,
            'count' => \count($visible),
        ];

        return $lines;
    }

    /** The scrolling window's first display-row index, keeping $selected visible. */
    private function windowStart(int $maxPreview): int
    {
        $count = \count($this->displayRows);
        if ($count <= $maxPreview) {
            return 0;
        }

        return max(0, min($this->selected - intdiv($maxPreview, 2), $count - $maxPreview));
    }

    /**
     * @param list<\Porta\Widget\DisplayRow> $visible
     */
    private function boxWidth(int $columns, array $visible): int
    {
        $status = $this->statusText();
        $width = max(mb_strwidth($this->title) + mb_strwidth($status) + 5, mb_strwidth($this->query->value()) + 9, 48);
        foreach ($visible as $row) {
            $text = null !== $row->filePath ? $row->filePath : $this->results[$row->matchIndex]['text'] ?? '';
            $width = max($width, mb_strwidth($text) + 8);
        }

        return min($width, $columns - 4, 80);
    }

    /**
     * The fixed header rows: title, query, replace, options+scope, mask.
     *
     * @return list<string>
     */
    private function headerRows(int $width, bool $focused): array
    {
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);
        $title = ' '.$this->title.$this->palette->of(Role::Dim).'  '.$this->statusText()."\x1b[39m";

        $replaceHint = $this->inPreview() ? \sprintf('  (Del exclude · Alt+A replace %d)', $this->keptCount()) : '  (Tab · Alt+A)';

        return [
            HeaderRenderer::render($title, $width, $this->palette->of(Role::HeaderBar)."\x1b[1m"),
            $this->fieldRow('Find', $this->query, $focused && 'query' === $this->activeField, '', $width, $marker),
            $this->fieldRow('Replace', $this->replace, $focused && 'replace' === $this->activeField, $replaceHint, $width, $marker),
            $this->optionsRowLine($width),
            $this->fieldRow('Mask', $this->mask, $focused && 'mask' === $this->activeField, '  (Tab)', $width, $marker),
        ];
    }

    /** A labelled input row (`Find:`/`Replace:`/`Mask:` + field), scrolled to keep the caret visible. */
    private function fieldRow(string $label, TextField $field, bool $shown, string $hint, int $width, string $marker): string
    {
        $line = ' '.$label.': '.$field->render(cursor: $shown);
        if ('' !== $hint) {
            $line .= $this->palette->of(Role::Dim).$hint."\x1b[39m";
        }

        return $this->palette->of(Role::Toolbar).AnsiText::scrollLineToCaret($line, $width, $marker)."\x1b[0m";
    }

    /** How many matches would be replaced (all minus the excluded). */
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

    private function statusText(): string
    {
        return $this->searching ? 'searching…' : \sprintf('%d results', \count($this->results));
    }

    /** The option tags + scope label row; records the tag + scope click zones. */
    private function optionsRowLine(int $width): string
    {
        $tokenGlyph = TokenScope::from($this->options['tokens'])->glyph();
        $this->optionZones = [];
        $col = 1; // leading space
        foreach (['regex' => '.*', 'case' => 'Aa', 'word' => '\\b', 'preserve' => 'Pc', 'tokens' => $tokenGlyph] as $name => $glyph) {
            $w = mb_strwidth($glyph) + 2;
            $this->optionZones[] = ['name' => $name, 'start' => $col, 'end' => $col + $w];
            $col += $w;
        }
        $tags =
            $this->optionTag('.*', $this->options['regex'], 'regex' === $this->hovered)
            .$this->optionTag('Aa', $this->options['case'], 'case' === $this->hovered)
            .$this->optionTag('\\b', $this->options['word'], 'word' === $this->hovered)
            .$this->optionTag('Pc', $this->options['preserve'], 'preserve' === $this->hovered)
            .$this->optionTag($tokenGlyph, 'any' !== $this->options['tokens'], 'tokens' === $this->hovered);

        $label = $this->scopeLabels[$this->scopeIndex] ?? 'Whole Project';
        $labelPart = 'scope' === $this->hovered
            ? $this->palette->of(Role::RowHighlightBg).$this->palette->of(Role::Bright).$label."\x1b[0m".$this->palette->of(Role::Toolbar)
            : $this->palette->of(Role::TabAccent).$label."\x1b[39m";
        $scope = '  '.$this->palette->of(Role::Dim).'Scope: '."\x1b[39m".$labelPart.$this->palette->of(Role::Dim).' (Alt+S)'."\x1b[39m";
        // The scope zone starts past the two-space gap; clamp so a truncated box can't hit past the edge.
        $this->optionZones[] = ['name' => 'scope', 'start' => $col + 2, 'end' => min($col + 2 + mb_strwidth('Scope: '.$label.' (Alt+S)'), $width)];

        return $this->palette->of(Role::Toolbar).AnsiUtils::truncateToWidth(' '.$tags.$scope, $width, '…', pad: true)."\x1b[0m";
    }

    private function optionTag(string $glyph, bool $on, bool $hovered): string
    {
        if ($on) {
            return $this->palette->of(Role::SelectedRow).' '.$glyph." \x1b[0m".$this->palette->of(Role::Toolbar);
        }
        if ($hovered) {
            return $this->palette->of(Role::RowHighlightBg).' '.$glyph." \x1b[0m".$this->palette->of(Role::Toolbar);
        }

        return $this->palette->of(Role::Faint).' '.$glyph." \x1b[39m";
    }

    private function fileHeaderRow(string $path, int $count, int $width): string
    {
        $text = ' '.$this->palette->of(Role::TabAccent).$path."\x1b[39m".$this->palette->of(Role::Dim).'  ('.$count.')'."\x1b[39m";

        return $this->palette->of(Role::PopupRow).AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
    }

    /**
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function matchRow(array $result, bool $selected, int $width): string
    {
        $bg = $selected ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::PopupRow);
        $label = (string) ($result['line'] + 1);
        $prefixWidth = 4 + mb_strwidth($label) + 2;
        $text = '    '.$this->palette->of(Role::TabAccent).$label."\x1b[39m".'  '.$result['text'];
        $line = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        $searchBg = $this->palette->of(Role::SearchBg);
        foreach ($result['ranges'] ?? [] as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
        }

        return $line;
    }

    /**
     * A match row in replace-preview mode: a `[x]/[ ]` checkbox, the line number,
     * and either the original line (highlighted, when excluded) or `old → new`.
     *
     * @param array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>} $result
     */
    private function previewMatchRow(array $result, int $matchIndex, bool $selected, int $width): string
    {
        $bg = $selected ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::PopupRow);
        $excluded = $this->excluded[$matchIndex] ?? false;
        $label = (string) ($result['line'] + 1);
        $old = $result['text'];
        $checkbox = '  '.($excluded ? '[ ]' : '[x]').' ';
        $prefixWidth = mb_strwidth($checkbox) + mb_strwidth($label) + 2;
        $head = $checkbox.$this->palette->of(Role::TabAccent).$label."\x1b[39m".'  ';

        if ($excluded) {
            $text = $head.$old.$this->palette->of(Role::Dim).'   (excluded)'."\x1b[39m";
        } else {
            $new = null !== $this->previewReplacement ? ($this->previewReplacement)($old, $this->replace->value()) : $old;
            $text = $head.$old.'  →  '.$this->palette->of(Role::Added).$new."\x1b[39m";
        }

        $line = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        $searchBg = $this->palette->of(Role::SearchBg);
        foreach ($result['ranges'] ?? [] as $range) {
            $start = $prefixWidth + $range['start'];
            $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
        }

        return $line;
    }

    private function emptyRow(int $width): string
    {
        $text = '' === $this->query->value() ? ' type to search…' : ' no results';

        return $this->palette->of(Role::PopupRow).$this->palette->of(Role::CompletionHint).AnsiUtils::truncateToWidth($text, $width, '', pad: true)."\x1b[0m";
    }

    /** The footer hint row; the action words are clickable/hoverable zones. */
    private function footerRow(int $width): string
    {
        $this->footerZones = [];
        $bg = $this->palette->of(Role::HeaderBar);
        $dim = $this->palette->of(Role::Dim);
        $sep = ' · ';
        $line = ' Enter open'.$sep;
        $col = mb_strwidth($line);
        foreach (['Find window' => 'find_window', 'Scope' => 'footer_scope', 'Close' => 'close'] as $label => $name) {
            $this->footerZones[] = ['name' => $name, 'start' => $col, 'end' => $col + mb_strwidth($label)];
            $line .= $name === $this->hovered
                ? $this->palette->of(Role::RowHighlightBg).$this->palette->of(Role::Bright).$label."\x1b[0m".$bg.$dim
                : $label;
            $col += mb_strwidth($label);
            if ('close' !== $name) {
                $line .= $sep;
                $col += mb_strwidth($sep);
            }
        }

        return $bg.$dim.AnsiUtils::truncateToWidth($line, $width, '…', pad: true)."\x1b[0m";
    }

    /**
     * The last composited box geometry (for mouse hit-testing and tests); the
     * options row sits at `previewRow - 2`.
     *
     * @return array{col: int, width: int, previewRow: int, windowStart: int, count: int}|null
     */
    public function lastBox(): ?array
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
        if (null === $box || $relCol < $box['col'] || $relCol >= ($box['col'] + $box['width'])) {
            if (null !== $this->onClose) {
                ($this->onClose)();
            }

            return true;
        }
        $innerCol = $relCol - $box['col'];
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
            $zone = $this->zoneNameAt($innerCol, $this->optionZones);
            if ('scope' === $zone) {
                $this->cycleScope();
            } elseif (null !== $zone) {
                $this->fireToggle($zone);
            }

            return true;
        }
        if ($relRow === $this->footerRowIndex) {
            $this->clickFooter($this->zoneNameAt($innerCol, $this->footerZones));

            return true;
        }
        $index = $this->previewIndexAt($relRow);
        if (null !== $index) {
            $this->selected = $index;
            if ($this->inPreview() && $innerCol >= 2 && $innerCol < 5) {
                $this->toggleExcludeSelected();
            } else {
                $this->jumpSelected();
            }
        }

        return true;
    }

    /** Fire the footer action under a click ('find_window' | 'footer_scope' | 'close'). */
    private function clickFooter(?string $name): void
    {
        if ('find_window' === $name) {
            if (null !== $this->onOpenInFindWindow) {
                ($this->onOpenInFindWindow)();
            }
        } elseif ('footer_scope' === $name) {
            $this->cycleScope();
        } elseif ('close' === $name) {
            if (null !== $this->onClose) {
                ($this->onClose)();
            }
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
        if (null !== $box && $relRow === $this->optionsRow) {
            return $this->hoverZone($this->zoneNameAt($relCol - $box['col'], $this->optionZones));
        }
        if (null !== $box && $relRow === $this->footerRowIndex) {
            return $this->hoverZone($this->zoneNameAt($relCol - $box['col'], $this->footerZones));
        }
        $cleared = $this->hoverZone(null); // moving off the header/footer drops any tag hover
        $index = $this->previewIndexAt($relRow);
        if (null !== $index && null === $this->displayRows[$index]->filePath && $index !== $this->selected) {
            $this->selected = $index;
            ($this->invalidate)();

            return true;
        }

        return $cleared;
    }

    /**
     * The zone name whose column span covers $innerCol, or null.
     *
     * @param list<array{name: string, start: int, end: int}> $zones
     */
    private function zoneNameAt(int $innerCol, array $zones): ?string
    {
        foreach ($zones as $zone) {
            if ($innerCol >= $zone['start'] && $innerCol < $zone['end']) {
                return $zone['name'];
            }
        }

        return null;
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
        $i = $relRow - $box['previewRow'];
        if ($i < 0 || $i >= $box['count']) {
            return null;
        }

        return $box['windowStart'] + $i;
    }
}
