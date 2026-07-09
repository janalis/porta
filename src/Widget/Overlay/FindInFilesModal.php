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
        'cycle_scope' => ['alt+s'],
        'toggle_regex' => ['alt+r'],
        'toggle_case' => ['alt+c'],
        'toggle_word' => ['alt+w'],
        'toggle_preserve' => ['alt+p'],
        'toggle_tokens' => ['alt+t'],
    ];

    private bool $open = false;
    private string $title = '';
    private readonly TextField $query;
    private readonly TextField $mask;
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
    /** 'query' | 'mask' — which field typing edits (Tab cycles) */
    private string $activeField = 'query';
    private bool $searching = false;

    /** @var array{col: int, width: int, previewRow: int, windowStart: int, count: int}|null last-rendered geometry, for hit-testing */
    private ?array $box = null;
    /** @var list<array{name: string, start: int, end: int}> option-tag click zones (columns) on the options row */
    private array $optionZones = [];
    private int $optionsRow = -1;

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
    /** @var (callable(): void)|null */
    private $onClose = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly \Closure $invalidate,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->query = new TextField();
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
        $this->mask->clear();
        $this->activeField = 'query';
        $this->results = [];
        $this->displayRows = [];
        $this->selected = 0;
        $this->searching = false;
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
        } elseif ($kb->matches($data, 'cursor_up')) {
            $this->step(-1);
        } elseif ($kb->matches($data, 'cursor_down')) {
            $this->step(1);
        } elseif ($kb->matches($data, 'switch_field')) {
            $this->activeField = 'query' === $this->activeField ? 'mask' : 'query';
            ($this->invalidate)();
        } elseif ($kb->matches($data, 'cycle_scope')) {
            $this->scopeIndex = ($this->scopeIndex + 1) % \count($this->scopeLabels);
            if (null !== $this->onScopeCycle) {
                ($this->onScopeCycle)($this->scopeIndex);
            }
            ($this->invalidate)();
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
        return 'mask' === $this->activeField ? $this->mask : $this->query;
    }

    private function notifyField(): void
    {
        if ('mask' === $this->activeField) {
            if (null !== $this->onMaskChanged) {
                ($this->onMaskChanged)($this->mask->value());
            }
        } elseif (null !== $this->onQuery) {
            ($this->onQuery)($this->query->value());
        }
        ($this->invalidate)();
    }

    private function fireToggle(string $name): void
    {
        if (null !== $this->onToggleOption) {
            ($this->onToggleOption)($name);
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

        $maxPreview = max(1, min(\count($this->displayRows), $rows - 8, 10));
        $windowStart = $this->windowStart($maxPreview);
        $visible = \array_slice($this->displayRows, $windowStart, $maxPreview);

        $width = $this->boxWidth($columns, $visible);
        $col = max(0, intdiv($columns - $width, 2));

        $popup = $this->headerRows($width, $focused);
        $this->optionsRow = 2; // title(0), query(1), options(2) — index into $popup
        $previewIndex = \count($popup);
        foreach ($visible as $i => $row) {
            $popup[] = null !== $row->filePath
                ? $this->fileHeaderRow($row->filePath, $row->count, $width)
                : $this->matchRow($this->results[$row->matchIndex], ($windowStart + $i) === $this->selected, $width);
        }
        if ([] === $visible) {
            $popup[] = $this->emptyRow($width);
        }
        $popup[] = $this->footerRow($width);

        $startRow = max(0, intdiv($rows - \count($popup), 3));
        foreach ($popup as $i => $popupLine) {
            $screen = $startRow + $i;
            if ($screen >= \count($lines)) {
                break;
            }
            $lines[$screen] = OverlayCompositor::into($lines[$screen], $col, $width, $columns, $popupLine);
        }

        $this->optionsRow += $startRow;
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
        $width = max(mb_strwidth($this->title) + mb_strwidth($status) + 5, mb_strwidth($this->query->value()) + 6, 48);
        foreach ($visible as $row) {
            $text = null !== $row->filePath ? $row->filePath : ($this->results[$row->matchIndex]['text'] ?? '');
            $width = max($width, mb_strwidth($text) + 8);
        }

        return min($width, $columns - 4, 80);
    }

    /**
     * The fixed header rows: title, query, options+scope, mask.
     *
     * @return list<string>
     */
    private function headerRows(int $width, bool $focused): array
    {
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);
        $title = ' '.$this->title.$this->palette->of(Role::Dim).'  '.$this->statusText()."\x1b[39m";

        $queryShown = $focused && 'query' === $this->activeField;
        $queryRow = $this->palette->of(Role::Toolbar).AnsiText::scrollLineToCaret(' > '.$this->query->render(cursor: $queryShown), $width, $marker)."\x1b[0m";

        $maskShown = $focused && 'mask' === $this->activeField;
        $maskLine = ' Mask: '.$this->mask->render(cursor: $maskShown).$this->palette->of(Role::Dim).'  (Tab)'."\x1b[39m";
        $maskRow = $this->palette->of(Role::Toolbar).AnsiText::scrollLineToCaret($maskLine, $width, $marker)."\x1b[0m";

        return [
            HeaderRenderer::render($title, $width, $this->palette->of(Role::HeaderBar)."\x1b[1m"),
            $queryRow,
            $this->optionsRowLine($width),
            $maskRow,
        ];
    }

    private function statusText(): string
    {
        return $this->searching ? 'searching…' : \sprintf('%d results', \count($this->results));
    }

    /** The option tags + scope label row; records the tag click zones. */
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
        $tags = $this->optionTag('.*', $this->options['regex'])
            .$this->optionTag('Aa', $this->options['case'])
            .$this->optionTag('\\b', $this->options['word'])
            .$this->optionTag('Pc', $this->options['preserve'])
            .$this->optionTag($tokenGlyph, 'any' !== $this->options['tokens']);
        $scope = '  '.$this->palette->of(Role::Dim).'Scope: '."\x1b[39m".$this->palette->of(Role::TabAccent).($this->scopeLabels[$this->scopeIndex] ?? 'Whole Project')."\x1b[39m".$this->palette->of(Role::Dim).' (Alt+S)'."\x1b[39m";

        return $this->palette->of(Role::Toolbar).AnsiUtils::truncateToWidth(' '.$tags.$scope, $width, '…', pad: true)."\x1b[0m";
    }

    private function optionTag(string $glyph, bool $on): string
    {
        return $on
            ? $this->palette->of(Role::SelectedRow).' '.$glyph." \x1b[0m".$this->palette->of(Role::Toolbar)
            : $this->palette->of(Role::Faint).' '.$glyph." \x1b[39m";
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

    private function emptyRow(int $width): string
    {
        $text = '' === $this->query->value() ? ' type to search…' : ' no results';

        return $this->palette->of(Role::PopupRow).$this->palette->of(Role::CompletionHint).AnsiUtils::truncateToWidth($text, $width, '', pad: true)."\x1b[0m";
    }

    private function footerRow(int $width): string
    {
        $hint = ' Enter open · Alt+Enter Find window · Alt+S scope · Esc';

        return $this->palette->of(Role::HeaderBar).$this->palette->of(Role::Dim).AnsiUtils::truncateToWidth($hint, $width, '…', pad: true)."\x1b[0m";
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

    /** A left-click at editor-pane-relative coords: option tag toggles, preview row jumps, outside closes. */
    public function clickAt(int $relRow, int $relCol): bool
    {
        if (!$this->open) {
            return false;
        }
        $box = $this->box;
        if (null === $box || $relCol < $box['col'] || $relCol >= $box['col'] + $box['width']) {
            if (null !== $this->onClose) {
                ($this->onClose)();
            }

            return true;
        }
        $innerCol = $relCol - $box['col'];
        if ($relRow === $this->optionsRow) {
            foreach ($this->optionZones as $zone) {
                if ($innerCol >= $zone['start'] && $innerCol < $zone['end']) {
                    $this->fireToggle($zone['name']);

                    return true;
                }
            }

            return true;
        }
        $index = $this->previewIndexAt($relRow);
        if (null !== $index) {
            $this->selected = $index;
            $this->jumpSelected();
        }

        return true;
    }

    /** Highlight the preview row under the pointer; returns whether it changed. */
    public function hoverAt(int $relRow, int $relCol): bool
    {
        if (!$this->open) {
            return false;
        }
        $index = $this->previewIndexAt($relRow);
        if (null !== $index && null === $this->displayRows[$index]->filePath && $index !== $this->selected) {
            $this->selected = $index;
            ($this->invalidate)();

            return true;
        }

        return false;
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
