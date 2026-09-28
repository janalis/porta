<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Editor\DiagnosticSeverity;
use Porta\Event\TreeDropRequested;
use Porta\Event\TreeFileActivated;
use Porta\Layout\ResizableSidebar;
use Porta\Support\AnsiText;
use Porta\Tree\FileDecorations;
use Porta\Tree\FileTreeNode;
use Porta\Ui\ClickIntent;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\MultiSelection;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\WheelScrollable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Project file tree with lazy directory expansion.
 */
#[AsIdeService]
#[AsWidget('tree', flex: 0)]
final class FileTreeWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, ResizableSidebar, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PanelWidth;
    use StylesDefault;

    // '.porta' is the IDE's own session/history metadata dir; '.porta.json' (the
    // project config file) is not matched by the exact-name check and still shows.
    private const array IGNORED = ['.git', '.porta', 'node_modules', '.idea', '.vscode', '.DS_Store'];

    /** @var array<string, bool> expanded state by path */
    private array $expanded = [];
    private readonly ListSelection $list;
    private readonly MultiSelection $selection;
    private int $lastRows = 24;

    /** Columns the name keeps for itself before the payload label stands down. */
    private const int DROP_LABEL_FLOOR = 8;

    /** The directory a drag in progress would drop into, or null when no drag is live. */
    private ?string $dropTarget = null;

    /**
     * What the live drag is carrying, already translated, or '' for none.
     *
     * A rendered string rather than a count because the widget has no
     * translator and no business gaining one for this: the router that owns the
     * gesture knows how many paths it picked up and already translates for the
     * status bar.
     */
    private string $dropLabel = '';

    /** A directory row whose expand/collapse is waiting to learn whether the press was a click. */
    private ?int $pendingToggle = null;

    /** @var list<FileTreeNode> */
    private array $visible = [];

    /**
     * Change-detection state per directory scanned in the last rebuild: the
     * entry-list signature plus the directory mtime read just before it, so
     * the poll can skip the scandir when the mtime provably hasn't moved.
     *
     * @var array<string, array{sig: string, mtime: int}>
     */
    private array $scannedSigs = [];

    /** The {@see refreshDirsSlice} scan position, persisted across poll ticks. */
    private int $dirCursor = 0;

    /** The root in realpath (buffer) space; the raw root itself when it does not resolve. */
    private readonly string $realRoot;

    /**
     * Last frame's per-file marks, absolute path => state. Held rather than
     * pulled inside the row loop because the rendered lines are memoized on the
     * render revision, which an edit in the EDITOR never bumps — so the marks
     * are compared here and the memo dropped when they move.
     *
     * @var array<string, array{dirty: bool, severity: DiagnosticSeverity|null, status: \Porta\Editor\ChangeMarkKind|null}>
     */
    private array $fileMarks = [];

    /**
     * The last rendered lines and their (revision, width, rows) key. A private
     * memo rather than the widget cache because the vendor's layout engine
     * intrinsic-measures every flex:0 child per frame with render() +
     * clearRenderCache() (LayoutEngine + Renderer::measureIntrinsicWidth), so
     * the stock cache is force-cleared twice per frame and the tree would
     * otherwise re-render twice on EVERY editor scroll frame. Everything that
     * changes the tree's output — selection, expand, hover, drag-resize, the
     * menu overlay — goes through invalidate(), which bumps the revision.
     *
     * @var list<string>|null
     */
    private ?array $memoLines = null;

    /** @var array{int, int, int}|null */
    private ?array $memoKey = null;

    /**
     * An unchanged dir mtime older than this many seconds is trusted without a
     * scandir; younger ones are re-scanned because a same-second rename can
     * leave the mtime identical.
     */
    private const int POLL_GRACE_SECONDS = 2;

    public function __construct(
        private readonly string $rootDir,
        private readonly ClockInterface $clock = new NativeClock(),
        int $width = 30,
        ?Styles $styles = null,
        private readonly ?FileDecorations $fileDecorations = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->realRoot = ($resolved = \realpath($rootDir)) ? $resolved : $rootDir;
        $this->list = new ListSelection();
        $this->selection = new MultiSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->rebuild();
    }

    /**
     * The currently selected node, or null when the tree is empty. The keyboard
     * counterpart of {@see nodeAt} for opening the context menu without a click.
     */
    public function selectedNode(): ?FileTreeNode
    {
        return $this->visible[$this->list->selected()] ?? null;
    }

    /** The selected entry's widget-relative row (row 0 is the header). */
    public function selectedRow(): int
    {
        return $this->list->selected() - $this->list->scroll() + 1;
    }

    /**
     * Every selected FILE, in the order the tree shows them.
     *
     * Tree order, not click order, because the commands that read this compare
     * or act on a pair and "the first one" has to mean the same thing however
     * it was picked. Directories are left out: nothing selects a directory to
     * act on it, and a caller that wanted one would have to filter anyway.
     *
     * @return list<string>
     */
    public function selectedPaths(): array
    {
        $paths = [];
        foreach ($this->visible as $node) {
            if ($node->isDir || !$this->selection->has($node->path)) {
                continue;
            }
            $paths[] = $node->path;
        }

        return $paths;
    }

    #[\Override]
    public function minWidth(): int
    {
        return 12;
    }

    #[\Override]
    public function maxRatio(): float
    {
        return 0.6;
    }

    /**
     * The tree node under a click at row $relRow (0 = header), selecting it, or
     * null when the click missed every entry. Used to target a right-click.
     */
    public function nodeAt(int $relRow): ?FileTreeNode
    {
        $index = $this->list->rowIndexAt($relRow, \count($this->visible));
        if (null === $index) {
            return null;
        }
        $this->list->select($index);
        // Right-clicking INSIDE the selection keeps it — that is how a menu
        // acts on the pair you built. Right-clicking outside it collapses to
        // the row under the pointer, because a menu that silently acts on two
        // files you are not pointing at is worse than one that acts on one.
        $node = $this->visible[$index];
        if (!$this->selection->has($node->path)) {
            $this->selection->only($node->path);
        }
        $this->invalidate();

        return $node;
    }

    /**
     * The node at a widget-relative row, looked up and nothing else.
     *
     * The pure half of {@see nodeAt()}, which also moves the cursor and may
     * collapse the selection. A drag has to know what it grabbed BEFORE the
     * click that follows decides what the selection becomes, and asking the
     * mutating one would make the question change its own answer.
     */
    public function nodeAtRow(int $relRow): ?FileTreeNode
    {
        $index = $this->list->rowIndexAt($relRow, \count($this->visible));

        return null === $index ? null : $this->visible[$index];
    }

    /**
     * A left press on a row, with a directory's expand/collapse HELD BACK
     * until {@see commitPress()} says the press was a click.
     *
     * Pressing a folder to drag it used to toggle it, which is not a cosmetic
     * annoyance: the rows below it move, so by the first motion event the row
     * under the pointer is a different node and the drop lands somewhere
     * nobody aimed at. Deferring is the shape the graph pane's drag already
     * uses — a release with no motion becomes the click.
     *
     * Only the toggle waits. A file still activates on the press, which is
     * this tree's documented behaviour and what its tests hold.
     *
     * Returns what a drag from this press would carry, because the caller needs
     * both and the two answers must agree. Read here rather than asked for
     * separately: the sources have to be resolved BEFORE the click runs (which
     * may collapse the selection to the row under the pointer), and the caller
     * previously kept that ordering by comment while deriving "is this press
     * modified" a second time, from the modifier keys rather than from the
     * intent. Two spellings of one fact is how a press came to defer a toggle
     * with no drag armed to spend it.
     *
     * @return list<string> absolute paths, in tree order; empty when the press
     *                      grabbed nothing draggable
     */
    public function pressRow(int $relRow, ClickIntent $intent = ClickIntent::Open): array
    {
        $sources = $this->dragSourcesAt($relRow, ClickIntent::Open !== $intent);
        $node = $this->nodeAtRow($relRow);
        if (ClickIntent::Open !== $intent || null === $node || !$node->isDir) {
            $this->clickRow($relRow, $intent);

            return $sources;
        }
        // Select it — the same collapse-unless-already-in rule a right-click
        // follows — and remember what a click would still owe.
        $this->nodeAt($relRow);
        $this->pendingToggle = $relRow;

        return $sources;
    }

    /** The press turned out to be a click: pay what {@see pressRow()} deferred. */
    public function commitPress(): void
    {
        $row = $this->pendingToggle;
        $this->pendingToggle = null;
        if (null !== $row) {
            $this->clickRow($row);
        }
    }

    /** The press turned out to be a drag: the deferred toggle is abandoned. */
    public function forgetPress(): void
    {
        $this->pendingToggle = null;
    }

    /**
     * What a drag starting at this row would carry.
     *
     * Dragging a row that is IN the selection carries the whole selection —
     * that is what the selection is for. Dragging one outside it carries just
     * that row, without disturbing the selection, because the click that
     * follows collapses it anyway.
     *
     * Empty — no drag — for the header row, for a $modified press (Ctrl and
     * Shift are building a selection, not grabbing one), and a directory
     * always travels alone: {@see selectedPaths()} answers with files only.
     *
     * The sibling of {@see \Porta\Widget\DockTabsWidget::dragSourceAt()},
     * and read BEFORE the click runs for the same reason.
     *
     * @return list<string>
     */
    // @mago-expect lint:no-boolean-flag-parameter -- $modified is one fact with two values: the press is building a selection rather than grabbing one.
    public function dragSourcesAt(int $relRow, bool $modified): array
    {
        $node = $modified ? null : $this->nodeAtRow($relRow);
        if (null === $node) {
            return [];
        }
        $selected = $this->selectedPaths();

        return $node->isDir || !\in_array($node->path, $selected, strict: true) ? [$node->path] : $selected;
    }

    /**
     * The directory a drop at this row would land in: the node itself when it
     * is a folder, its parent when it is a file. PhpStorm's rule, and the one
     * that makes a tree of files droppable at all.
     */
    public function dropDirAt(int $relRow): ?string
    {
        $node = $this->nodeAtRow($relRow);
        if (null === $node) {
            return null;
        }

        return $node->isDir ? $node->path : \dirname($node->path);
    }

    /**
     * Say that a file should be opened, as a click on its row would.
     *
     * The same event the click path dispatches, so a drop on the editor and a
     * click on the row reach the editor by one route rather than two.
     */
    public function requestOpen(string $path): void
    {
        $this->events?->dispatch(new TreeFileActivated($path));
    }

    /**
     * Say that a drag was dropped onto $targetDir.
     *
     * The widget owns the gesture and no model, so it reports rather than
     * acts — the shape {@see \Porta\Widget\DockTabsWidget::moveTab()} uses
     * for a reorder.
     *
     * @param list<string> $sources
     */
    public function requestDrop(array $sources, string $targetDir, bool $copy): void
    {
        $this->events?->dispatch(new TreeDropRequested($sources, $targetDir, $copy));
    }

    /**
     * Scroll the tree by $delta rows under a live drag, without moving what the
     * drag is aiming at. Returns whether it moved.
     *
     * A drag can only ever land on a row that is on screen, and on any real
     * project the folder being aimed at is usually exactly the one that is not.
     * Every other drag in this codebase CLAMPS the pointer into its rect
     * instead, which is right for a text selection (the document scrolls with
     * the caret) and useless for a tree, where the target is a row rather than
     * a point.
     *
     * The window moves on its own rather than by moving the selection, because
     * the selection is what the next click acts on — see {@see
     * \Porta\Ui\ListSelection::scrollWindow()} for why it still comes along
     * when the window would otherwise leave it behind.
     */
    public function scrollDuringDrag(int $delta): bool
    {
        // Row 0 is the header, so the window is one row shorter than the pane.
        if (!$this->list->scrollWindow($delta, \count($this->visible), $this->lastRows - 1)) {
            return false;
        }
        $this->invalidate();

        return true;
    }

    /**
     * Open a collapsed directory under a live drag, so the drag can reach the
     * rows inside it. Returns whether anything opened.
     *
     * Dropping ON a collapsed folder already works — it is a directory, so it
     * is a target. What is unreachable is anything INSIDE one, and a tree opens
     * collapsed, so on a fresh session that is most of the project.
     *
     * Deliberately NOT the toggle {@see clickRow()} uses. That one is a toggle,
     * and a drag resting on an OPEN folder must not close it; more to the
     * point, the press has already deferred a toggle of its own (see {@see
     * pressRow()}) and paying or replacing that debt here is how a folder
     * nobody touched ends up opening on the next unrelated click.
     */
    public function expandDuringDrag(string $path): bool
    {
        $node = null;
        foreach ($this->visible as $candidate) {
            if ($candidate->path !== $path) {
                continue;
            }

            $node = $candidate;
            break;
        }
        if (null === $node || !$node->isDir || ($this->expanded[$path] ?? false)) {
            return false;
        }
        $this->expanded[$path] = true;
        $this->rebuild();
        $this->invalidate();

        return true;
    }

    /**
     * Mark a directory as the pending drop of a drag in progress, or null to
     * clear it.
     *
     * Painted in {@see Role::DropTargetRow}, and $label — already translated by
     * the caller — trails the row to say what would land on it.
     *
     * It borrowed the hovered-directory tint at first, on the reasoning that a
     * button-held motion arrives as `isLeftDrag()` and never `isMove()`, so the
     * hover router is dormant during a drag and there was no second highlight
     * to be confused with. True, and beside the point: the two styles were
     * byte-identical, so "your files are going here" and "the pointer is here"
     * were the same row.
     */
    public function setDropTarget(?string $path, string $label = ''): void
    {
        if ($path === $this->dropTarget && $label === $this->dropLabel) {
            return;
        }
        $this->dropTarget = $path;
        $this->dropLabel = $label;
        $this->invalidate();
    }

    /**
     * The payload label trailing the drop-target row, or '' when this is not
     * that row or the pane cannot spare the columns.
     *
     * The floor is the point. A row whose visible width disagrees with the
     * width it declares throws out of the render loop and takes the session
     * with it, so this is a bound that can REFUSE: below the floor the label
     * goes entirely rather than eating the filename it sits beside.
     */
    private function dropTrailing(FileTreeNode $node, int $room): string
    {
        if ($node->path !== $this->dropTarget || '' === $this->dropLabel) {
            return '';
        }
        $label = ' '.$this->dropLabel.' ';

        return (AnsiUtils::visibleWidth($label) + self::DROP_LABEL_FLOOR) <= $room ? $label : '';
    }

    public function refresh(): void
    {
        $this->rebuild();
        $this->list->clamp(\count($this->visible));
        $this->invalidate();
    }

    /**
     * Expand to and select a file by absolute path (a double-clicked editor
     * tab), so it shows highlighted in the tree. A no-op for a path outside the
     * root or one the rebuild does not surface (e.g. an ignored directory).
     */
    public function revealPath(string $path): void
    {
        // Buffer paths are realpath'd (symlinks resolved) while the tree's nodes
        // are built from the raw root, so compare in realpath space, then map the
        // target back to the raw-root path the node/expansion keys use.
        $root = \realpath($this->rootDir);
        $target = \realpath($path);
        if (false === $root || false === $target || !\str_starts_with($target, $root.\DIRECTORY_SEPARATOR)) {
            return;
        }
        $path = $this->rootDir.\DIRECTORY_SEPARATOR.\substr($target, \strlen($root) + 1);
        // Expand every ancestor directory between the root and the file.
        $dir = \dirname($path);
        while (\strlen($dir) > \strlen($this->rootDir)) {
            $this->expanded[$dir] = true;
            $dir = \dirname($dir);
        }
        $this->rebuild();
        foreach ($this->visible as $i => $node) {
            if ($node->path !== $path) {
                continue;
            }

            $this->list->select($i);
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
        $count = \count($this->visible);
        if (0 === $count) {
            return;
        }

        if ($kb->matches($data, 'select_up') || $kb->matches($data, 'select_down')) {
            $step = $kb->matches($data, 'select_up') ? -1 : 1;
            $index = \max(0, \min($count - 1, $this->list->selected() + $step));
            $this->list->select($index);
            $this->extendSelection($index, ClickIntent::Range);
            $this->invalidate();

            return;
        }
        // Row 0 is the header, so a page is one row SHORTER than the pane —
        // the same budget render() gives follow() and scrollWindow() gets above.
        // Paging by the full height scrolled one entry past the bottom of the
        // screen every press, and the entry you landed on had never been drawn.
        // No floor, for the reason scrollWindow() has none: a pane with no room
        // to draw an entry has no page to turn, and ListNavigation already
        // ignores a page of zero.
        if (ListNavigation::handleKey($data, $kb, $this->list, $count, page: $this->lastRows - 1)) {
            // Moving the cursor with no modifier collapses the selection to the
            // row under it: a pair is built deliberately, and a stale second
            // file surviving a walk through the tree is how a compare ends up
            // diffing something nobody meant.
            $this->selection->only($this->visible[$this->list->selected()]->path);
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'collapse')) {
            $node = $this->visible[$this->list->selected()];
            if ($node->isDir && ($this->expanded[$node->path] ?? false)) {
                $this->expanded[$node->path] = false;
                $this->rebuild();
                $this->invalidate();

                return;
            }
            if ($node->depth > 0) {
                // Jump to parent
                for ($i = $this->list->selected() - 1; $i >= 0; --$i) {
                    if ($this->visible[$i]->depth >= $node->depth) {
                        continue;
                    }

                    $this->list->select($i);
                    break;
                }
            }
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'expand')) {
            $node = $this->visible[$this->list->selected()];
            if ($node->isDir && !($this->expanded[$node->path] ?? false)) {
                $this->expanded[$node->path] = true;
                $this->rebuild();
                $this->invalidate();
            }

            return;
        }
        if ($kb->matches($data, 'open')) {
            $node = $this->visible[$this->list->selected()];
            if ($node->isDir) {
                $this->expanded[$node->path] = !($this->expanded[$node->path] ?? false);
                $this->rebuild();
                $this->invalidate();

                return;
            }
            $this->events?->dispatch(new TreeFileActivated($node->path));

            return;
        }
        if ($kb->matches($data, 'reload')) {
            $this->refresh();
        }
    }

    /**
     * Add row $index to the selection: as one more row (Ctrl), or as the range
     * from the anchor (Shift).
     *
     * A Shift with no anchor behaves like a Ctrl — there is no range without a
     * start, and refusing to select anything would read as the key not working.
     */
    private function extendSelection(int $index, ClickIntent $intent): void
    {
        $path = $this->visible[$index]->path;
        $anchor = $this->selection->anchor();
        if (ClickIntent::Add === $intent || null === $anchor) {
            $this->selection->toggle($path);

            return;
        }

        // The anchor is always a visible row — {@see MultiSelection::keepOnly()}
        // drops it with the file it named — so the fallback is this row alone,
        // which degrades a range into a plain selection rather than throwing.
        $from = $this->rowOf($anchor) ?? $index;
        $range = [];
        foreach (\range($from, $index) as $row) {
            $range[] = $this->visible[$row]->path;
        }
        $this->selection->replaceRange($range);
    }

    /** Where $path sits in the visible rows, or null when it is not there. */
    private function rowOf(string $path): ?int
    {
        $rows = \array_keys(\array_filter($this->visible, static fn (FileTreeNode $node): bool => $node->path === $path));

        return $rows[0] ?? null;
    }

    /**
     * Handle a click at row $relRow of the tree (0 = header). Selects the
     * entry and opens the file / toggles the directory, mirroring Enter.
     *
     * Any intent but {@see ClickIntent::Open} only SELECTS: a modified click is
     * how a pair is built, and opening a file on the way would replace the
     * editor the user is about to compare against.
     */
    public function clickRow(int $relRow, ClickIntent $intent = ClickIntent::Open): void
    {
        $index = $this->list->rowIndexAt($relRow, \count($this->visible));
        if (null === $index) {
            return;
        }
        if (ClickIntent::Open !== $intent) {
            $this->list->select($index);
            $this->extendSelection($index, $intent);
            $this->invalidate();

            return;
        }
        $this->list->select($index);
        $this->selection->only($this->visible[$index]->path);
        $node = $this->visible[$index];
        if ($node->isDir) {
            $this->expanded[$node->path] = !($this->expanded[$node->path] ?? false);
            $this->rebuild();
            $this->invalidate();

            return;
        }
        $this->events?->dispatch(new TreeFileActivated($node->path));
        $this->invalidate();
    }

    /**
     * Highlight the node under the mouse pointer at widget-relative row $relRow
     * (the header/empty rows clear it). Returns whether the highlight changed, so
     * the caller re-renders only on change under the flood of motion events.
     */
    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->list->rowIndexAt($relRow, \count($this->visible)));
    }

    /**
     * Pull the per-file marks once per frame and drop the memo when they moved.
     * Dirtiness and diagnostics both change from the EDITOR, which never bumps
     * this widget's render revision, so nothing else would notice them.
     */
    #[\Override]
    public function beforeRender(): void
    {
        $marks = $this->fileDecorations?->snapshot() ?? [];
        if ($marks !== $this->fileMarks) {
            $this->fileMarks = $marks;
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 24);
        $this->lastRows = $rows;

        // Both per-frame calls (the intrinsic measure and the real pass) clamp
        // to the same effective width, so one memo entry serves them both; a
        // pure editor scroll leaves the revision untouched and both hit.
        $key = [$this->getRenderRevision(), $width, $rows];
        if (null !== $this->memoLines && $key === $this->memoKey) {
            return $this->memoLines;
        }

        // Row 0 is the header, so $rows - 1 entries are visible.
        $scroll = $this->list->follow($rows - 1);

        $lines = [];
        $lines[] = HeaderRenderer::title(' '.\basename($this->rootDir).'/', $width, $this->styles);

        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $node = $this->visible[$index] ?? null;

            if (null === $node) {
                $lines[] = AnsiText::blank($width);
                continue;
            }

            $icon = match (true) {
                !$node->isDir => '  ',
                $this->expanded[$node->path] ?? false => Glyphs::EXPANDED.' ',
                default => Glyphs::COLLAPSED.' ',
            };
            // Marks ride INSIDE the row text so the row's one style covers them:
            // painting them separately would mean slicing the styled line again,
            // and their meaning is the shape, not the colour.
            $marks = $this->marksFor($node);
            $marks .= $this->dropTrailing($node, $width - AnsiUtils::visibleWidth($marks));
            $text = AnsiText::blank($node->depth + 1).$icon.$node->name.($node->isDir ? '/' : '');
            $text = AnsiText::truncate($text, \max(1, $width - AnsiUtils::visibleWidth($marks)), pad: true).$marks;

            // Subtle hover background beneath the pointer keeps the dir/file foreground.
            $lines[] = match (true) {
                // Above the cursor row: during a drag the pointer is what the
                // eye follows, and the cursor is wherever the press left it.
                $node->path === $this->dropTarget => $this->styles->of(Role::DropTargetRow)->apply($text),
                $index === $this->list->selected() => $this->applyElement('selected', $text),
                // A row selected but not under the cursor — the second file of
                // a pair. The cursor keeps its own style above; without this
                // the selection is invisible and the pair is a guess.
                $this->selection->has($node->path) => $this->styles->of(Role::SelectedRowUnfocused)->apply($text),
                $index === $this->hoveredIndex => ($node->isDir ? $this->styles->merge(Role::RowHighlightBg, Role::TabAccent) : $this->styles->of(Role::RowHighlightBg))->apply($text),
                $node->isDir => $this->styles->of(Role::TabAccent)->apply($text),
                default => $text,
            };
        }

        // Composite against the tree's own line width ($width), not the context
        // width: the tree's rows are exactly $width wide, and a measuring layout
        // pass can hand us a wider context — padding to that would blow out the
        // tree's reported width and starve the editor pane.
        $this->memoKey = $key;

        return $this->memoLines = $lines;
    }

    /**
     * A file row's trailing marks, right-aligned and in a fixed order: its
     * version-control status, the worst diagnostic it carries, then whether it
     * has unsaved changes — file state, then problems, then yours. Each is one
     * column and each is a SHAPE rather than a colour, because the whole row
     * wears one style (a selected row's background has to cover the marks, and
     * painting them separately would mean slicing the styled line again).
     *
     * A directory never gets any of them: an aggregated folder badge would have
     * to walk the subtree every frame.
     */
    private function marksFor(FileTreeNode $node): string
    {
        // Both spaces, because both are legitimately in use: core keys buffers
        // by realpath(), while a plugin publishing file statuses names them
        // under the project root it was handed. Trying one and not the other is
        // a decoration that silently never appears — which is how this was
        // found.
        $mark = $node->isDir ? null : $this->fileMarks[$this->bufferPath($node->path)] ?? $this->fileMarks[$node->path] ?? null;
        if (null === $mark) {
            return '';
        }

        // A file with nothing to say is absent from the snapshot entirely, so
        // reaching here means at least one glyph follows the separating space.
        return ' '.($mark['status']?->glyph() ?? '').($mark['severity']?->glyph() ?? '').($mark['dirty'] ? Glyphs::DIRTY : '');
    }

    /**
     * A node's path in BUFFER space. Buffers are keyed by realpath() while the
     * nodes are built from the raw root, so a project opened through a symlink
     * has the two disagree on every path — and a mark looked up in the wrong
     * space simply never appears. Resolved by swapping the root prefix, never
     * with a syscall: realpath() per node per frame would be one stat per
     * rendered row.
     *
     * Every node path is built FROM the root by {@see walk}, so the prefix is
     * always there to swap; when the root does not resolve, the two spaces are
     * the same string and this is the identity.
     */
    private function bufferPath(string $path): string
    {
        return $this->realRoot.\substr($path, \strlen($this->rootDir));
    }

    private function rebuild(): void
    {
        $this->visible = [];
        $this->scannedSigs = [];
        $this->walk($this->rootDir, 0);
        // A path that has left the tree leaves the selection with it: a
        // collapsed directory or a deleted file must not stay in a pair the
        // user can no longer see.
        $this->selection->keepOnly(\array_map(static fn (FileTreeNode $node): string => $node->path, $this->visible));
        // An EMPTY selection takes the cursor row, so a Shift+arrow has an
        // anchor on the first keystroke of a session. Only when it is empty:
        // seeding unconditionally would let a rebuild — a directory expanding,
        // a file deleted — replace what the user had selected with whatever
        // row the cursor index now lands on, which is how a pair silently
        // becomes a different pair.
        $cursor = $this->visible[$this->list->selected()] ?? null;
        if (null !== $cursor && 0 === $this->selection->count()) {
            $this->selection->only($cursor->path);
        }
    }

    /**
     * Rebuild the tree only if a scanned directory changed on disk (an entry was
     * added, removed or renamed), preserving the expanded/selection state via
     * {@see refresh()}. Returns whether a refresh happened. An unchanged mtime
     * outside the grace window skips the (much costlier) scandir; the entry-list
     * signature stays the source of truth inside it, so same-second changes are
     * still detected reliably.
     */
    public function refreshIfChanged(): bool
    {
        $freshBefore = $this->clock->now()->getTimestamp() - self::POLL_GRACE_SECONDS;
        foreach (\array_keys($this->scannedSigs) as $dir) {
            if (!$this->dirChangedSince($dir, $freshBefore)) {
                continue;
            }

            $this->refresh();

            return true;
        }

        return false;
    }

    /**
     * Check up to $budget scanned directories from a persistent cursor — the
     * poll timer's amortized sibling of {@see refreshIfChanged()}, so a large
     * expanded tree costs a bounded slice per tick instead of one long stall.
     * A detected change refreshes immediately and rewinds the cursor. Returns
     * whether a refresh happened.
     */
    public function refreshDirsSlice(int $budget): bool
    {
        $dirs = \array_keys($this->scannedSigs);
        $count = \count($dirs); // never 0: rebuild() always records the root
        $freshBefore = $this->clock->now()->getTimestamp() - self::POLL_GRACE_SECONDS;
        // The modulo also re-clamps the cursor after a rebuild shrank the map.
        for ($i = 0; $i < \min($budget, $count); ++$i) {
            $dir = $dirs[$this->dirCursor % $count];
            $this->dirCursor = ($this->dirCursor + 1) % $count;
            if ($this->dirChangedSince($dir, $freshBefore)) {
                $this->refresh();
                $this->dirCursor = 0;

                return true;
            }
        }

        return false;
    }

    /** Whether $dir's entry list no longer matches its recorded scan signature. */
    private function dirChangedSince(string $dir, int $freshBefore): bool
    {
        $scan = $this->scannedSigs[$dir];
        // Clear the cache for this path first. PHP caches stat results for the
        // life of the process and invalidates them only for writes IT performs,
        // so an mtime bumped by ANOTHER process still reads as the cached one —
        // and since an unchanged mtime past the grace window is trusted without
        // a scandir, the directory would then be trusted forever. Measured: a
        // file created by a separate process was never seen.
        \clearstatcache(clear_realpath_cache: true, filename: $dir);
        // Read the mtime BEFORE the signature it vouches for: a change
        // landing after this read bumps the mtime again → caught next tick.
        $mtime = \is_dir($dir) ? (int) \filemtime($dir) : -1;
        if ($mtime === $scan['mtime'] && $mtime < $freshBefore && -1 !== $mtime) {
            return false;
        }
        if ($this->dirSignature($dir) !== $scan['sig']) {
            return true;
        }
        $this->scannedSigs[$dir]['mtime'] = $mtime; // signature verified at this mtime

        return false;
    }

    /** A stable fingerprint of a directory's entries, or '' when it is gone. */
    private function dirSignature(string $dir): string
    {
        if (!\is_dir($dir)) {
            return '';
        }
        $entries = \scandir($dir);

        return \implode("\0", $entries ? $entries : []);
    }

    private function walk(string $dir, int $depth): void
    {
        $mtime = \is_dir($dir) ? (int) \filemtime($dir) : -1; // before the scandir it vouches for
        $scanned = \is_dir($dir) ? \scandir($dir) : false;
        $entries = $scanned ? $scanned : [];
        // The one scandir serves both the walk and the recorded signature —
        // byte-identical to what dirSignature() would produce over the same
        // entries, so every expanded directory costs one read per rebuild,
        // not two. The mtime above still precedes the scan it vouches for.
        $this->scannedSigs[$dir] = ['sig' => \implode("\0", $entries), 'mtime' => $mtime];
        $dirs = [];
        $files = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, self::IGNORED, strict: true)) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            \is_dir($path) ? ($dirs[] = $entry) : ($files[] = $entry);
        }

        \sort($dirs, \SORT_NATURAL | \SORT_FLAG_CASE);
        \sort($files, \SORT_NATURAL | \SORT_FLAG_CASE);

        foreach ($dirs as $name) {
            $path = $dir.\DIRECTORY_SEPARATOR.$name;
            $this->visible[] = new FileTreeNode($path, $name, true, $depth);
            if ($this->expanded[$path] ?? false) {
                $this->walk($path, $depth + 1);
            }
        }
        foreach ($files as $name) {
            $this->visible[] = new FileTreeNode($dir.\DIRECTORY_SEPARATOR.$name, $name, false, $depth);
        }
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'select_up' => [Key::shift(Key::UP)],
            'select_down' => [Key::shift(Key::DOWN)],
            'collapse' => [Key::LEFT],
            'expand' => [Key::RIGHT],
            'open' => [Key::ENTER],
            'reload' => ['r'],
        ];
    }

    protected function overlayWidth(RenderContext $context): int
    {
        return $this->panelColumns($context);
    }
}
