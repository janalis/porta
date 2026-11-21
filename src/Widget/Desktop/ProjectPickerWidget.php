<?php

declare(strict_types=1);

namespace Porta\Widget\Desktop;

use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\ModalRowStyle;
use Porta\Widget\Render\Rule;
use Porta\Widget\Render\SelectionBar;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\PickerRow;
use Porta\Widget\Support\RowSelection;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The first screen of a desktop window with no project: Porta's name, one
 * field that takes a project's name or a folder's path, and the rows that
 * field turns up, in a centred card.
 *
 * What the rows ARE is the caller's ({@see \Porta\Desktop\ProjectPicker}):
 * this draws them, moves the selection over the ones that can be chosen, and
 * hands back what Enter and Tab were pressed on. Headings are rules with a
 * legend, so a section reads by its shape under the monochrome theme too.
 *
 * Runs before the IDE boots, on a bare Tui: nothing here may need the
 * container, the IDE's keymap or its pointer routing.
 */
final class ProjectPickerWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use StylesDefault;

    /** The card never grows past this: a list of names and paths is hard to scan wider. */
    private const int CARD_MAX = 64;

    /** Columns kept clear either side of the card. */
    private const int MARGIN = 2;

    /** Below this many rows the name and tagline go: the list needs the room. */
    private const int HEADER_MIN_ROWS = 16;

    /** The widest a label column grows before the hints beside it get the rest. */
    private const int LABEL_MAX = 28;

    private readonly TextField $field;

    private readonly ListSelection $list;

    /** @var list<PickerRow> */
    private array $rows;

    /** Which line of the list area is the first drawn. */
    private int $scroll;

    /** List rows the last render had room for: the page size. */
    private int $page = 1;

    private string $problem;

    /**
     * @param \Closure(string): list<PickerRow>          $rowsFor  the rows a typed text turns up
     * @param \Closure(self, ?PickerRow, string): void   $onSubmit Enter: this picker, the selected row (null when none) and the text
     * @param \Closure(?PickerRow, string): string       $onTab    Tab: what the field holds next
     * @param \Closure(): void                           $onCancel Esc
     * @param list<string>                               $footer   the key hints under the card, a line each
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly string $version,
        private readonly \Closure $rowsFor,
        private readonly \Closure $onSubmit,
        private readonly \Closure $onTab,
        private readonly \Closure $onCancel,
        private readonly array $footer,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
        $this->field = new TextField();
        $this->list = new ListSelection();
        $this->refresh();
    }

    /** Puts $text in the field, the caret at its end, and lists what it turns up. */
    public function setText(string $text): void
    {
        $this->field->setValue($text);
        $this->refresh();
    }

    /** Says why the text cannot be opened, under the field, until it changes. */
    public function refuse(string $problem): void
    {
        $this->problem = $problem;
        $this->invalidate();
    }

    /** @return array<string, list<string>> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE],
            'complete' => [Key::TAB],
            'backspace' => [Key::BACKSPACE],
            'clear' => ['ctrl+u'],
        ];
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $selectable = \count($this->selectable());

        match (true) {
            $kb->matches($data, action: 'cancel') => ($this->onCancel)(),
            $kb->matches($data, action: 'confirm') => ($this->onSubmit)($this, $this->selected(), $this->field->value()),
            $kb->matches($data, action: 'complete') => $this->setText(($this->onTab)($this->selected(), $this->field->value())),
            ListNavigation::handleKey($data, $kb, $this->list, $selectable, page: $this->page) => $this->invalidate(),
            $kb->matches($data, action: 'clear') => $this->setText(''),
            $kb->matches($data, action: 'backspace') ? $this->field->backspace() : $this->field->editKey($data, $kb) || $this->field->insert($data) => $this->refresh(),
            default => null,
        };
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = \max(1, $context->getColumns());
        $height = \max(1, $context->getRows());
        $card = \max(1, \min($width - (2 * self::MARGIN), self::CARD_MAX));
        $left = AnsiText::blank(\max(0, \intdiv($width - $card, num2: 2)));
        $place = static fn (string $line): string => AnsiText::truncate($left.$line, $width, pad: true);

        $lines = $height >= self::HEADER_MIN_ROWS ? $this->header($width) : [];
        foreach ($this->fieldRows($card) as $row) {
            $lines[] = $place($row);
        }
        $lines[] = $place('' === $this->problem ? '' : $this->styles->of(Role::DiagError)->apply(AnsiText::truncate($this->problem, $card)));

        $footer = \array_map(fn (string $hint): string => $this->centred($this->styles->of(Role::Dim)->apply($hint), $width), $this->footer);
        $this->page = \max(1, $height - \count($lines) - \count($footer) - 1);
        foreach ($this->listRows($card, $this->page) as $row) {
            $lines[] = $place($row);
        }
        $lines = \array_pad($lines, \max(\count($lines), $height - \count($footer)), AnsiText::blank($width));

        return \array_slice([...$lines, ...$footer], offset: 0, length: $height);
    }

    /**
     * Porta's name and version, and what it is, centred over the card.
     *
     * @return list<string>
     */
    private function header(int $width): array
    {
        $name = $this->styles->of(Role::Bright)->withBold()->apply('Porta').' '.$this->styles->of(Role::Dim)->apply($this->version);

        return [
            AnsiText::blank($width),
            $this->centred($name, $width),
            $this->centred($this->styles->of(Role::Dim)->apply($this->translator->trans('A terminal IDE for PHP projects')), $width),
            AnsiText::blank($width),
        ];
    }

    /** @return list<string> */
    private function fieldRows(int $card): array
    {
        $spec = new FieldSpec($this->translator->trans('Open a project'), placeholder: $this->translator->trans('Type a name or a path'));

        return FieldBox::render($spec, $this->field, FieldState::Focused, CaretVisibility::Shown, \min($card, FieldBox::MAX_WIDTH), $this->styles);
    }

    /**
     * The rows that fit in $room lines, the window moved just enough to keep
     * the selection in it — and the heading above it, when the selection is
     * a section's first row, so a row is never shown without its section.
     *
     * @return list<string>
     */
    private function listRows(int $card, int $room): array
    {
        $at = $this->selectedLine();
        if (null !== $at) {
            $top = $at > 0 && !$this->rows[$at - 1]->isSelectable() ? $at - 1 : $at;
            $this->scroll = \min($this->scroll, $top);
            $this->scroll = \max($this->scroll, $at - $room + 1);
        }
        $this->scroll = \max(0, \min($this->scroll, \count($this->rows) - $room));

        $labels = \array_map(static fn (PickerRow $row): int => $row->isSelectable() ? AnsiUtils::visibleWidth($row->label) : 0, $this->rows);
        $labelWidth = \min(self::LABEL_MAX, \max([0, ...$labels]));
        $lines = [];
        foreach (\array_slice($this->rows, $this->scroll, $room, preserve_keys: true) as $index => $row) {
            $lines[] = $this->row($row, RowSelection::of($index === $at, hovered: false), $card, $labelWidth);
        }

        return $lines;
    }

    private function row(PickerRow $row, RowSelection $state, int $card, int $labelWidth): string
    {
        $selected = RowSelection::Selected === $state;
        if (!$row->isSelectable()) {
            return '' === $row->hint ? Rule::horizontal($card, $this->styles, $row->label) : $this->styles->of(Role::Dim)->apply(AnsiText::truncate(' '.$row->hint, $card));
        }
        $bar = SelectionBar::of($selected, focused: true)->value.' ';
        $label = AnsiText::truncate($row->label, $labelWidth, pad: true);
        $text = AnsiText::truncate($bar.($selected ? $this->styles->of(Role::Bright)->withBold()->apply($label) : $label).'  '.$this->styles->of(Role::Dim)->apply($row->hint), $card, pad: true);

        // The selection's own shade; the other rows sit on the card, not a popup's.
        return $selected ? $this->styles->of(ModalRowStyle::role($state))->apply($text) : $text;
    }

    private function centred(string $text, int $width): string
    {
        return AnsiText::truncate(AnsiText::blank(\max(0, \intdiv($width - AnsiUtils::visibleWidth($text), num2: 2))).$text, $width, pad: true);
    }

    /** The rows for what the field holds now, the selection back on the first that can be chosen. */
    private function refresh(): void
    {
        $this->rows = ($this->rowsFor)($this->field->value());
        $this->list->reset();
        $this->scroll = 0;
        $this->problem = '';
        $this->invalidate();
    }

    /** @return list<int> the indexes of the rows that can be chosen, in order */
    private function selectable(): array
    {
        return \array_keys(\array_filter($this->rows, static fn (PickerRow $row): bool => $row->isSelectable()));
    }

    private function selectedLine(): ?int
    {
        return $this->selectable()[$this->list->selected()] ?? null;
    }

    private function selected(): ?PickerRow
    {
        $line = $this->selectedLine();

        return null === $line ? null : $this->rows[$line];
    }
}
