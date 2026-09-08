<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Editor\DiagnosticStyle;
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
    private const IGNORED = ['.git', '.porta', 'node_modules', '.idea', '.vscode', '.DS_Store'];

    /** @var array<string, bool> expanded state by path */
    private array $expanded = [];
    private readonly ListSelection $list;
    private readonly MultiSelection $selection;
    private int $lastRows = 24;

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
     * @var array<string, array{dirty: bool, severity: int, status: \Porta\Editor\ChangeMarkKind|null}>
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
        $this->realRoot = ($resolved = realpath($rootDir)) ? $resolved : $rootDir;
        $this->list = new ListSelection();
        $this->selection = new MultiSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
        $this->rebuild();
    }

    /**
     * The currently selected node, or null when the tree is empty. The keyboard
     * counterpart of {@see nodeAt} for opening the context menu without a click.
     *
     * @return FileTreeNode|null
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
     *
     * @return FileTreeNode|null
     */
    public function nodeAt(int $relRow): ?FileTreeNode
    {
        $index = $this->list->rowIndexAt($relRow, \count($this->visible));
        if (null === $index) {
            return null;
        }
        $this->list->select($index);
        $this->invalidate();

        return $this->visible[$index];
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
        $root = realpath($this->rootDir);
        $target = realpath($path);
        if (false === $root || false === $target || !str_starts_with($target, $root.\DIRECTORY_SEPARATOR)) {
            return;
        }
        $path = $this->rootDir.\DIRECTORY_SEPARATOR.substr($target, \strlen($root) + 1);
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
            $index = max(0, min($count - 1, $this->list->selected() + $step));
            $this->list->select($index);
            $this->extendSelection($index, ClickIntent::Range);
            $this->invalidate();

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, $count, page: $this->lastRows)) {
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
        foreach (range($from, $index) as $row) {
            $range[] = $this->visible[$row]->path;
        }
        $this->selection->replaceRange($range);
    }

    /** Where $path sits in the visible rows, or null when it is not there. */
    private function rowOf(string $path): ?int
    {
        $rows = array_keys(array_filter($this->visible, static fn (FileTreeNode $node): bool => $node->path === $path));

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
        $width = min($this->width, $context->getColumns());
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 24);
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
        $lines[] = HeaderRenderer::title(' '.basename($this->rootDir).'/', $width, $this->styles);

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
            $text = AnsiText::blank($node->depth + 1).$icon.$node->name.($node->isDir ? '/' : '');
            $text = AnsiText::truncate($text, max(1, $width - AnsiUtils::visibleWidth($marks)), pad: true).$marks;

            // Subtle hover background beneath the pointer keeps the dir/file foreground.
            $lines[] = match (true) {
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

        return $this->memoLines = $this->withMenuOverlay($lines, $width);
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
        return ' '.($mark['status']?->glyph() ?? '').(0 === $mark['severity'] ? '' : DiagnosticStyle::glyph($mark['severity'])).($mark['dirty'] ? Glyphs::DIRTY : '');
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
        return $this->realRoot.substr($path, \strlen($this->rootDir));
    }

    private function rebuild(): void
    {
        $this->visible = [];
        $this->scannedSigs = [];
        $this->walk($this->rootDir, 0);
        // A path that has left the tree leaves the selection with it: a
        // collapsed directory or a deleted file must not stay in a pair the
        // user can no longer see.
        $this->selection->keepOnly(array_map(static fn (FileTreeNode $node): string => $node->path, $this->visible));
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
        foreach (array_keys($this->scannedSigs) as $dir) {
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
        $dirs = array_keys($this->scannedSigs);
        $count = \count($dirs); // never 0: rebuild() always records the root
        $freshBefore = $this->clock->now()->getTimestamp() - self::POLL_GRACE_SECONDS;
        // The modulo also re-clamps the cursor after a rebuild shrank the map.
        for ($i = 0; $i < min($budget, $count); ++$i) {
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
        // Read the mtime BEFORE the signature it vouches for: a change
        // landing after this read bumps the mtime again → caught next tick.
        $mtime = is_dir($dir) ? (int) filemtime($dir) : -1;
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
        if (!is_dir($dir)) {
            return '';
        }
        $entries = scandir($dir);

        return implode("\0", $entries ? $entries : []);
    }

    private function walk(string $dir, int $depth): void
    {
        $mtime = is_dir($dir) ? (int) filemtime($dir) : -1; // before the scandir it vouches for
        $scanned = is_dir($dir) ? scandir($dir) : false;
        $entries = $scanned ? $scanned : [];
        // The one scandir serves both the walk and the recorded signature —
        // byte-identical to what dirSignature() would produce over the same
        // entries, so every expanded directory costs one read per rebuild,
        // not two. The mtime above still precedes the scan it vouches for.
        $this->scannedSigs[$dir] = ['sig' => implode("\0", $entries), 'mtime' => $mtime];
        $dirs = [];
        $files = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, self::IGNORED, strict: true)) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? ($dirs[] = $entry) : ($files[] = $entry);
        }

        sort($dirs, \SORT_NATURAL | \SORT_FLAG_CASE);
        sort($files, \SORT_NATURAL | \SORT_FLAG_CASE);

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
}
