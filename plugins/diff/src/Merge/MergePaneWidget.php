<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Merge;

use Porta\DiffPlugin\Widget\DiffViewRenderer;
use Porta\Editor\ConflictMarkers;
use Porta\Editor\ConflictSide;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * One side of the merge view — ours | result | theirs — beside the editor
 * that holds the result.
 *
 * Built twice: theirs is the registered preview, and ours is its
 * {@see flank()}, seated on the editor's other side. Both draw
 * {@see MergeSides}' rows level with the editor's lines — each row the line
 * the editor shows on that row, its pinned sticky-scroll headers included —
 * so the three columns scroll as one. Under soft wrap a line takes as many
 * rows here as the editor gives it: this side's text wraps at its own width,
 * padded when shorter, its last row cut with … when longer. A fold in the
 * result is not followed, nor a code lens's row. A conflict's first row is its
 * side's label behind an accept mark (⇥ from ours, ⇤ from theirs): a click on
 * it, or `a` with the keys here, keeps this side's lines for that conflict;
 * `b` keeps both. Every accept is one undo step in the editor.
 *
 * What the pane needs of the IDE arrives as closures, a plugin class being no
 * service: the result's current text, the caret and the editor's view, how
 * many rows the editor gives a line, a replace of the whole text, a jump, a
 * scroll and a save.
 */
final class MergePaneWidget extends EditorPaneWidget
{
    /** The theirs pane's routing id; ours, a flank, is never routed to. */
    public const string PANE_ID = 'merge';

    private string $path = '';

    /** @var list<string> the result as last fed, one entry per line */
    private array $lines = [];

    /** @var list<MergeRow> */
    private array $rows = [];

    /**
     * @param \Closure(): ?string                                    $text    the result as the editor has it now
     * @param \Closure(): (array{line: int, column: int, top: int, segment: int, sticky: list<int>}|null) $caret the caret, whose conflict the keys act on
     * @param \Closure(int): list<array{line: ?int, segment: ?int, of: int}> $screen what the editor shows on each of its first rows: a line's text segment of how many, null on a lens or block row, no line past the end
     * @param \Closure(string): void                                 $replace the result's whole text, as one undo step
     * @param \Closure(int): void                                    $jump    the caret to the start of a 0-based line
     * @param \Closure(int): void                                    $scroll  the editor's view by a number of rows, the caret where it is
     * @param \Closure(): void                                       $save    the result to disk, as the editor's own Ctrl+S
     */
    public function __construct(
        private readonly ConflictSide $side,
        private readonly Translator $translator,
        private readonly \Closure $text,
        private readonly \Closure $caret,
        private readonly \Closure $screen,
        private readonly \Closure $replace,
        private readonly \Closure $jump,
        private readonly \Closure $scroll,
        private readonly \Closure $save,
        ?Styles $styles = null,
        private readonly ?self $ours = null,
    ) {
        $this->initStyles($styles);
    }

    #[\Override]
    public function paneId(): string
    {
        return ConflictSide::Theirs === $this->side ? self::PANE_ID : self::PANE_ID.'-ours';
    }

    #[\Override]
    public function flank(): ?self
    {
        return $this->ours;
    }

    #[\Override]
    public function show(string $path, mixed $payload): void
    {
        $this->path = $path;
        $this->lines = \explode("\n", \is_string($payload) ? $payload : '');
        $this->rows = MergeSides::rows($this->lines, $this->side);
        $this->invalidate();
        $this->ours?->show($path, $payload);
    }

    #[\Override]
    public function clear(): void
    {
        $this->lines = [];
        $this->rows = [];
        $this->ours?->clear();
    }

    /** The caret moved to another line: the conflict it is in may be another one. */
    #[\Override]
    public function followSource(int $line, int $byteCol): void
    {
        $this->invalidate();
        $this->ours?->followSource($line, $byteCol);
    }

