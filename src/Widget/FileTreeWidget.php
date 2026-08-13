<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Event\TreeFileActivated;
use Porta\Layout\ResizableSidebar;
use Porta\Support\AnsiText;
use Porta\Tree\FileTreeNode;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
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

    /**
     * An unchanged dir mtime older than this many seconds is trusted without a
     * scandir; younger ones are re-scanned because a same-second rename can
     * leave the mtime identical.
     */
    private const int POLL_GRACE_SECONDS = 2;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly string $rootDir,
        ?ClockInterface $clock = null,
        int $width = 30,
        ?Styles $styles = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->width = $width;
        $this->clock = $clock ?? new NativeClock();
        $this->initStyles($styles);
        $this->list = new ListSelection();
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

        if (ListNavigation::handleKey($data, $kb, $this->list, $count, page: $this->lastRows)) {
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
     * Handle a click at row $relRow of the tree (0 = header). Selects the
     * entry and opens the file / toggles the directory, mirroring Enter.
     */
    public function clickRow(int $relRow): void
    {
        $index = $this->list->rowIndexAt($relRow, \count($this->visible));
        if (null === $index) {
            return;
        }
        $this->list->select($index);
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

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 24);
        $this->lastRows = $rows;

        // Row 0 is the header, so $rows - 1 entries are visible.
        $scroll = $this->list->follow($rows - 1);

        $lines = [];
        $lines[] = HeaderRenderer::render(' '.basename($this->rootDir).'/', $width, $this->styles->of(Role::HeaderBar)->withBold());

        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $node = $this->visible[$index] ?? null;

            if (null === $node) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            $icon = match (true) {
                !$node->isDir => '  ',
                $this->expanded[$node->path] ?? false => Glyphs::EXPANDED.' ',
                default => Glyphs::COLLAPSED.' ',
            };
            $text = str_repeat(' ', max(0, $node->depth + 1)).$icon.$node->name.($node->isDir ? '/' : '');
            $text = AnsiText::truncate($text, $width, pad: true);

            // Subtle hover background beneath the pointer keeps the dir/file foreground.
            $lines[] = match (true) {
                $index === $this->list->selected() => $this->applyElement('selected', $text),
                $index === $this->hoveredIndex => ($node->isDir ? $this->styles->merge(Role::RowHighlightBg, Role::TabAccent) : $this->styles->of(Role::RowHighlightBg))->apply($text),
                $node->isDir => $this->styles->of(Role::TabAccent)->apply($text),
                default => $text,
            };
        }

        // Composite against the tree's own line width ($width), not the context
        // width: the tree's rows are exactly $width wide, and a measuring layout
        // pass can hand us a wider context — padding to that would blow out the
        // tree's reported width and starve the editor pane.
        return $this->withMenuOverlay($lines, $width);
    }

    private function rebuild(): void
    {
        $this->visible = [];
        $this->scannedSigs = [];
        $this->walk($this->rootDir, 0);
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
        clearstatcache();
        $freshBefore = $this->clock->now()->getTimestamp() - self::POLL_GRACE_SECONDS;
        foreach ($this->scannedSigs as $dir => $scan) {
            // Read the mtime BEFORE the signature it vouches for: a change
            // landing after this read bumps the mtime again → caught next tick.
            $mtime = is_dir($dir) ? (int) filemtime($dir) : -1;
            if ($mtime === $scan['mtime'] && $mtime < $freshBefore && -1 !== $mtime) {
                continue;
            }
            if ($this->dirSignature($dir) !== $scan['sig']) {
                $this->refresh();

                return true;
            }
            $this->scannedSigs[$dir]['mtime'] = $mtime; // signature verified at this mtime
        }

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
        $this->scannedSigs[$dir] = ['sig' => $this->dirSignature($dir), 'mtime' => $mtime];
        $scanned = is_dir($dir) ? scandir($dir) : false;
        $entries = $scanned ? $scanned : [];
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
            'collapse' => [Key::LEFT],
            'expand' => [Key::RIGHT],
            'open' => [Key::ENTER],
            'reload' => ['r'],
        ];
    }
}
