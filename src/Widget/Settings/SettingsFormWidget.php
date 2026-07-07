<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\I18n\Translator;
use Porta\Ui\Style;
use Porta\Ui\TextField;
use Porta\Widget\InputHook;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The editor-settings form: one row per setting, fed as dumb data by the
 * controller. A boolean renders as a `[x]`/`[ ]` checkbox, a choice/int as a
 * `< value >` stepper. Space/Enter/→ advances the selected row, ←/[ steps it
 * back, and each change fires onChange with the new value; the controller
 * persists it and pushes fresh rows back. Rows that only take effect next
 * launch carry a dimmed "· applies on restart" note.
 *
 * A `text` row is edited inline: Enter/click enters edit mode, typing/Backspace
 * change the value, Enter commits (fires onChange), Esc cancels.
 *
 * @phpstan-type Row array{key: string, label: string, kind: string, value: bool|int|string, restart: bool, options?: list<string>, min?: int, max?: int, hint?: string}
 */
final class SettingsFormWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;

    public function __construct(
        private readonly Translator $translator,
    ) {}

    /** @var list<Row> */
    private array $rows = [];
    private int $selected = 0;
    /** The row index whose text field is being edited inline, or null. */
    private ?int $editing = null;
    private ?TextField $editField = null;

    /** @var (callable(string, bool|int|string): void)|null a row's value changed */
    private $onChange = null;
    /** @var (callable(): void)|null `2`: switch to the Keybindings tab */
    private $onShowKeybindings = null;
    /** @var (callable(): void)|null Tab: cycle focus (single pane today) */
    private $onFocusNext = null;
    /** @var (callable(): void)|null Esc: leave the settings view */
    private $onExit = null;

    /** @param callable(string, bool|int|string): void $callback */
    public function onChange(callable $callback): self
    {
        $this->onChange = $callback;

        return $this;
    }

    public function onShowKeybindings(callable $callback): self
    {
        $this->onShowKeybindings = $callback;

        return $this;
    }

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    /**
     * Replace the rows, clamping the selection into range.
     *
     * @param list<Row> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->selected = max(0, min($this->selected, \count($rows) - 1));
        $this->editing = null;
        $this->invalidate();
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function isEditing(): bool
    {
        return null !== $this->editing;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if (null !== $this->editing && null !== $this->editField) {
            $this->handleTextInput($data, $kb, $this->editing, $this->editField);

            return;
        }

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);
        } elseif ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);
        } elseif ($kb->matches($data, 'show_keybindings')) {
            $this->fire($this->onShowKeybindings);
        } elseif ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->selected - 1);
        } elseif ($kb->matches($data, 'move_down')) {
            $this->moveTo($this->selected + 1);
        } elseif ($kb->matches($data, 'increment')) {
            $this->actuate(1);
        } elseif ($kb->matches($data, 'decrement')) {
            $this->actuate(-1);
        }
    }

    /** A click selects the row under the pointer and advances it one step. */
    public function handleClick(int $relRow): void
    {
        if (!isset($this->rows[$relRow])) {
            return;
        }
        $this->selected = $relRow;
        $this->actuate(1);
    }

    private function moveTo(int $index): void
    {
        $index = max(0, min($index, \count($this->rows) - 1));
        if ($index !== $this->selected) {
            $this->selected = $index;
            $this->invalidate();
        }
    }

    /** Advance (delta +1) or step back (delta -1) the selected row's value. */
    private function actuate(int $delta): void
    {
        $row = $this->rows[$this->selected] ?? null;
        if (null === $row) {
            return;
        }
        if ('text' === $row['kind']) {
            $this->editField = new TextField((string) $row['value']);
            $this->editing = $this->selected;
            $this->invalidate();

            return;
        }
        if (null === $this->onChange) {
            return;
        }

        $new = match ($row['kind']) {
            'bool' => !(bool) $row['value'],
            'choice' => $this->cycleChoice($row, $delta),
            default => $this->stepInt($row, $delta),
        };
        ($this->onChange)($row['key'], $new);
    }

    /** Route a keystroke into the inline text editor: Esc cancels, Enter commits. */
    private function handleTextInput(string $data, Keybindings $kb, int $index, TextField $field): void
    {
        if ($kb->matches($data, 'exit_view')) {
            $this->editing = null;
            $this->invalidate();
        } elseif ($kb->matches($data, 'confirm')) {
            $key = $this->rows[$index]['key'] ?? null;
            $value = $field->value();
            $this->editing = null;
            $this->invalidate();
            if (null !== $key && null !== $this->onChange) {
                ($this->onChange)($key, $value);
            }
        } elseif ($kb->matches($data, 'delete_char_backward')) {
            if ($field->backspace()) {
                $this->invalidate();
            }
        } elseif ($field->insert($data)) {
            $this->invalidate();
        }
    }

    /**
     * @param Row $row
     */
    private function cycleChoice(array $row, int $delta): string
    {
        $options = $row['options'] ?? [];
        if ([] === $options) {
            return (string) $row['value'];
        }
        $index = array_search($row['value'], $options, true);
        $index = false === $index ? 0 : $index;
        $count = \count($options);

        return $options[(($index + $delta) % $count + $count) % $count];
    }

    /**
     * @param Row $row
     */
    private function stepInt(array $row, int $delta): int
    {
        $min = $row['min'] ?? 1;
        $max = $row['max'] ?? \PHP_INT_MAX;

        return max($min, min($max, (int) $row['value'] + $delta));
    }

    /** @param (callable(): void)|null $callback */
    private function fire(?callable $callback): void
    {
        if (null !== $callback) {
            $callback();
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(\count($this->rows), $context->getRows() > 0 ? $context->getRows() : \count($this->rows));

        $lines = [];
        for ($i = 0; $i < $rows; ++$i) {
            $row = $this->rows[$i] ?? null;
            if (null === $row) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }

            $selected = $i === $this->selected;
            $bg = $selected ? ($this->focused ? Style::SELECTED_ROW : "\x1b[48;5;237m") : '';
            $text = match ($row['kind']) {
                'bool' => ' '.((bool) $row['value'] ? '[x]' : '[ ]').' '.$row['label'],
                'text' => ' '.$row['label'].'  '.$this->textValue($row, $i),
                default => ' '.$row['label'].'  < '.(string) $row['value'].' >',
            };
            if ($row['restart']) {
                $text .= "\x1b[38;5;245m · ".$this->translator->trans('applies on restart')."\x1b[39m";
            }
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /**
     * The rendered value of a text row: the live editor (with a cursor) while
     * editing, else the value, else a dimmed hint when empty.
     *
     * @param Row $row
     */
    private function textValue(array $row, int $index): string
    {
        if ($index === $this->editing && null !== $this->editField) {
            return $this->editField->render(cursor: $this->focused);
        }
        $value = (string) $row['value'];
        if ('' === $value && isset($row['hint'])) {
            return "\x1b[38;5;245m".$row['hint']."\x1b[39m";
        }

        return $value;
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // always fills its pane
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'increment' => [Key::SPACE, Key::ENTER, Key::RIGHT, ']'],
            'decrement' => [Key::LEFT, '['],
            'confirm' => [Key::ENTER],
            'delete_char_backward' => [Key::BACKSPACE],
            'show_keybindings' => ['2'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
