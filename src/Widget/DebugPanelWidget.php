<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Debug\DebugState;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\HeaderRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The debugger's bottom panel: session state, the paused call stack, the
 * watch expressions (evaluated per stop/frame; results land index-addressed
 * via setWatchResult, so out-of-order responses cannot misassign) and the
 * variables tree (scopes expandable one level at a time, children fetched
 * lazily through the onExpandVariable callback). Enter or a click on a frame
 * jumps to its source via onFrameSelected; Delete on a watch row fires
 * onRemoveWatch. Structured like {@see ResultsPanelWidget} — the third
 * resizable bottom panel.
 */
#[AsWidget('debug')]
final class DebugPanelWidget extends OverlayWidget implements FocusableInterface, LtrPane
{
    use FocusableTrait;
    use KeybindingsTrait;
    use ListPanelInteraction;
    use PanelRows;

    private string $title = '';
    private DebugState $state = DebugState::Idle;
    private ?string $location = null;
    private readonly ListSelection $list;

    /** @var list<array{id: int, name: string, path: string, line: int}> 0-based lines */
    private array $frames = [];
    /** @var list<array{name: string, variablesReference: int}> */
    private array $scopes = [];
    /** @var list<array{expr: string, value: ?string, ref: int, error: ?string}> value null = pending */
    private array $watches = [];
    /** @var array<int, list<array{name: string, value: string, variablesReference: int}>> children by reference */
    private array $children = [];
    /** @var array<int, true> expanded variablesReferences */
    private array $expanded = [];
    /** @var list<DebugRow> flattened display rows */
    private array $rows = [];

    /** @var (callable(int, string, int): void)|null frameId, path, 0-based line */
    private $onFrameSelected = null;
    /** @var (callable(int, callable(list<array{name: string, value: string, variablesReference: int}>): void): void)|null */
    private $onExpandVariable = null;
    /** @var (callable(int): void)|null a watch row's Delete key, by watch index */
    private $onRemoveWatch = null;

    private readonly Styles $styles;

    public function __construct(?Styles $styles = null)
    {
        $this->styles = $styles ?? Styles::dark();
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
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

    public function onRemoveWatch(callable $callback): self
    {
        $this->onRemoveWatch = $callback;

        return $this;
    }

    public function open(string $title): void
    {
        $this->markOpen();
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
            $this->watches = array_map(static fn (array $watch): array => [...$watch, 'value' => null, 'ref' => 0, 'error' => null], $this->watches);
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
            if ($scope['variablesReference'] <= 0) {
                continue;
            }

            $this->expanded[$scope['variablesReference']] = true;
        }
        $this->rebuild();
    }

    /** @param list<array{name: string, value: string, variablesReference: int}> $variables */
    public function setVariables(int $reference, array $variables): void
    {
        $this->children[$reference] = $variables;
        $this->rebuild();
    }

    /** @param list<string> $expressions the watch expressions; all values reset to pending */
    public function setWatches(array $expressions): void
    {
        $this->watches = array_map(static fn (string $expr): array => ['expr' => $expr, 'value' => null, 'ref' => 0, 'error' => null], $expressions);
        $this->rebuild();
    }