    /**
     * Which conflict of how many, and the keys — or, with none left, that the
     * result only needs saving.
     */
    #[\Override]
    public function statusRight(): string
    {
        $count = \count(ConflictMarkers::find($this->lines));
        if (0 === $count) {
            return [] === $this->lines ? '' : $this->translator->trans('No conflicts left — save the result');
        }

        return $this->translator->trans('Conflict %current% of %count%  a keep %side%  b both  n/p next/prev', [
            '%current%' => (string) ((MergeSides::current($this->lines, $this->caretLine()) ?? 0) + 1),
            '%count%' => (string) $count,
            '%side%' => $this->sideName(),
        ]);
    }

    /** A click on a conflict's label keeps this side for it. Never a drag. */
    #[\Override]
    public function press(int $relRow, int $relCol): bool
    {
        $at = $this->screenRows($relRow + 1)[$relRow] ?? null;
        $row = null !== $at && 0 === $at['segment'] ? $at['row'] : null;
        if (MergeRowKind::Header === $row?->kind && null !== $row->region) {
            $this->accept($row->region, $this->side);
        }

        return false;
    }

    /** A notch over a side scrolls the editor, which the three columns follow. */
    #[\Override]
    public function wheelBy(int $rows): void
    {
        ($this->scroll)($rows);
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $current = MergeSides::current($this->result(), $this->caretLine());
        match (true) {
            $kb->matches($data, 'merge_keep') => null === $current ? null : $this->accept($current, $this->side),
            $kb->matches($data, 'merge_keep_both') => null === $current ? null : $this->accept($current, ConflictSide::Both),
            $kb->matches($data, 'merge_next') => $this->step(1),
            $kb->matches($data, 'merge_prev') => $this->step(-1),
            // Re-bound to the editor's defaults so saving and tab management
            // keep working while this pane has the keys (see EditorKeymap):
            // the next thing after the last accept is the save.
            $kb->matches($data, 'save') => ($this->save)(),
            $kb->matches($data, 'next_buffer') => $this->handler?->cycleBuffer(1),
            $kb->matches($data, 'prev_buffer') => $this->handler?->cycleBuffer(-1),
            $kb->matches($data, 'close_buffer') => $this->handler?->closeBuffer(),
            $kb->matches($data, 'goto_file') => $this->handler?->openGotoFile(),
            $kb->matches($data, 'save_all') => $this->handler?->saveAll(),
            default => null,
        };
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $columns = \max(0, $context->getColumns());
        $height = \max(0, $context->getRows());
        $current = MergeSides::current($this->lines, $this->caretLine());
        $lines = [];
        foreach ($this->screenRows($height) as $at) {
            $lines[] = $this->renderRow($at['row'], $at['segment'], $at['of'], $current, $columns);
        }

        return $this->withMenuOverlay($lines, $columns);
    }

    /**
     * Row $segment of the $of the editor gives this line's text: the side's
     * text from where the rows above left it, the last of them cut with … if
     * there is more — at one row each, the whole line cut as it always was. A
     * null $segment is a code lens or a line block in the editor: blank here.
     */
    private function renderRow(?MergeRow $row, ?int $segment, int $of, ?int $current, int $columns): string
    {
        if (null === $row || null === $segment || 0 === $columns) {
            return AnsiText::blank($columns);
        }
        $inner = $columns - 1; // the one-column indent
        $shown = DiffViewRenderer::shown($row->text);
        // Column-exact, as the diff pane slices: a wide glyph across a break leaves a blank, never shifts the row.
        $from = $segment * $inner;
        $text = AnsiText::truncate(' '.AnsiText::columnsFrom($shown, $from, $segment < ($of - 1) ? $inner : \max(0, DiffViewRenderer::columns($shown) - $from)), $columns, pad: true);

        return match ($row->kind) {
            MergeRowKind::Context => $this->styles->of(Role::Dim)->apply($text),
            // The label on the conflict's first row; the rest of its rows the header's colour, empty.
            MergeRowKind::Header => 0 === $segment ? $this->header($row, $row->region === $current, $columns) : $this->styles->of(Role::Hunk)->apply(AnsiText::blank($columns)),
            // What this side keeps, on either side: added to the result if accepted.
            MergeRowKind::Side => $this->styles->of(Role::AddedRowBg)->apply($text),
            MergeRowKind::Filler => AnsiText::blank($columns),
        };
    }

