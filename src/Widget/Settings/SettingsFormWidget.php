<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Container\AsWidget;
use Porta\Event\SettingsSearchRequested;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\BaseDirection;
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
use Porta\Ui\ScreenDensity;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelRowStyle;
use Porta\Widget\Support\WheelScrollable;
use Psr\EventDispatcher\EventDispatcherInterface;
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
 * The editor-settings form, drawn like a browser form and fed as dumb data by
 * the controller: a label column, then one control column where every kind
 * renders the way its HTML counterpart does — a boxed checkbox, a boxed text
 * field, a select (`│ dark  ▾│`) whose list opens on Enter/Space or a click,
 * a `◂ 4 ▸` stepper, a push button — grouped under fieldset legends. Each
 * change fires onChange with the new value; the controller persists it and
 * pushes fresh rows back. Rows that only take effect next launch carry a
 * dimmed "· applies on restart" note.
 *
 * Items are several rows tall, so every pointer and paging question goes
 * through ONE map, {@see $rowOwner}, filled by the render that drew them —
 * the Welcome card list's mechanism.
 *
 * A `text` row is edited inline: Enter/click enters edit mode, typing/Backspace
 * change the value, Enter commits (fires onChange), Esc cancels.
 */
#[AsWidget('settings-form', flex: 1)]
final class SettingsFormWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, TooltipProvider, VerticallyExpandableInterface, WheelScrollable
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
        private readonly ScreenDensity $density = new ScreenDensity(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->list = new ListSelection();
        $this->header = new CrumbHeader();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @var list<SettingsRow> */
    private array $rows = [];
    private readonly ListSelection $list;
    /** Items the pane last had room for from its scroll — the page size for PgUp/PgDn. */
    private int $lastPage = 1;
    /**
     * Which item each screen row of the last render belongs to — null for the
     * title, a legend, the padding and the footer — so a click, a hover and a
     * scroll land on what was DRAWN there.
     *
     * @var list<int|null>
     */
    private array $rowOwner = [];
    /**
     * Where each drawn item's control sits: its first row, first column,
     * width and height, pane-relative — a select opens its list under it, a
     * click on a stepper's arrow steps.
     *
     * @var array<int, array{row: int, col: int, width: int, height: int}>
     */
    private array $controls = [];

    /**
     * What each drawn item could not show, from the last render — its detail
     * cut to fit, what its ● means — keyed by item index.
     *
     * @var array<int, string>
     */
    private array $tips = [];

    /** The width the last render drew at: a tooltip spans the whole row. */
    private int $drawnWidth = 0;
    /** The page's trail, drawn as the title row. */
    private readonly CrumbHeader $header;

    /** @var list<array{label: string, id: ?string}> the trail last set: which page the rows are */
    private array $trail = [];
    /** The row index whose text field is being edited inline, or null. */
    private ?int $editing = null;
    private ?TextField $editField = null;

    /**
     * @var (callable(string, bool|int|string): bool)|null a row's value changed;
     *                                                     false = refused
     */
    private $onChange;

    /** @var (callable(string): void)|null a row was put back to its default */
    private $onReset;

    /** @var (callable(SettingsRow, int, int, int, int): void)|null a choice row's list was asked for */
    private $onOpenChoice;

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
     * Open a choice row's list: called with the row, the pane-relative row
     * BELOW its control (where the list hangs), the control's column, the row
     * the control starts on (where a list that has to flip up ends) and its
     * width. Picking an option is the host's business — it runs the ordinary
     * onChange path, so a pick and a step are one write.
     *
     * @param callable(SettingsRow, int, int, int, int): void $callback
     */
    public function onOpenChoice(callable $callback): self
    {
        $this->onOpenChoice = $callback;

        return $this;
    }

    /**
     * A crumb of the title row that names another page was clicked: the
     * callback gets that page's id.
     *
     * @param callable(string): void $callback
     */
    public function onCrumb(callable $callback): self
    {
        $this->header->onCrumb($callback);

        return $this;
    }

    /**
     * Replace the rows, clamping the selection into range. The title is the
     * page's trail, root first — or one plain name, a trail of itself.
     *
     * @param list<SettingsRow>                                $rows
     * @param list<array{label: string, id: ?string}>|string $title
     */
    public function setRows(array $rows, array|string $title = ''): void
    {
        $trail = match (true) {
            \is_array($title) => $title,
            '' === $title => [],
            default => [['label' => $title, 'id' => null]],
        };
        // An edit in progress survives the SAME page re-reading itself — a
        // tool's version landing a moment after it opened, an install
        // finishing — while its row is still there. Another page cancels
        // it: plugin pages share row keys, and an edit that followed its key
        // across would save into a different plugin.
        $editedKey = null === $this->editing || $trail !== $this->trail ? null : $this->rows[$this->editing]->key;
        $kept = null === $editedKey ? null : \array_find_key($rows, static fn (SettingsRow $row): bool => $editedKey === $row->key);
        $this->rows = $rows;
        $this->trail = $trail;
        $this->header->set($trail);
        $this->list->clamp(\count($rows));
        $this->editing = $kept;
        if (null !== $kept) {
            $this->list->select($kept);
        }
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
        $kind = ($this->rows[$this->list->selected()] ?? null)?->kind;

        match (true) {
            null !== $this->editing && null !== $this->editField => $this->handleTextInput($data, $kb, $this->editing, $this->editField),
            $kb->matches($data, 'exit_view') => $this->fire($this->onExit),
            $kb->matches($data, 'focus_next_pane') => $this->fire($this->onFocusNext),
            $kb->matches($data, 'find_setting') => $this->events?->dispatch(new SettingsSearchRequested()),
            $kb->matches($data, 'show_keybindings') => $this->events?->dispatch(new SettingsSectionRequested('keybindings')),
            $kb->matches($data, 'reset_row') => $this->resetSelected(),
            SettingsRowKind::Choice === $kind && $kb->matches($data, 'open_list') => $this->openChoice(),
            // A checkbox takes Space and Enter, as in a browser — the arrows and
            // brackets step a select or a number, and on a checkbox they used to
            // flip it, which no browser does.
            SettingsRowKind::Bool === $kind && $kb->matches($data, 'toggle') => $this->actuate(1),
            SettingsRowKind::Bool === $kind && ($kb->matches($data, 'increment') || $kb->matches($data, 'decrement')) => null,
            $kb->matches($data, 'increment') => $this->actuate(1),
            $kb->matches($data, 'decrement') => $this->actuate(-1),
            ListNavigation::handleKey($data, $kb, $this->list, \count($this->rows), page: $this->lastPage) => $this->invalidate(),
            default => null,
        };
    }

    /** Tint the settings item under the pointer (visual only, like the nav/keys lists). */
    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowOwner[$relRow] ?? null);
    }

    /** Tint the link crumb at column $col of the title row, or none (null); returns whether that changed. */
    public function hoverCrumb(?int $col): bool
    {
        if (!$this->header->hover($col)) {
            return false;
        }
        $this->invalidate();

        return true;
    }

    /**
     * A click SELECTS the item; a click on the already-selected item actuates
     * it — on its label as well as its control, the way an HTML <label>
     * toggles its checkbox.
     *
     * Deliberately not the results/tree contract, where the first click also
     * activates: those rows exist to be jumped to, and jumping is free. A
     * settings row's activation writes persisted config — a stray click used to
     * toggle a setting or arm a plugin removal outright.
     *
     * Two controls answer the first click, because it writes nothing: a
     * select opens its list (a pick is still a separate click), and a
     * selected stepper steps down or up by the arrow half it was clicked in.
     * A checkbox answers it too, on its label as on its box, though it does
     * write: its whole effect is shown in place and the next click undoes it,
     * where the rows the rule protects arm a removal or open an editor. A
     * browser's `<label>` toggles on the first click; making it wait read as
     * a dead control.
     *
     * Rows the render drew nothing on — the rule under the title, a legend
     * and its margins, the padding, the footer — are nobody's: {@see $rowOwner} is what was drawn, not the list
     * arithmetic, which is how a click on the footer once selected the setting
     * BELOW the window. The title row is the page's trail, where a crumb
     * naming another page goes there.
     */
    public function handleClick(int $relRow, int $relCol): void
    {
        if (0 === $relRow) {
            $this->header->click($relCol);

            return;
        }
        $index = $this->rowOwner[$relRow] ?? null;
        if (null === $index) {
            return;
        }
        $control = $this->controls[$index] ?? null;
        $onControl = null !== $control && $relCol >= $control['col'] && $relCol < ($control['col'] + $control['width']);
        $kind = $this->rows[$index]->kind;
        $selected = $index === $this->list->selected();
        $this->list->select($index);
        $this->invalidate();
        // @mago-expect lint:literal-named-argument -- intdiv's parameter is called `num2`; halving the control needs no name
        $half = null === $control ? 0 : $control['col'] + \intdiv($control['width'], 2);
        match (true) {
            $onControl && SettingsRowKind::Choice === $kind => $this->openChoice(),
            $selected || SettingsRowKind::Bool === $kind => $this->actuate($onControl && SettingsRowKind::Int === $kind && $relCol < $half ? -1 : 1),
            default => null,
        };
    }

    /** Hand the selected choice row's control geometry to the host so it can hang the list under it. */
    private function openChoice(): void
    {
        $index = $this->list->selected();
        $row = $this->rows[$index] ?? null;
        $control = $this->controls[$index] ?? null;
        if (!$row instanceof SettingsRow || null === $control || null === $this->onOpenChoice || $row->disabled) {
            $this->actuate(1); // no host to show a list: step, so the row still moves

            return;
        }
        ($this->onOpenChoice)($row, $control['row'] + $control['height'], $control['col'], $control['row'], $control['width']);
    }

    /** Advance (delta +1) or step back (delta -1) the selected row's value. */
    private function actuate(int $delta): void
    {
        $row = $this->rows[$this->list->selected()] ?? null;
        if (null === $row || $row->disabled) {
            return; // shown, not actionable — the dashed border already says so
        }
        $onChange = $this->onChange;
        match (true) {
            // Forward only, symmetric with the Action arm below: ← used to open
            // the editor as readily as → did, which is not what a step-back key
            // means anywhere else on this screen.
            SettingsRowKind::Text === $row->kind => $delta > 0 ? $this->openEditor($row) : null,
            null === $onChange => null,
            // A button has no value to step back.
            SettingsRowKind::Action === $row->kind => $delta > 0 ? $onChange($row->key, (string) $row->value) : null,
            default => $onChange($row->key, match ($row->kind) {
                SettingsRowKind::Bool => !(bool) $row->value,
                SettingsRowKind::Choice => $this->cycleChoice($row, $delta),
                default => $this->stepInt($row, $delta),
            }),
        };
    }

    /** Open the inline text editor on $row, seeded with its value. */
    private function openEditor(SettingsRow $row): void
    {
        $this->editField = new TextField((string) $row->value, $this->cursorStyle);
        $this->editing = $this->list->selected();
        $this->invalidate();
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
            // A REFUSED value keeps the editor open with the text as typed. It
            // used to close regardless, and setRows() then snapped the row back
            // to the persisted value — so an invalid JSON launch config was
            // reported and destroyed in the same breath, with nothing to
            // correct.
            if (null === $key || null === $this->onChange || ($this->onChange)($key, $field->value())) {
                $this->editing = null;
            }

            return;
        }
        // Backspace, else caret movement — so a typo at the START of a value is
        // fixable without retyping the rest, a JSON debug launch config being
        // the case that made append-only editing untenable — else typing.
        $changed = $kb->matches($data, 'delete_char_backward') ? $field->backspace() : $field->editKey($data, $kb) || $field->insert($data);
        if ($changed) {
            $this->invalidate();
        }
    }

    private function cycleChoice(SettingsRow $row, int $delta): string
    {
        $options = $row->options ?? [];
        if ([] === $options) {
            return (string) $row->value;
        }
        $index = \array_search($row->value, $options, strict: true);
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
        // The window the items get: the pane less the title, the rule under
        // it and the footer.
        $window = \max(1, $rows - 3);
        $layout = SettingsRowLayout::measure($this->rows, $width, $this->density->current(), $this->direction());
        $scroll = $this->list->followHeights($layout->heights, $window);
        $this->lastPage = $this->list->countFitting($layout->heights, $scroll, $window);

        // The title row is the page's trail with its count at the right, and
        // the rule under it closes it off, as under the editor's bar; it opens
        // the pane, so it needs nothing above.
        $count = $this->translator->trans('%count% setting|%count% settings', ['%count%' => \count($this->rows)]);
        $lines = [$this->header->render($width, $this->styles, $count), Rule::horizontal($width, $this->styles)];
        $this->rowOwner = [null, null];
        $this->controls = [];
        $this->tips = [];
        $this->drawnWidth = $width;
        // The title and its rule, then the window: the footer's row is the
        // first past it.
        $footer = 2 + $window;
        for ($i = $scroll; $i < \count($this->rows) && \count($lines) < $footer; ++$i) {
            foreach ($this->item($i, $layout, $width, \count($lines)) as [$line, $owner]) {
                if (\count($lines) >= $footer) {
                    break; // the last item may be cut; its rest is a scroll away
                }
                $lines[] = $line;
                $this->rowOwner[] = $owner;
            }
        }
        while (\count($lines) < $footer) {
            $lines[] = AnsiText::blank($width);
            $this->rowOwner[] = null;
        }
        $lines[] = $this->footer($width);
        $this->rowOwner[] = null;

        return $lines;
    }

    /**
     * One item's screen rows, each paired with its owner: the legend rule
     * between two blank rows if the item opens a fieldset (all nobody's), then
     * the control's rows with the label, the modified marker and the detail on
     * the control's middle row.
     *
     * @return list<array{string, int|null}>
     */
    private function item(int $index, SettingsRowLayout $layout, int $width, int $top): array
    {
        $row = $this->rows[$index];
        $out = [];
        $legend = $layout->legends[$index] ?? null;
        if (null !== $legend) {
            $out[] = [AnsiText::blank($width), null];
            $out[] = [Rule::horizontal($width, $this->styles, $legend), null];
            $out[] = [AnsiText::blank($width), null];
        }
        $control = $this->control($row, $index, $layout);
        $middle = \intdiv(\count($control), num2: 2); // the label sits on the value row
        $this->controls[$index] = [
            'row' => $top + \count($out),
            'col' => SettingsRowLayout::LABEL_CHROME + $layout->label,
            'width' => SettingsRowKind::Bool === $row->kind ? AnsiUtils::visibleWidth($control[0]) : $layout->control, // a checkbox is as wide as it is drawn, boxed or not
            'height' => \count($control),
        ];
        // A button whose caption fell back to its own label leaves the label
        // column blank rather than saying it twice.
        $label = SettingsRowKind::Action === $row->kind && '' === (string) $row->value ? '' : $row->label;
        $detail = SettingsRowDetail::of($row, $this->translator);
        $editing = $index === $this->editing && null !== $this->editField;
        foreach ($control as $i => $part) {
            $text = $i === $middle
                ? $this->marker($row).AnsiText::padRight(SettingsRowLayout::label($label, $this->direction()), $layout->label)
                : AnsiText::blank(2 + $layout->label);
            $text .= $this->gap().$part.$this->valueEnd();
            // The detail takes what the control leaves and is cut on its own:
            // truncating the whole row spent its ellipsis on the control's
            // closing rail whenever the detail overflowed.
            $room = $width - AnsiUtils::visibleWidth($text);
            if ($i === $middle && '' !== $detail && $room > 3) {
                $text .= $this->styles->of(Role::Dim)->apply(AnsiText::truncate($this->detailSeparator().$detail, $room));
            }
            if ($i === $middle) {
                $this->recordTip($index, $row, AnsiUtils::visibleWidth($this->detailSeparator().$detail) > $room ? $detail : '');
            }
            // An overflowing value being edited must keep its caret on screen:
            // scroll the assembled row to the marker instead of a hard clip
            // that would drop it off the right edge (every other field does).
            $text = $editing && $i === $middle ? AnsiText::scrollLineToCaret($text, $width) : AnsiText::truncate($text, $width, pad: true);
            $out[] = [$this->shadeRow($text, $this->list->isSelected($index), $index === $this->hoveredIndex), $index];
        }

        return $out;
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
     * A control is machine data: `< dark >`, `[✓]`, `[Remove]`. Sat between a
     * Hebrew label and a Latin value, its opening delimiter has strong
     * right-to-left on one side and strong left-to-right on the other, so with
     * nothing to bind it it takes the paragraph direction, leaves for the far
     * side of the group, and mirrors on the way — the row then shows two `>`
     * and the value is not bracketed at all.
     *
     * The mark gives that delimiter something to hold on to. It takes no
     * column, so the label column keeps its alignment; and
     * {@see \Porta\I18n\Bidi} drops it on the way out, so the rendered row is
     * as wide as every measurer thinks it is.
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
        // The spaces go BETWEEN the marks: after the right-to-left one they
        // resolve with the label's side; after the left-to-right one they would
        // join the control's run and be drawn on its far side, leaving the label
        // against the box. Neither mark takes a column, so the label column and
        // the rendered width are those of the two spaces.
        return $this->translator->isRtl() ? Bidi::RTL_MARK.'  '.Bidi::LTR_MARK : '  ';
    }

    /**
     * The closing half of the pair {@see gap()} opens.
     *
     * One mark is not enough, and the checkbox is what proves it: with a mark
     * only in front, `[✓]`'s opening bracket binds left-to-right while its
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

    private function direction(): BaseDirection
    {
        return $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;
    }

    /**
     * What opens the detail clause after the control: ` · `, led in a
     * mirrored form by a right-to-left mark.
     *
     * The detail is prose and belongs mirrored, but a range opens with
     * digits, and digits after {@see valueEnd()}'s left-to-right mark resolve
     * left-to-right — `· 1-8` joined the control's run and was drawn between
     * the control and its label, shoving the box off the column its border
     * rows keep. The mark hands the clause back to the paragraph.
     */
    private function detailSeparator(): string
    {
        return ($this->translator->isRtl() ? Bidi::RTL_MARK : '').' · ';
    }

    /**
     * An item under the pointer says what its row could not: the detail cut
     * to fit — the hint, the range, where the value comes from — and what its
     * ● means. Anchored on the item's control rows.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $index = $this->rowOwner[$row] ?? null;
        if (null === $index || !\array_key_exists($index, $this->tips) || !\array_key_exists($index, $this->controls)) {
            return null;
        }
        $text = $this->tips[$index];
        $control = $this->controls[$index];

        return new Tooltip('settings:'.$this->rows[$index]->key, $text, $control['row'], 0, $this->drawnWidth, $control['height']);
    }

    /** The item's tooltip, when its row cut its $cutDetail (or '' when it drew it whole) or it carries the modified mark. */
    private function recordTip(int $index, SettingsRow $row, string $cutDetail): void
    {
        $lines = '' === $cutDetail ? [] : [$cutDetail];
        if ($row->isModified()) {
            $lines[] = $this->translator->trans('Changed from its default — r puts it back');
        }
        if ([] !== $lines) {
            $this->tips[$index] = \implode("\n", $lines);
        }
    }

    private function marker(SettingsRow $row): string
    {
        return $row->isModified()
            ? $this->styles->of(Role::Modified)->apply(Glyphs::SETTING_MODIFIED).' '
            : '  ';
    }

    /**
     * A row's control: the part that shows the value and that actuating moves,
     * as its rows — {@see FieldBox::ROWS} when the page is boxed, a checkbox
     * too (its own box, `╭───╮ │ ✓ │ ╰───╯`), and one when the pane is too narrow,
     * where a checkbox is `[✓]`.
     *
     * Every kind renders it in the SAME column, which is the whole point —
     * `Bool` used to put its checkbox at column 1, ahead of its label, while
     * the others put theirs after.
     *
     * @return list<string>
     */
    private function control(SettingsRow $row, int $index, SettingsRowLayout $layout): array
    {
        $state = $this->state($row, $index);
        $rows = $layout->boxed ? FieldBox::ROWS : 1;
        $width = $layout->control;

        return match ($row->kind) {
            SettingsRowKind::Bool => FieldBox::ROWS === $rows
                ? FieldBox::checkboxBox((bool) $row->value, $state, $this->styles, tintMark: !$this->list->isSelected($index))
                : [FieldBox::checkbox((bool) $row->value, $state, $this->list->isSelected($index) ? null : $this->styles)],
            SettingsRowKind::Text => $this->textControl($row, $index, $state, $width, $rows),
            SettingsRowKind::Action => FieldBox::button($this->actionCaption($row), $state, $index === $this->hoveredIndex, $width, $rows, $this->styles),
            SettingsRowKind::Int => FieldBox::control(new FieldSpec(''), static fn (int $room): string => self::stepper((string) $row->value, $room), $state, $width, $rows, $this->styles),
            default => FieldBox::select(new FieldSpec(''), $this->choiceLabel($row), $state, $width, $rows, $this->styles),
        };
    }

    /**
     * An item's state, told by its control's border: dashed when it will not
     * act, double for the armed half of a destructive two-press confirm, heavy
     * while it is the item the focused form's keys go to, rounded otherwise.
     */
    private function state(SettingsRow $row, int $index): FieldState
    {
        return match (true) {
            $row->disabled => FieldState::Disabled,
            $row->danger => FieldState::Danger,
            default => FieldState::of($this->focused && $this->list->isSelected($index)),
        };
    }

    /** `◂  4  ▸`: the value centred between the two arrows a click steps by. */
    private static function stepper(string $value, int $room): string
    {
        return Glyphs::STEPPER_DOWN.' '.FieldBox::centred($value, \max(0, $room - 4)).' '.Glyphs::STEPPER_UP;
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

    /**
     * The key legend. Every one of these was undiscoverable: the form renders
     * no title, no footer and no affordance, so `[`/`]` stepping and the bare
     * `2` that jumps to Keybindings existed only in the keymap.
     */
    private function footer(int $width): string
    {
        $hint = match (true) {
            null !== $this->editing => $this->translator->trans('⏎ save · Esc cancel'),
            SettingsRowKind::Bool === ($this->rows[$this->list->selected()] ?? null)?->kind => $this->translator->trans('Space toggle · r reset · Ctrl+F find · Tab panes · 2 keybindings'),
            default => $this->translator->trans('⏎ edit · ←/→ change · r reset · Ctrl+F find · Tab panes · 2 keybindings'),
        };

        return $this->styles->merge(Role::Toolbar, Role::Dim)->apply(AnsiText::truncate(' '.$hint, $width, pad: true));
    }

    /**
     * A text row's control: its value in a field — the live editor, caret and
     * all, while it is being edited; otherwise the value from its START, the
     * way a browser shows an input you are not typing in.
     *
     * The hint used to stand in for an empty value here, which is why it never
     * reached any other kind and why it read as a placeholder rather than a
     * description. It lives in the detail column now, for every kind and
     * whether or not the row has a value.
     *
     * @return list<string>
     */
    private function textControl(SettingsRow $row, int $index, FieldState $state, int $width, int $rows): array
    {
        if ($index === $this->editing && null !== $this->editField) {
            $typing = $this->focused;
            $state = FieldState::of($typing);
            $caret = $typing ? CaretVisibility::Shown : CaretVisibility::Hidden;

            return FieldBox::field(new FieldSpec(''), $this->editField, $state, $caret, $width, $rows, $this->styles);
        }
        $value = (string) $row->value;

        return FieldBox::control(new FieldSpec(''), static fn (int $room): string => AnsiText::truncate($value, $room), $state, $width, $rows, $this->styles);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            // A select opens its list on the keys a browser's does; ←/→ still
            // step it without opening, like every other control here.
            'open_list' => [Key::ENTER, Key::SPACE],
            'toggle' => [Key::SPACE, Key::ENTER],
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
