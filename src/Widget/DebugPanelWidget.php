<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Debug\DebugState;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListSelection;
use Porta\Ui\LtrPane;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The debugger's bottom panel: session state, the paused call stack and the
 * variables tree (scopes expandable one level at a time, children fetched
 * lazily through the onExpandVariable callback). Enter or a click on a frame
 * jumps to its source via onFrameSelected. Structured like
 * {@see ResultsPanelWidget} — the third resizable bottom panel.
 */
#[AsWidget('debug')]
final class DebugPanelWidget extends OverlayWidget implements FocusableInterface, LtrPane
{
    use FocusableTrait;
    use KeybindingsTrait;
    use PanelRows;

    private string $title = '';
    private DebugState $state = DebugState::Idle;
    private ?string $location = null;
    private readonly ListSelection $list;

    /** @var list<array{id: int, name: string, path: string, line: int}> 0-based lines */
    private array $frames = [];
    /** @var list<array{name: string, variablesReference: int}> */
    private array $scopes = [];
    /** @var array<int, list<array{name: string, value: string, variablesReference: int}>> children by reference */
    private array $children = [];
    /** @var array<int, true> expanded variablesReferences */
    private array $expanded = [];
    /** @var list<array{kind: string, label: string, frameId: int, path: string, line: int, ref: int, depth: int}> flattened display rows */
    private array $rows = [];

    /** @var (callable(int, string, int): void)|null frameId, path, 0-based line */
    private $onFrameSelected = null;
    /** @var (callable(int, callable(list<array{name: string, value: string, variablesReference: int}>): void): void)|null */
    private $onExpandVariable = null;

    private readonly Palette $palette;

    public function __construct(?Palette $palette = null)
    {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->list = new ListSelection();
        $this->visibleRows = 10;
    }

    public function onFrameSelected(callable $callback): self
    {
        $this->onFrameSelected = $callback;

        return $this;
    }

    public function onExpandVariable(callable $callback): self
    {
        $this->onExpandVariable = $callback;

        return $this;
    }

    public function open(string $title): void
    {
        $this->open = true;
        $this->title = $title;
        $this->state = DebugState::Starting;
        $this->location = null;
        $this->frames = [];
        $this->scopes = [];
        $this->children = [];
        $this->expanded = [];
        $this->list->select(0);
        $this->rebuild();
    }

    public function setSessionState(DebugState $state, ?string $location): void
    {
        $this->state = $state;
        $this->location = $location;
        if (DebugState::Paused !== $state) {
            $this->frames = [];
            $this->scopes = [];
            $this->children = [];
            $this->expanded = [];
        }
        $this->rebuild();
    }

    /** @param list<array{id: int, name: string, path: string, line: int}> $frames */
    public function setFrames(array $frames): void
    {
        $this->frames = $frames;
        $this->rebuild();
    }

    /** @param list<array{name: string, variablesReference: int}> $scopes scopes render expanded; children arrive via setVariables */
    public function setScopes(array $scopes): void
    {
        $this->scopes = $scopes;
        foreach ($scopes as $scope) {
            if ($scope['variablesReference'] > 0) {
                $this->expanded[$scope['variablesReference']] = true;
            }
        }
        $this->rebuild();
    }

    /** @param list<array{name: string, value: string, variablesReference: int}> $variables */
    public function setVariables(int $reference, array $variables): void
    {
        $this->children[$reference] = $variables;
        $this->rebuild();
    }

    /** Scroll the list with the mouse wheel. */
    public function scrollBy(int $delta): void
    {
        if ($delta < 0) {
            $this->list->moveUp(-$delta);
        } else {
            $this->list->moveDown(\count($this->rows), $delta);
        }
        $this->invalidate();
    }

    /** Highlight the row under the pointer; returns whether it changed. */
    public function hoverRow(int $relRow): bool
    {
        if ($this->list->selectIfChanged($this->rowIndexAt($relRow))) {
            $this->invalidate();

            return true;
        }

        return false;
    }