    /** A conflict's label behind its accept mark, emphasised on the conflict the keys act on. */
    // @mago-expect lint:no-boolean-flag-parameter -- whether this is the conflict the keys act on is the one fact the row's emphasis shows.
    private function header(MergeRow $row, bool $current, int $columns): string
    {
        $mark = ConflictSide::Theirs === $this->side ? '⇤' : '⇥';
        $label = '' === $row->text ? $this->sideName() : $row->text;
        $style = $this->styles->of(Role::Hunk);

        return ($current ? $style->withBold() : $style)->apply(AnsiText::truncate(' '.$mark.' '.$label, $columns, pad: true));
    }

    /**
     * Keep $side's lines for conflict $index in the result, then put the
     * caret where it was — and draw the resolved result at once, both sides:
     * a second accept before the feed catches up must find the conflicts as
     * they are now.
     */
    private function accept(int $index, ConflictSide $side): void
    {
        $lines = $this->result();
        $start = ConflictMarkers::find($lines)[$index]['start'] ?? null;
        $resolved = MergeSides::accept($lines, $index, $side);
        if (null === $start || null === $resolved) {
            return;
        }
        $text = \implode("\n", $resolved);
        ($this->replace)($text);
        $this->show($this->path, $text);
        ($this->jump)($start);
    }

    /**
     * The result as the editor has it now, else as last fed.
     *
     * @return list<string>
     */
    private function result(): array
    {
        $text = ($this->text)();

        return null === $text ? $this->lines : \explode("\n", $text);
    }

    /**
     * What each of the first $height rows is level with in the editor, as the
     * editor says it draws them: its pinned headers, then its lines from the
     * row the view starts at, past folds, each for its lens rows, its text's
     * rows and its block rows. With no view from the editor, nothing.
     *
     * @return list<array{row: ?MergeRow, segment: ?int, of: int}>
     */
    private function screenRows(int $height): array
    {
        $screen = [];
        foreach (($this->screen)($height) as $at) {
            $screen[] = ['row' => null === $at['line'] ? null : $this->rows[$at['line']] ?? null, 'segment' => $at['segment'], 'of' => $at['of']];
        }

        return \array_pad($screen, \max(0, $height), value: ['row' => null, 'segment' => 0, 'of' => 1]);
    }

    /** The caret to the next ($direction 1) or previous (-1) conflict's first line, wrapping. */
    private function step(int $direction): void
    {
        $starts = \array_column(ConflictMarkers::find($this->result()), 'start');
        if ([] === $starts) {
            return;
        }
        $line = $this->caretLine();
        $ahead = $direction > 0 ? $starts : \array_reverse($starts);
        $target = \array_find($ahead, static fn (int $start): bool => $direction > 0 ? $start > $line : $start < $line) ?? $ahead[0];
        ($this->jump)($target);
    }

    private function caretLine(): int
    {
        return ($this->caret)()['line'] ?? 0;
    }

    private function sideName(): string
    {
        return ConflictSide::Theirs === $this->side ? $this->translator->trans('theirs') : $this->translator->trans('ours');
    }

    /** @return array<string, string[]> */
    #[\Override]
    protected static function getDefaultKeybindings(): array
    {
        return [
            'merge_keep' => ['a', Key::ENTER],
            'merge_keep_both' => ['b'],
            'merge_next' => ['n'],
            'merge_prev' => ['p'],
            'save' => ['ctrl+s'],
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
            'save_all' => ['ctrl+alt+s'],
        ];
    }
}