    /** Index-addressed delivery; a stale index (the watch was removed meanwhile) is ignored. */
    public function setWatchResult(int $index, string $value, int $ref, ?string $error = null): void
    {
        if (!array_key_exists($index, $this->watches)) {
            return;
        }
        $this->watches[$index] = [...$this->watches[$index], 'value' => $value, 'ref' => $ref, 'error' => $error];
        $this->rebuild();
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'close')) {
            $this->close();

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows))) {
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'jump')) {
            $this->activateRow($this->list->selected());

            return;
        }
        if ($kb->matches($data, 'remove_watch')) {
            $row = $this->rows[$this->list->selected()] ?? null;
            if (null !== $row && DebugRowKind::Watch === $row->kind && null !== $this->onRemoveWatch) {
                ($this->onRemoveWatch)($row->watchIndex);
            }
        }
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        if (!$this->isVisible()) {
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
        $prefix = ' Debug: '.$this->title.$this->styles->of(Role::Dim)->apply('  '.$status.$legend);
        $lines = [HeaderRenderer::render($prefix, $columns, $this->styles->of(Role::HeaderBar)->withBold())];

        $scroll = $this->list->follow($this->visibleRows);
        foreach (\array_slice($this->rows, $scroll, $this->visibleRows) as $i => $row) {
            // The selection shade comes from the theme's .list::selected(:focus) rules.
            $bg = $this->list->isSelected($scroll + $i) ? $this->resolveElement('selected') : $this->styles->of(Role::Toolbar);
            $lines[] = $bg->apply(AnsiUtils::truncateToWidth($this->rowText($row), $columns, '…', pad: true));
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
            'remove_watch' => [Key::DELETE],
        ];
    }

    protected function resetState(): void
    {
        $this->state = DebugState::Idle;
        $this->frames = [];
        $this->watches = [];
        $this->scopes = [];
        $this->children = [];
        $this->expanded = [];
        $this->rows = [];
    }

    private function rowText(DebugRow $row): string
    {
        $indent = str_repeat('  ', max(0, $row->depth));

        return match ($row->kind) {
            DebugRowKind::Watch => $this->watchText($row),
            DebugRowKind::Section => ' '.$this->styles->of(Role::Dim)->apply($row->label),
            DebugRowKind::Frame => ' '.$indent.$row->label.'  '.$this->styles->of(Role::TabAccent)->apply($row->path.':'.($row->line + 1)),
            DebugRowKind::Scope => ' '.$indent.(array_key_exists($row->ref, $this->expanded) ? '▾ ' : '▸ ').$this->styles->of(Role::Bright)->apply($row->label),
            DebugRowKind::Variable => ' '.$indent.$this->expandArrow($row->ref).$row->label,
        };
    }

    /** The expand/collapse arrow for a reference-bearing row: an open/closed triangle when expandable, else two spaces. */
    private function expandArrow(int $ref): string
    {
        if ($ref <= 0) {
            return '  ';
        }

        return array_key_exists($ref, $this->expanded) ? '▾ ' : '▸ ';
    }

    private function watchText(DebugRow $row): string
    {
        $indent = str_repeat('  ', max(0, $row->depth));
        $watch = $this->watches[$row->watchIndex] ?? null;
        $arrow = $this->expandArrow($row->ref);
        $value = match (true) {
            null === $watch => '',
            null !== $watch['error'] => $this->styles->of(Role::Dim)->apply($watch['error']),
            null === $watch['value'] => $this->styles->of(Role::Dim)->apply('…'),
            default => $watch['value'],
        };

        return ' '.$indent.$arrow.$row->label.' = '.$value;
    }

    /** Rebuild the flattened rows from frames + scopes + expanded children. */
    private function rebuild(): void
    {
        $rows = [];
        if ([] !== $this->frames) {
            $rows[] = new DebugRow(DebugRowKind::Section, 'Frames');
            foreach ($this->frames as $i => $frame) {
                $rows[] = new DebugRow(DebugRowKind::Frame, "#{$i} {$frame['name']}", frameId: $frame['id'], path: $frame['path'], line: $frame['line'], depth: 1);
            }
        }
        if ([] !== $this->watches) {
            $rows[] = new DebugRow(DebugRowKind::Section, 'Watches');
            foreach ($this->watches as $i => $watch) {
                $rows[] = new DebugRow(DebugRowKind::Watch, $watch['expr'], ref: $watch['ref'], depth: 1, watchIndex: $i);
                if ($watch['ref'] > 0 && array_key_exists($watch['ref'], $this->expanded)) {
                    $this->appendChildren($rows, $watch['ref'], 2);
                }
            }
        }
        if ([] !== $this->scopes) {
            $rows[] = new DebugRow(DebugRowKind::Section, 'Variables');
            foreach ($this->scopes as $scope) {
                $rows[] = new DebugRow(DebugRowKind::Scope, $scope['name'], ref: $scope['variablesReference'], depth: 1);
                if (array_key_exists($scope['variablesReference'], $this->expanded)) {
                    $this->appendChildren($rows, $scope['variablesReference'], 2);
                }
            }
        }
        $this->rows = $rows;
        $this->list->clamp(\count($rows));
        $this->invalidate();
    }

    /** @param list<DebugRow> $rows */
    private function appendChildren(array &$rows, int $reference, int $depth): void
    {
        foreach ($this->children[$reference] ?? [] as $variable) {
            $rows[] = new DebugRow(DebugRowKind::Variable, "{$variable['name']} = {$variable['value']}", ref: $variable['variablesReference'], depth: $depth);
            if ($variable['variablesReference'] > 0 && array_key_exists($variable['variablesReference'], $this->expanded)) {
                $this->appendChildren($rows, $variable['variablesReference'], $depth + 1);
            }
        }
    }

    /** Enter or a click: a frame jumps to its source, a reference-bearing row expands/collapses. */
    protected function activateRow(int $index): void
    {
        $row = $this->rows[$index] ?? null;
        if (null === $row) {
            return;
        }
        if (DebugRowKind::Frame === $row->kind && null !== $this->onFrameSelected) {
            ($this->onFrameSelected)($row->frameId, $row->path, $row->line);

            return;
        }
        if (\in_array($row->kind, [DebugRowKind::Scope, DebugRowKind::Variable, DebugRowKind::Watch], strict: true) && $row->ref > 0) {
            $this->toggleExpand($row->ref);
        }
    }

    private function toggleExpand(int $reference): void
    {
        if (array_key_exists($reference, $this->expanded)) {
            unset($this->expanded[$reference]);
            $this->rebuild();

            return;
        }
        $this->expanded[$reference] = true;
        if (!array_key_exists($reference, $this->children) && null !== $this->onExpandVariable) {
            ($this->onExpandVariable)($reference, fn (array $variables) => $this->setVariables($reference, $variables));
        }
        $this->rebuild();
    }

    protected function selection(): ListSelection
    {
        return $this->list;
    }

    protected function rowCount(): int
    {
        return \count($this->rows);
    }

    /** Row 0 is the title/status header. */
    protected function headerRowCount(): int
    {
        return 1;
    }
}