    /** A click selects and activates (frame jump / expand toggle). */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $this->activateRow($index);
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'close')) {
            $this->close();
        } elseif ($kb->matches($data, 'move_up')) {
            $this->list->moveUp(1);
            $this->invalidate();
        } elseif ($kb->matches($data, 'move_down')) {
            $this->list->moveDown(\count($this->rows), 1);
            $this->invalidate();
        } elseif ($kb->matches($data, 'jump')) {
            $this->activateRow($this->list->selected());
        }
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        if (!$this->open) {
            return [];
        }
        $columns = $context->getColumns();

        $status = match ($this->state) {
            DebugState::Idle, DebugState::Starting => 'starting…',
            DebugState::Running => 'running…',
            DebugState::Paused => 'paused'.(null !== $this->location ? ' at '.$this->location : ''),
            DebugState::Terminated => 'terminated',
        };
        $legend = '  F8 over · F7 into · ⇧F8 out · F9 resume · ^F2 stop';
        $prefix = ' Debug: '.$this->title.$this->palette->of(Role::Dim).'  '.$status.$legend."\x1b[39m";
        $lines = [HeaderRenderer::render($prefix, $columns, $this->palette->of(Role::HeaderBar)."\x1b[1m")];

        $scroll = $this->list->follow($this->visibleRows);
        foreach (\array_slice($this->rows, $scroll, $this->visibleRows) as $i => $row) {
            $isSelected = $this->list->isSelected($scroll + $i);
            $bg = $isSelected ? ($this->focused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::SelectedRowUnfocused)) : $this->palette->of(Role::Toolbar);
            $lines[] = $bg.AnsiUtils::truncateToWidth($this->rowText($row), $columns, '…', pad: true)."\x1b[0m";
        }

        return $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'close' => [Key::ESCAPE],
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'jump' => [Key::ENTER],
        ];
    }

    protected function resetState(): void
    {
        $this->state = DebugState::Idle;
        $this->frames = [];
        $this->scopes = [];
        $this->children = [];
        $this->expanded = [];
        $this->rows = [];
    }

    /** @param array{kind: string, label: string, frameId: int, path: string, line: int, ref: int, depth: int} $row */
    private function rowText(array $row): string
    {
        $indent = str_repeat('  ', $row['depth']);

        return match ($row['kind']) {
            'section' => ' '.$this->palette->of(Role::Dim).$row['label']."\x1b[39m",
            'frame' => ' '.$indent.$row['label'].'  '.$this->palette->of(Role::TabAccent).$row['path'].':'.($row['line'] + 1)."\x1b[39m",
            'scope' => ' '.$indent.(isset($this->expanded[$row['ref']]) ? '▾ ' : '▸ ').$this->palette->of(Role::Bright).$row['label']."\x1b[39m",
            default => ' '.$indent.($row['ref'] > 0 ? (isset($this->expanded[$row['ref']]) ? '▾ ' : '▸ ') : '  ').$row['label'],
        };
    }

    /** Rebuild the flattened rows from frames + scopes + expanded children. */
    private function rebuild(): void
    {
        $rows = [];
        if ([] !== $this->frames) {
            $rows[] = self::row('section', 'Frames');
            foreach ($this->frames as $i => $frame) {
                $rows[] = self::row('frame', "#{$i} {$frame['name']}", frameId: $frame['id'], path: $frame['path'], line: $frame['line'], depth: 1);
            }
        }
        if ([] !== $this->scopes) {
            $rows[] = self::row('section', 'Variables');
            foreach ($this->scopes as $scope) {
                $rows[] = self::row('scope', $scope['name'], ref: $scope['variablesReference'], depth: 1);
                if (isset($this->expanded[$scope['variablesReference']])) {
                    $this->appendChildren($rows, $scope['variablesReference'], 2);
                }
            }
        }
        $this->rows = $rows;
        $this->list->clamp(\count($rows));
        $this->invalidate();
    }

    /** @param list<array{kind: string, label: string, frameId: int, path: string, line: int, ref: int, depth: int}> $rows */
    private function appendChildren(array &$rows, int $reference, int $depth): void
    {
        foreach ($this->children[$reference] ?? [] as $variable) {
            $rows[] = self::row('variable', "{$variable['name']} = {$variable['value']}", ref: $variable['variablesReference'], depth: $depth);
            if ($variable['variablesReference'] > 0 && isset($this->expanded[$variable['variablesReference']])) {
                $this->appendChildren($rows, $variable['variablesReference'], $depth + 1);
            }
        }
    }

    /** @return array{kind: string, label: string, frameId: int, path: string, line: int, ref: int, depth: int} */
    private static function row(string $kind, string $label, int $frameId = -1, string $path = '', int $line = 0, int $ref = 0, int $depth = 0): array
    {
        return ['kind' => $kind, 'label' => $label, 'frameId' => $frameId, 'path' => $path, 'line' => $line, 'ref' => $ref, 'depth' => $depth];
    }

    private function activateRow(int $index): void
    {
        $row = $this->rows[$index] ?? null;
        if (null === $row) {
            return;
        }
        if ('frame' === $row['kind'] && null !== $this->onFrameSelected) {
            ($this->onFrameSelected)($row['frameId'], $row['path'], $row['line']);

            return;
        }
        if (('scope' === $row['kind'] || 'variable' === $row['kind']) && $row['ref'] > 0) {
            $this->toggleExpand($row['ref']);
        }
    }

    private function toggleExpand(int $reference): void
    {
        if (isset($this->expanded[$reference])) {
            unset($this->expanded[$reference]);
            $this->rebuild();

            return;
        }
        $this->expanded[$reference] = true;
        if (!isset($this->children[$reference]) && null !== $this->onExpandVariable) {
            ($this->onExpandVariable)($reference, fn (array $variables) => $this->setVariables($reference, $variables));
        }
        $this->rebuild();
    }

    /** Display-row index at the widget-relative row (row 0 is the header), or null. */
    private function rowIndexAt(int $relRow): ?int
    {
        $i = $relRow - 1;
        if ($i < 0) {
            return null;
        }
        $index = $this->list->scroll() + $i;

        return $index < \count($this->rows) ? $index : null;
    }
}
