<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Container\AsWidget;
use Porta\Event\SettingsSearchRequested;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Bidi;
use Porta\I18n\Translator;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
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
 * controller. A boolean renders as a {@see Glyphs} checkbox, a choice/int as a
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
final class SettingsFormWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PaneNavigationCallbacks;
    use PanelRowStyle;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
        private readonly CursorStyle $cursorStyle = new CursorStyle(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @var list<SettingsRow> */
    private array $rows = [];
    private readonly ListSelection $list;
    /** Rows the pane last had room for — the page size for PgUp/PgDn and the scroll window. */
    private int $lastRows = 10;
    /** The page title, shown in the header row. */
    private string $title = '';
    /** The row index whose text field is being edited inline, or null. */
    private ?int $editing = null;
    private ?TextField $editField = null;

    /**
     * @var (callable(string, bool|int|string): bool)|null a row's value changed;
     *                                                     false = refused
     */
    private $onChange = null;

    /** @var (callable(string): void)|null a row was put back to its default */
    private $onReset = null;

    /** @param callable(string, bool|int|string): bool $callback returns false to refuse the value */
    public function onChange(callable $callback): self
    {
        $this->onChange = $callback;

        return $this;
    }

    /** @param callable(string): void $callback a row was reset to its default */
    public function onReset(callable $callback): self
    {
        $this->onReset = $callback;

        return $this;
    }

    /**
     * Replace the rows, clamping the selection into range.
     *
     * @param list<SettingsRow> $rows
     */
    public function setRows(array $rows, string $title = ''): void
    {
        $this->rows = $rows;
        $this->title = $title;
        $this->list->clamp(\count($rows));
        $this->editing = null;
        $this->invalidate();
    }

    /**
     * Put the selection on the row with this key, if the page has one.
     *
     * Silently does nothing when it does not, which is the useful behaviour for
     * the only caller: a search result naming a row that a plugin toggle or a
     * locale switch has since removed should still open its page, not throw.
     */
    public function selectKey(string $key): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row->key !== $key) {
                continue;
            }

            $this->list->select($index);
            $this->invalidate();

            return;
        }
    }

    #[\Override]
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
        if ($kb->matches($data, 'find_setting')) {
            $this->events?->dispatch(new SettingsSearchRequested());

            return;
        }
        if ($kb->matches($data, 'show_keybindings')) {
            $this->events?->dispatch(new SettingsSectionRequested('keybindings'));

            return;
        }
        if ($kb->matches($data, 'reset_row')) {
            $this->resetSelected();

            return;
        }
        if ($kb->matches($data, 'increment')) {
            $this->actuate(1);

            return;
        }
        if ($kb->matches($data, 'decrement')) {
            $this->actuate(-1);

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows), page: $this->lastRows)) {
            $this->invalidate();
        }
    }

    /** Tint the settings row under the pointer (visual only, like the nav/keys lists). */
    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /**
     * A click SELECTS the row; a click on the already-selected row actuates it.
     *
     * Deliberately not the results/tree contract, where the first click also
     * activates: those rows exist to be jumped to, and jumping is free. A
     * settings row's activation writes persisted config — a stray click used to
     * toggle a setting or arm a plugin removal outright.
     */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        if ($index === $this->list->selected()) {
            $this->actuate(1);

            return;
        }
        $this->list->select($index);
        $this->invalidate();
    }

    /** The row index under a pane-relative row, accounting for the header and the scroll. */
    private function rowIndexAt(int $relRow): ?int
    {
        return $this->list->rowIndexAt($relRow, \count($this->rows), $this->headerRowCount());
    }

    /**
     * Rows above the first setting. One title today; a filter row would make it
     * two, and every index conversion in this widget goes through here so that
     * stays a one-line change.
     */
    private function headerRowCount(): int
    {
        return 1;
    }

    /** Advance (delta +1) or step back (delta -1) the selected row's value. */
    private function actuate(int $delta): void
    {
        $row = $this->rows[$this->list->selected()] ?? null;
        if (null === $row) {
            return;
        }
        if (SettingsRowKind::Text === $row->kind) {
            // Forward only, symmetric with the Action arm below: ← used to open
            // the editor as readily as → did, which is not what a step-back key
            // means anywhere else on this screen.
            if ($delta > 0) {
                $this->editField = new TextField((string) $row->value, $this->cursorStyle);
                $this->editing = $this->list->selected();
                $this->invalidate();
            }

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
            $this->invalidate();
            if (null === $key || null === $this->onChange) {
                $this->editing = null;

                return;
            }
            // A REFUSED value keeps the editor open with the text as typed. It
            // used to close regardless, and setRows() then snapped the row back
            // to the persisted value — so an invalid JSON launch config was
            // reported and destroyed in the same breath, with nothing to
            // correct.
            if (($this->onChange)($key, $field->value())) {
                $this->editing = null;
            }

            return;
        }
        if ($kb->matches($data, 'delete_char_backward')) {
            if ($field->backspace()) {
                $this->invalidate();
            }

            return;
        }
        // Caret movement, so a typo at the START of a value is fixable without
        // retyping the rest — a JSON debug launch config being the case that
        // made append-only editing untenable.
        if ($this->moveCaret($data, $field)) {
            $this->invalidate();

            return;
        }
        if ($field->insert($data)) {
            $this->invalidate();
        }
    }

    /**
     * Run a caret gesture against the inline editor; false when $data is not one.
     *
     * Raw sequences rather than keybinding ids: these are the field's own
     * editing keys, not rebindable IDE actions, and the editor widget and the
     * commit box spell them the same way.
     */
    private function moveCaret(string $data, TextField $field): bool
    {
        return match ($data) {
            "\x1b[D" => $field->moveLeft(),
            "\x1b[C" => $field->moveRight(),
            "\x1b[H", "\x1bOH", "\x1b[1~" => $field->moveHome(),
            "\x1b[F", "\x1bOF", "\x1b[4~" => $field->moveEnd(),
            "\x1b[3~" => $field->deleteForward(),
            "\x17" => $field->deleteWordBackward(),
            default => false,
        };
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

        return IntMath::clamp((int) $row->value + ($delta * ($row->step ?? 1)), $min, $max);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = $context->getRows();
        if ($rows <= 0) {
            return []; // no pane, no lines — never more than the allotted rows
        }
        // The window the rows get: the pane less the header and the footer.
        $this->lastRows = max(1, $rows - $this->headerRowCount() - 1);
        $scroll = $this->list->follow($this->lastRows);

        $layout = SettingsRowLayout::measure($this->rows, $width);
        $lines = [HeaderRenderer::title($this->titleLine(), $width, $this->styles)];
        for ($offset = 0; $offset < $this->lastRows; ++$offset) {
            $i = $scroll + $offset;
            $row = $this->rows[$i] ?? null;
            if (null === $row) {
                $lines[] = AnsiText::blank($width);
                continue;
            }

            $control = $this->control($row, $i);
            // The armed destructive row (the two-press remove confirm) tints
            // its CONTROL, not the whole line: wearing DangerBg over the row
            // beat both the selection bar and the hover tint, so the one row
            // where confirming matters was the one row you could not tell was
            // selected.
            if ($row->danger) {
                $control = $this->styles->merge(Role::DangerBg, Role::Bright)->apply($control);
            }
            // A button whose caption fell back to its own label leaves the
            // label column blank rather than saying it twice.
            $label = SettingsRowKind::Action === $row->kind && '' === (string) $row->value ? '' : $row->label;
            $text = $this->marker($row).AnsiText::padRight($label, $layout->label).$this->gap().$control.$this->valueEnd();
            $detail = SettingsRowDetail::of($row, $this->translator);
            if ('' !== $detail) {
                // No dangling separator when the control is empty — an unset
                // text row reads as `LSP server    auto: phpactor`, which is
                // what its hint always meant.
                $text .= $this->styles->of(Role::Dim)->apply(('' === $control ? '  ' : ' · ').$detail);
            }
            // An overflowing value being edited must keep its caret on screen:
            // scroll the assembled row to the marker instead of a hard clip that
            // would drop it off the right edge (every other field does this).
            $text =
                $i === $this->editing && null !== $this->editField
                    ? AnsiText::scrollLineToCaret($text, $width)
                    : AnsiText::truncate($text, $width, pad: true);
            $lines[] = $this->shadeRow($text, $this->list->isSelected($i), $i === $this->hoveredIndex);
        }
        $lines[] = $this->footer($width);

        return $this->withMenuOverlay($lines, $width);
    }

    /**
     * Put the selected row back to its default.
     *
     * Inert on a row that has none — a button, or a toggle with no stock value
     * — so the key never appears to do something it did not.
     */
    private function resetSelected(): void
    {
        $row = $this->rows[$this->list->selected()] ?? null;
        if (null === $row || null === $row->default) {
            return;
        }
        if (null !== $this->onReset) {
            ($this->onReset)($row->key);
        }
    }

    /**
     * The leading marker column: a dot on a row that differs from its default.
     *
     * Leading, so one vertical scan of the page answers "what have I changed
     * here?" — which nothing on this screen could answer before.
     */
    /**
     * The two columns between a label and its control — one of them spent on a
     * left-to-right mark when the interface is mirrored.
     *
     * A control is machine data: `< dark >`, `[x]`, `[Remove]`. Sat between a
     * Hebrew label and a Latin value, its opening delimiter has strong
     * right-to-left on one side and strong left-to-right on the other, so with
     * nothing to bind it it takes the paragraph direction, leaves for the far
     * side of the group, and mirrors on the way — the row then shows two `>`
     * and the value is not bracketed at all.
     *
     * The mark gives that delimiter something to hold on to. It costs one of
     * the two gap columns rather than a third, so the label column keeps its
     * alignment; and {@see \Porta\I18n\Bidi} spends it for a space on the way
     * out, so the rendered row is as wide as every measurer thinks it is.
     */
    private function gap(): string
    {
        // Two marks, not a space and a mark: the first ENDS the label's run and
        // the second begins the control's. Without the first, a label whose own
        // text ends in a Latin run — `שרת LSP`, `עיצוב בעת שמירה (LSP)` — puts
        // strong left-to-right text against a left-to-right control, the two
        // merge, and the control is drawn inside the label. The label tears in
        // half and the column moves with it: across five real labels the
        // checkbox landed at columns 28, 33, 37, 46 and 28.
        //
        // Both columns already existed as the gap, and each mark is spent for a
        // space, so the label column and the rendered width are untouched.
        return $this->translator->isRtl() ? Bidi::RTL_MARK.Bidi::LTR_MARK : '  ';
    }

    /**
     * The closing half of the pair {@see gap()} opens.
     *
     * One mark is not enough, and the checkbox is what proves it: with a mark
     * only in front, `[x]`'s opening bracket binds left-to-right while its
     * closing one still has the row's padding on its right, takes the paragraph
     * direction and leaves — so a row that used to be correct starts showing
     * `[ [x`. Fixing the stepper this way traded one broken row for another.
     *
     * Bracketing the CONTROL holds every shape at once: the stepper, the
     * checkbox, the action button and the int row's stepper. The detail clause
     * beyond it is deliberately left outside — it is translated prose and
     * belongs mirrored, and wrapping it too is what broke the scope row, whose
     * detail is Hebrew where the theme row's is a Latin option name. Measured
     * against every shape with a detail of each direction before choosing.
     */
    private function valueEnd(): string
    {
        return $this->translator->isRtl() ? Bidi::LTR_MARK : '';
    }

    private function marker(SettingsRow $row): string
    {
        return $row->isModified()
            ? $this->styles->of(Role::Modified)->apply(Glyphs::SETTING_MODIFIED).' '
            : '  ';
    }

    /**
     * A row's control: the part that shows the value and that actuating moves.
     *
     * Every kind renders it in the SAME column, which is the whole point —
     * `Bool` used to put its checkbox at column 1, ahead of its label, while
     * the others put theirs after.
     */
    private function control(SettingsRow $row, int $index): string
    {
        return match ($row->kind) {
            SettingsRowKind::Bool => (bool) $row->value ? Glyphs::CHECKBOX_ON : Glyphs::CHECKBOX_OFF,
            SettingsRowKind::Text => $this->textValue($row, $index),
            SettingsRowKind::Action => '['.$this->actionCaption($row).']',
            SettingsRowKind::Int => '< '.(string) $row->value.' >',
            default => '< '.$this->choiceLabel($row).' >',
        };
    }

    /**
     * A button's caption, falling back to its own label.
     *
     * The plugins page builds its install offer with `value: false`, which
     * stringifies to '' and rendered as `Install phpstan  []` — a button with
     * nothing written on it.
     */
    private function actionCaption(SettingsRow $row): string
    {
        $caption = (string) $row->value;

        return '' === $caption ? $row->label : $caption;
    }

    /** `<page> — N settings`, or just the count before a page has named itself. */
    private function titleLine(): string
    {
        $count = $this->translator->trans('%count% setting|%count% settings', ['%count%' => \count($this->rows)]);

        return ' '.('' === $this->title ? $count : $this->title.' — '.$count);
    }

    /**
     * The key legend. Every one of these was undiscoverable: the form renders
     * no title, no footer and no affordance, so `[`/`]` stepping and the bare
     * `2` that jumps to Keybindings existed only in the keymap.
     */
    private function footer(int $width): string
    {
        $hint = null !== $this->editing
            ? $this->translator->trans('⏎ save · Esc cancel')
            : $this->translator->trans('⏎ edit · ←/→ change · r reset · Ctrl+F find · Tab panes · 2 keybindings');

        return $this->styles->merge(Role::Toolbar, Role::Dim)->apply(AnsiText::truncate(' '.$hint, $width, pad: true));
    }

    /**
     * The rendered value of a text row: the live editor (with a cursor) while
     * editing, else the value.
     *
     * The hint used to stand in for an empty value here, which is why it never
     * reached any other kind and why it read as a placeholder rather than a
     * description. It lives in the detail column now, for every kind and
     * whether or not the row has a value.
     *
     * @param SettingsRow $row
     */
    private function textValue(SettingsRow $row, int $index): string
    {
        if ($index === $this->editing && null !== $this->editField) {
            return $this->editField->render(cursor: $this->focused ? CaretVisibility::Shown : CaretVisibility::Hidden);
        }

        return (string) $row->value;
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
            // Widget-local and uncatalogued, like every other binding in this
            // map. The help viewer catalogues some of its keys because they are
            // rebindable; none of this form's are, and making one of the six
            // the exception would be the surprise.
            'find_setting' => ['ctrl+f'],
            // A bare letter, the dialect this pane already speaks ('1', '2',
            // '[', ']'), and inert while the inline editor owns the keys.
            'reset_row' => ['r'],
            // Shift+Tab is the same destination, not a second one: the view has
            // exactly two panes, so back and forward coincide. That is also why
            // SettingsViewController::cycleFocus() ignores its direction.
            'focus_next_pane' => [Key::TAB, 'shift+tab'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
