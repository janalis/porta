<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Input\TextInput;
use Porta\Ui\ListSelection;
use Porta\Ui\Style;
use Porta\Widget\Render\HeaderRenderer;
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
final class ResultsPanelWidget extends OverlayWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private int $visibleRows = 10;

    private bool $withQuery = false;
    private bool $withOptions = false;
    private bool $withReplace = false;
    private string $title = '';
    private string $query = '';
    private string $replace = '';
    /** 'query' | 'replace' — which field typing edits (only when withReplace) */
    private string $activeField = 'query';
    private readonly ListSelection $list;
    private bool $searching = false;

    /** @var list<array{name: string, start: int, end: int}> option-tag click zones (columns) from the last render */
    private array $optionZones = [];

    /** @var array{regex: bool, case: bool, word: bool} search toggles shown in the header */
    private array $options = ['regex' => false, 'case' => false, 'word' => false];

    /** @var list<array{path: string, line: int, text: string}> */
    private array $results = [];

    /** @var (callable(string): void)|null */
    private $onQueryChanged = null;
    /** @var (callable(string, int): void)|null */
    private $onJump = null;
    /** @var (callable(string): void)|null toggled a search option ('regex'|'case'|'word') */
    private $onToggleOption = null;
    /** @var (callable(string): void)|null replace all matches with the given text */
    private $onReplaceAll = null;

    public function __construct()
    {
        $this->list = new ListSelection();
    }

    public function onQueryChanged(callable $callback): self
    {
        $this->onQueryChanged = $callback;

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

    public function open(string $title, bool $withQuery, bool $withOptions = false, bool $withReplace = false): void
    {
        $this->open = true;
        $this->withQuery = $withQuery;
        $this->withOptions = $withOptions;
        $this->withReplace = $withReplace;
        $this->activeField = 'query';
        $this->title = $title;
        $this->query = '';
        $this->replace = '';
        $this->results = [];
        $this->list->reset();
        $this->searching = false;
        $this->invalidate();
    }

    /** @param list<array{path: string, line: int, text: string}> $results */
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

    /** Resize the panel to $rows visible result rows (clamped). */
    public function setVisibleRows(int $rows): void
    {
        $rows = max(2, min($rows, 200));
        if ($rows !== $this->visibleRows) {
            $this->visibleRows = $rows;
            $this->invalidate();
        }
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'close')) {
            $this->close();
        } elseif ($kb->matches($data, 'move_up')) {
            $this->list->moveUp();
            $this->invalidate();
        } elseif ($kb->matches($data, 'move_down')) {
            $this->list->moveDown(\count($this->results));
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
        } elseif ($this->withReplace && $kb->matches($data, 'switch_field')) {
            $this->activeField = 'query' === $this->activeField ? 'replace' : 'query';
            $this->invalidate();
        } elseif ($this->withReplace && $kb->matches($data, 'replace_all')) {
            if (null !== $this->onReplaceAll) {
                ($this->onReplaceAll)($this->replace);
            }
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            if ($this->withReplace && 'replace' === $this->activeField) {
                $this->replace = substr($this->replace, 0, -1);
                $this->invalidate();
            } elseif ($this->withQuery && '' !== $this->query) {
                $this->query = substr($this->query, 0, -1);
                $this->notifyQuery();
                $this->invalidate();
            }
        } elseif ($this->withQuery && TextInput::isPrintable($data)) {
            if ($this->withReplace && 'replace' === $this->activeField) {
                $this->replace .= $data;
            } else {
                $this->query .= $data;
                $this->notifyQuery();
            }
            $this->invalidate();
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
        $index = $this->rowIndexAt($relRow);
        if (null !== $index && $index !== $this->list->selected()) {
            $this->list->select($index);
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** Result index at the given widget-relative row, or null if it is not a result row. */
    private function rowIndexAt(int $relRow): ?int
    {
        $headerRows = $this->withReplace ? 2 : 1;
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
        $cursor = $this->focused && $this->withQuery ? AnsiUtils::cursorMarker(CursorShape::Bar) : '';
        $queryCursor = 'query' === $this->activeField ? $cursor : '';
        $prefix = ' '.$this->title.($this->withQuery ? ': '.$this->query.$queryCursor : '')."\x1b[38;5;245m".$status."\x1b[39m";

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
        $lines[] = HeaderRenderer::render($header, $columns);

        if ($this->withReplace) {
            $replaceCursor = 'replace' === $this->activeField ? $cursor : '';
            $replaceLine = ' Replace: '.$this->replace.$replaceCursor."\x1b[38;5;245m  (Tab switch · Alt+A replace all)\x1b[39m";
            $lines[] = "\x1b[48;5;236m".AnsiUtils::truncateToWidth($replaceLine, $columns, '…', pad: true)."\x1b[0m";
        }

        $scroll = $this->list->follow($this->visibleRows);

        foreach (\array_slice($this->results, $scroll, $this->visibleRows) as $i => $result) {
            $isSelected = ($scroll + $i) === $this->list->selected();
            $bg = $isSelected ? ($this->focused ? Style::SELECTED_ROW : "\x1b[48;5;238m") : "\x1b[48;5;235m";
            $location = $result['path'].':'.($result['line'] + 1);
            $text = ' '."\x1b[38;5;110m".$location."\x1b[39m".'  '.$result['text'];
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $columns, '…', pad: true)."\x1b[0m";
        }

        if ([] === $this->results && !$this->searching && '' !== $this->query) {
            $lines[] = "\x1b[48;5;235m\x1b[38;5;244m".AnsiUtils::truncateToWidth(' no results', $columns, '', pad: true)."\x1b[0m";
        }

        return $lines;
    }

    private function notifyQuery(): void
    {
        if (null !== $this->onQueryChanged) {
            ($this->onQueryChanged)($this->query);
        }
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
            ? Style::SELECTED_ROW.' '.$glyph." \x1b[0m"
            : "\x1b[38;5;242m ".$glyph." \x1b[39m";
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
