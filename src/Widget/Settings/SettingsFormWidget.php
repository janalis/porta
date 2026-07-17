<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\SettingsRow;
use Porta\Config\SettingsRowKind;
use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\HoverableList;
use Porta\Widget\HoverTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
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
 */
#[AsWidget('settings-form', flex: 1)]
final class SettingsFormWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

    private readonly Styles $styles;

    private readonly CursorStyle $cursorStyle;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @var list<SettingsRow> */
    private array $rows = [];
    private int $selected = 0;
    /** The row index whose text field is being edited inline, or null. */
    private ?int $editing = null;
    private ?TextField $editField = null;

    /** @var (callable(string, bool|int|string): void)|null a row's value changed */
    private $onChange = null;
    /** @var (callable(): void)|null `2`: switch to the Keybindings tab */
    private $onShowKeybindings = null;

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

    /**
     * Replace the rows, clamping the selection into range.
     *
     * @param list<SettingsRow> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->selected = IntMath::clamp($this->selected, 0, \count($rows) - 1);
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

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'show_keybindings')) {
            $this->fire($this->onShowKeybindings);

            return;
        }
        if ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->selected - 1);

            return;
        }
        if ($kb->matches($data, 'move_down')) {
            $this->moveTo($this->selected + 1);

            return;
        }
        if ($kb->matches($data, 'increment')) {
            $this->actuate(1);

            return;
        }
        if ($kb->matches($data, 'decrement')) {
            $this->actuate(-1);
        }
    }

    /** A click selects the row under the pointer and advances it one step. */
    /** Tint the settings row under the pointer (visual only, like the nav/keys lists). */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered(array_key_exists($relRow, $this->rows) ? $relRow : null);
    }

    public function handleClick(int $relRow): void
    {
        if (!array_key_exists($relRow, $this->rows)) {
            return;
        }
        $this->selected = $relRow;
        $this->actuate(1);
    }

    private function moveTo(int $index): void
    {
        $index = IntMath::clamp($index, 0, \count($this->rows) - 1);
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
        if (SettingsRowKind::Text === $row->kind) {
            $this->editField = new TextField((string) $row->value, $this->cursorStyle);
            $this->editing = $this->selected;
            $this->invalidate();

            return;
        }
        if (null === $this->onChange) {
            return;
        }
        if (SettingsRowKind::Action === $row->kind) {
            if ($delta > 0) {
                ($this->onChange)($row->key, (string) $row->value);
            }

            return; // a button has no value to step back
        }

        $new = match ($row->kind) {
            SettingsRowKind::Bool => !(bool) $row->value,
            SettingsRowKind::Choice => $this->cycleChoice($row, $delta),
            default => $this->stepInt($row, $delta),
        };
        ($this->onChange)($row->key, $new);
    }

    /** Route a keystroke into the inline text editor: Esc cancels, Enter commits. */
    private function handleTextInput(string $data, Keybindings $kb, int $index, TextField $field): void
    {
        if ($kb->matches($data, 'exit_view')) {
            $this->editing = null;
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'confirm')) {
            $key = ($this->rows[$index] ?? null)?->key;
            $value = $field->value();
            $this->editing = null;
            $this->invalidate();
            if (null !== $key && null !== $this->onChange) {
                ($this->onChange)($key, $value);
            }

            return;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            if ($field->backspace()) {
                $this->invalidate();
            }

            return;
        }
        if ($field->insert($data)) {
            $this->invalidate();
        }
    }

    /**
     * @param SettingsRow $row
     */
    private function cycleChoice(SettingsRow $row, int $delta): string
    {
        $options = $row->options ?? [];
        if ([] === $options) {
            return (string) $row->value;
        }
        $index = array_search($row->value, $options, strict: true);
        $index = false === $index ? 0 : $index;
        $count = \count($options);

        return $options[((($index + $delta) % $count) + $count) % $count];
    }

    /** The display label for a choice value (e.g. `en` → `English`), or the value itself. */
    private function choiceLabel(SettingsRow $row): string
    {
        $value = (string) $row->value;

        return $row->optionLabels[$value] ?? $value;
    }

    /**
     * @param SettingsRow $row
     */
    private function stepInt(SettingsRow $row, int $delta): int
    {
        $min = $row->min ?? 1;
        $max = $row->max ?? \PHP_INT_MAX;

        return IntMath::clamp((int) $row->value + $delta, $min, $max);
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(\count($this->rows), $context->getRows() > 0 ? $context->getRows() : \count($this->rows));

        $lines = [];
        for ($i = 0; $i < $rows; ++$i) {
            $row = $this->rows[$i] ?? null;
            if (null === $row) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            $text = match ($row->kind) {
                SettingsRowKind::Bool => ' '.((bool) $row->value ? '[x]' : '[ ]').' '.$row->label,
                SettingsRowKind::Text => ' '.$row->label.'  '.$this->textValue($row, $i),
                SettingsRowKind::Action => ' '.$row->label.'  ['.(string) $row->value.']',
                default => ' '.$row->label.'  < '.$this->choiceLabel($row).' >',
            };
            if ($row->restart) {
                $text .= $this->styles->of(Role::Dim)->apply(' · '.$this->translator->trans('applies on restart'));
            }
            $text = AnsiText::truncate($text, $width, pad: true);
            $lines[] = match (true) {
                $i === $this->selected => $this->applyElement('selected', $text),
                $i === $this->hoveredIndex => $this->styles->of(Role::RowHighlightBg)->apply($text),
                default => $text,
            };
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /**
     * The rendered value of a text row: the live editor (with a cursor) while
     * editing, else the value, else a dimmed hint when empty.
     *
     * @param SettingsRow $row
     */
    private function textValue(SettingsRow $row, int $index): string
    {
        if ($index === $this->editing && null !== $this->editField) {
            return $this->editField->render(cursor: $this->focused ? CaretVisibility::Shown : CaretVisibility::Hidden);
        }
        $value = (string) $row->value;
        if ('' === $value && null !== $row->hint) {
            return $this->styles->of(Role::Dim)->apply($row->hint);
        }

        return $value;
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
