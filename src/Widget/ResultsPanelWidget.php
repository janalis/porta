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
    private bool $withOptions = false;
    private bool $withReplace = false;
    private bool $withMask = false;
    private string $title = '';
    private readonly TextField $query;
    private readonly TextField $replace;
    private readonly TextField $mask;
    /** 'query' | 'mask' | 'replace' — which field typing edits; cycled by Tab */
    private string $activeField = 'query';
    private readonly ListSelection $list;
    private bool $searching = false;

    /** @var list<array{name: string, start: int, end: int}> option-tag click zones (columns) from the last render */
    private array $optionZones = [];

    /** @var array{regex: bool, case: bool, word: bool} search toggles shown in the header */
    private array $options = ['regex' => false, 'case' => false, 'word' => false];

    /** @var list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> ranges (search mode only) are display-column match spans */
    private array $results = [];

    /** @var (callable(string): void)|null */
    private $onQueryChanged = null;
    /** @var (callable(string): void)|null the file mask changed (re-run the search) */
    private $onMaskChanged = null;
    /** @var (callable(string, int): void)|null */
    private $onJump = null;
    /** @var (callable(string): void)|null toggled a search option ('regex'|'case'|'word') */
    private $onToggleOption = null;
    /** @var (callable(string): void)|null replace all matches with the given text */
    private $onReplaceAll = null;

    private readonly Palette $palette;

    public function __construct(?Palette $palette = null)
    {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->list = new ListSelection();
        $this->query = new TextField();
        $this->replace = new TextField();
        $this->mask = new TextField();
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

    /** @param array{regex: bool, case: bool, word: bool} $options */
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
        $this->activeField = 'query';
        $this->title = $title;
        $this->query->clear();
        $this->replace->clear();
        $this->mask->clear();
        $this->results = [];
        $this->list->reset();
        $this->searching = false;
        $this->invalidate();
    }

    /** @param list<array{path: string, line: int, text: string, ranges?: list<array{start: int, length: int}>}> $results */
    public function setResults(array $results): void
    {
        $this->results = $results;
        $this->list->reset();
        $this->searching = false;
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
        } elseif (ListNavigation::handleKey($data, $kb, $this->list, \count($this->results))) {
            $this->invalidate();
        } elseif ($kb->matches($data, 'jump')) {
            $result = $this->results[$this->list->selected()] ?? null;
            if (null !== $result && null !== $this->onJump) {
                $this->close();
                ($this->onJump)($result['path'], $result['line']);
            }
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_regex')) {
            $this->fireToggle('regex');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_case')) {
            $this->fireToggle('case');
        } elseif ($this->withOptions && $kb->matches($data, 'toggle_word')) {
            $this->fireToggle('word');
        } elseif ($kb->matches($data, 'switch_field') && \count($this->focusableFields()) > 1) {
            $fields = $this->focusableFields();
            $pos = (int) array_search($this->activeField, $fields, true);
            $this->activeField = $fields[($pos + 1) % \count($fields)];
            $this->invalidate();
        } elseif ($this->withReplace && $kb->matches($data, 'replace_all')) {
            if (null !== $this->onReplaceAll) {
                ($this->onReplaceAll)($this->replace->value());
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
            'replace' => $this->withReplace ? $this->replace : null,
            default => $this->withQuery ? $this->query : null,
        };
    }

    /** Notify the right listener after an edit: query/mask re-run, replace does not. */
    private function afterFieldEdit(): void
    {
        if ('query' === $this->activeField) {
            $this->notifyQuery();
        } elseif ('mask' === $this->activeField) {
            $this->notifyMask();
        }
    }

    private function notifyMask(): void
    {
        if (null !== $this->onMaskChanged) {
            ($this->onMaskChanged)($this->mask->value());
        }
    }

    /**
     * Route a left-click at the given widget-relative row/col: an option tag in
     * the header toggles it; a result row selects and jumps to it.
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
        $result = $this->results[$index];
        if (null !== $this->onJump) {
            $this->close();
            ($this->onJump)($result['path'], $result['line']);
        }
    }

    /** Highlight the result row under the pointer (widget-relative row); returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        if ($this->list->selectIfChanged($this->rowIndexAt($relRow))) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** Result index at the given widget-relative row, or null if it is not a result row. */
    private function rowIndexAt(int $relRow): ?int
    {
        // Row 0 is the title/query row; the mask and replace rows follow.
        $headerRows = 1 + ($this->withMask ? 1 : 0) + ($this->withReplace ? 1 : 0);
        $i = $relRow - $headerRows;
        if ($i < 0) {
            return null;
        }
        $index = $this->list->scroll() + $i;

        return $index < \count($this->results) ? $index : null;
    }

    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }

        $columns = $context->getColumns();
        $lines = [];

        $status = $this->searching ? ' searching…' : \sprintf(' %d results', \count($this->results));
        $showCursor = $this->focused && $this->withQuery;
        $queryField = $this->query->render(cursor: $showCursor && 'query' === $this->activeField);
        $prefix = ' '.$this->title.($this->withQuery ? ': '.$queryField : '').$this->palette->of(Role::Dim).$status."\x1b[39m";

        $this->optionZones = [];
        $options = '';
        if ($this->withOptions) {
            // Each tag renders as ' glyph ' (glyph + two padding spaces); the block
            // is prefixed by two spaces. Record each tag's column span for clicks.
            $col = AnsiUtils::visibleWidth($prefix) + 2;
            foreach (['regex' => '.*', 'case' => 'Aa', 'word' => '\\b'] as $name => $glyph) {
                $width = mb_strwidth($glyph) + 2;
                $this->optionZones[] = ['name' => $name, 'start' => $col, 'end' => $col + $width];
                $col += $width;
            }
            $options = '  '.$this->optionTag('.*', $this->options['regex']).$this->optionTag('Aa', $this->options['case']).$this->optionTag('\\b', $this->options['word']);
        }

        $header = $prefix.$options;
        $lines[] = HeaderRenderer::render($header, $columns, $this->palette->of(Role::HeaderBar)."\x1b[1m");

        if ($this->withMask) {
            $lines[] = $this->fieldRow('Mask', $this->mask, 'mask', $showCursor, '  (e.g. *.php, *.{php,js})', $columns);
        }
        if ($this->withReplace) {
            $lines[] = $this->fieldRow('Replace', $this->replace, 'replace', $showCursor, '  (Tab switch · Alt+A replace all)', $columns);
        }

        $scroll = $this->list->follow($this->visibleRows);

        $searchBg = $this->palette->of(Role::SearchBg);
        foreach (\array_slice($this->results, $scroll, $this->visibleRows) as $i => $result) {
            $isSelected = $this->list->isSelected($scroll + $i);
            $bg = $isSelected ? ($this->focused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::InlayPopupBg)) : $this->palette->of(Role::Toolbar);
            $location = $result['path'].':'.($result['line'] + 1);
            $prefixWidth = 1 + mb_strwidth($location) + 2; // ` ` + `path:line` + `  `
            $text = ' '.$this->palette->of(Role::TabAccent).$location."\x1b[39m".'  '.$result['text'];
            $line = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
            // Highlight each match span; close with the row bg so the tail keeps its colour.
            foreach ($result['ranges'] ?? [] as $range) {
                $start = $prefixWidth + $range['start'];
                $line = LineDecorator::apply($line, $start, $start + $range['length'], $searchBg, $bg, $searchBg);
            }
            $lines[] = $line;
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
            'switch_field' => [Key::TAB],
            'replace_all' => ['alt+a'],
        ];
    }
}
