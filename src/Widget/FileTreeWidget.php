<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Ui\ListSelection;
use Porta\Widget\Render\MenuOverlay;
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
final class FileTreeWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    // '.porta' is the IDE's own session/history metadata dir; '.porta.json' (the
    // project config file) is not matched by the exact-name check and still shows.
    private const IGNORED = ['.git', '.porta', 'node_modules', '.idea', '.vscode', '.DS_Store'];

    /** @var array<string, bool> expanded state by path */
    private array $expanded = [];
    private readonly ListSelection $list;
    private int $lastRows = 24;
    private ?int $hoveredIndex = null; // node highlighted under the mouse pointer

    /** @var list<array{path: string, name: string, isDir: bool, depth: int}> */
    private array $visible = [];

    /** @var array<string, string> entry-list signature of each directory scanned in the last rebuild, for change detection */
    private array $scannedSigs = [];

    /** @var (callable(string): void)|null */
    private $onOpenFile = null;

    /** @var list<array{row: int, col: int, width: int, segment: string}> floating context-menu spans (local coords) to overlay */
    private array $menuOverlay = [];

    public function __construct(
        private readonly string $rootDir,
        private int $width = 30,
    ) {
        $this->list = new ListSelection();
        $this->rebuild();
    }

    /** Resize the sidebar to $width columns (clamped to a sensible range). */
    public function setWidth(int $width): void
    {
        $width = max(12, min($width, 200));
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function onOpenFile(callable $callback): self
    {
        $this->onOpenFile = $callback;

        return $this;
    }

    /**
     * Floating context-menu dropdown spans (local coords) to overlay, or [] for none.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void
    {
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

    /**
     * The tree node under a click at row $relRow (0 = header), selecting it, or
     * null when the click missed every entry. Used to target a right-click.
     *
     * @return array{path: string, name: string, isDir: bool, depth: int}|null
     */
    public function nodeAt(int $relRow): ?array
    {
        if ($relRow < 1) {
            return null; // header row
        }
        $index = $this->list->scroll() + $relRow - 1;
        if (!isset($this->visible[$index])) {
            return null;
        }
        $this->list->select($index);
        $this->invalidate();

        return $this->visible[$index];
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // the tree always fills its pane
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function refresh(): void
    {
        $this->rebuild();
        $this->list->clamp(\count($this->visible));
        $this->invalidate();
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        $kb = $this->getKeybindings();
        $count = \count($this->visible);
        if (0 === $count) {
            return;
        }

        if ($kb->matches($data, 'move_up')) {
            $this->list->moveUp();
            $this->invalidate();
        } elseif ($kb->matches($data, 'move_down')) {
            $this->list->moveDown($count);
            $this->invalidate();
        } elseif ($kb->matches($data, 'page_up')) {
            $this->list->moveUp($this->lastRows);
            $this->invalidate();
        } elseif ($kb->matches($data, 'page_down')) {
            $this->list->moveDown($count, $this->lastRows);
            $this->invalidate();
        } elseif ($kb->matches($data, 'collapse')) {
            $node = $this->visible[$this->list->selected()];
            if ($node['isDir'] && ($this->expanded[$node['path']] ?? false)) {
                $this->expanded[$node['path']] = false;
                $this->rebuild();
            } elseif ($node['depth'] > 0) {
                // Jump to parent
                for ($i = $this->list->selected() - 1; $i >= 0; --$i) {
                    if ($this->visible[$i]['depth'] < $node['depth']) {
                        $this->list->select($i);
                        break;
                    }
                }
            }
            $this->invalidate();
        } elseif ($kb->matches($data, 'expand')) {
            $node = $this->visible[$this->list->selected()];
            if ($node['isDir'] && !($this->expanded[$node['path']] ?? false)) {
                $this->expanded[$node['path']] = true;
                $this->rebuild();
                $this->invalidate();
            }
        } elseif ($kb->matches($data, 'open')) {
            $node = $this->visible[$this->list->selected()];
            if ($node['isDir']) {
                $this->expanded[$node['path']] = !($this->expanded[$node['path']] ?? false);
                $this->rebuild();
                $this->invalidate();
            } elseif (null !== $this->onOpenFile) {
                ($this->onOpenFile)($node['path']);
            }
        } elseif ($kb->matches($data, 'reload')) {
            $this->refresh();
        }
    }

    /**
     * Handle a click at row $relRow of the tree (0 = header). Selects the
     * entry and opens the file / toggles the directory, mirroring Enter.
     */
    public function clickRow(int $relRow): void
    {
        if ($relRow < 1) {
            return; // header row
        }
        $index = $this->list->scroll() + $relRow - 1;
        if (!isset($this->visible[$index])) {
            return;
        }
        $this->list->select($index);
        $node = $this->visible[$index];
        if ($node['isDir']) {
            $this->expanded[$node['path']] = !($this->expanded[$node['path']] ?? false);
            $this->rebuild();
        } elseif (null !== $this->onOpenFile) {
            ($this->onOpenFile)($node['path']);
        }
        $this->invalidate();
    }

    /**
     * Highlight the node under the mouse pointer at widget-relative row $relRow
     * (the header/empty rows clear it). Returns whether the highlight changed, so
     * the caller re-renders only on change under the flood of motion events.
     */
    public function hoverRow(int $relRow): bool
    {
        $index = null;
        if ($relRow >= 1) {
            $candidate = $this->list->scroll() + $relRow - 1;
            if (isset($this->visible[$candidate])) {
                $index = $candidate;
            }
        }

        return $this->setHovered($index);
    }

    /** Clear the hover highlight (the pointer left the tree); returns whether it changed. */
    public function clearHover(): bool
    {
        return $this->setHovered(null);
    }

    private function setHovered(?int $index): bool
    {
        if ($index === $this->hoveredIndex) {
            return false;
        }
        $this->hoveredIndex = $index;
        $this->invalidate();

        return true;
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 24);
        $this->lastRows = $rows;

        // Row 0 is the header, so $rows - 1 entries are visible.
        $scroll = $this->list->follow($rows - 1);

        $lines = [];
        $header = AnsiUtils::truncateToWidth(' '.basename($this->rootDir).'/', $width, '…', pad: true);
        $lines[] = "\x1b[1m\x1b[38;5;110m".$header."\x1b[0m";

        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $node = $this->visible[$index] ?? null;

            if (null === $node) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }

            $icon = $node['isDir']
                ? ($this->expanded[$node['path']] ?? false ? '▾ ' : '▸ ')
                : '  ';
            $text = str_repeat(' ', $node['depth'] + 1).$icon.$node['name'].($node['isDir'] ? '/' : '');
            $text = AnsiUtils::truncateToWidth($text, $width, '…', pad: true);

            if ($index === $this->list->selected()) {
                $style = $this->focused ? "\x1b[48;5;31m\x1b[38;5;231m" : "\x1b[48;5;237m";
                $lines[] = $style.$text."\x1b[0m";
            } elseif ($index === $this->hoveredIndex) {
                // Subtle hover background beneath the pointer, keeping the dir/file foreground.
                $fg = $node['isDir'] ? "\x1b[38;5;110m" : '';
                $lines[] = "\x1b[48;5;236m".$fg.$text."\x1b[0m";
            } elseif ($node['isDir']) {
                $lines[] = "\x1b[38;5;110m".$text."\x1b[0m";
            } else {
                $lines[] = $text;
            }
        }

        // Composite against the tree's own line width ($width), not the context
        // width: the tree's rows are exactly $width wide, and a measuring layout
        // pass can hand us a wider context — padding to that would blow out the
        // tree's reported width and starve the editor pane.
        return MenuOverlay::apply($lines, $this->menuOverlay, $width);
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
     * {@see refresh()}. Returns whether a refresh happened. Uses the directory's
     * entry list (not mtime) so same-second changes are detected reliably.
     */
    public function refreshIfChanged(): bool
    {
        foreach ($this->scannedSigs as $dir => $sig) {
            if ($this->dirSignature($dir) !== $sig) {
                $this->refresh();

                return true;
            }
        }

        return false;
    }

    /** A stable fingerprint of a directory's entries, or '' when it is gone. */
    private function dirSignature(string $dir): string
    {
        return is_dir($dir) ? implode("\0", @scandir($dir) ?: []) : '';
    }

    private function walk(string $dir, int $depth): void
    {
        $this->scannedSigs[$dir] = $this->dirSignature($dir);
        $entries = @scandir($dir) ?: [];
        $dirs = $files = [];

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, self::IGNORED, true)) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? ($dirs[] = $entry) : ($files[] = $entry);
        }

        sort($dirs, \SORT_NATURAL | \SORT_FLAG_CASE);
        sort($files, \SORT_NATURAL | \SORT_FLAG_CASE);

        foreach ($dirs as $name) {
            $path = $dir.\DIRECTORY_SEPARATOR.$name;
            $this->visible[] = ['path' => $path, 'name' => $name, 'isDir' => true, 'depth' => $depth];
            if ($this->expanded[$path] ?? false) {
                $this->walk($path, $depth + 1);
            }
        }
        foreach ($files as $name) {
            $this->visible[] = ['path' => $dir.\DIRECTORY_SEPARATOR.$name, 'name' => $name, 'isDir' => false, 'depth' => $depth];
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
