<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Layout\ResizableSidebar;
use Porta\Support\IntMath;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Style;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
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
final class FileTreeWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface, ResizableSidebar
{
    use FillsPaneTrait;
    use FocusableTrait;
    use MenuOverlayHostTrait;
    use InputHook;
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

    /** @var (callable(string): void)|null */
    private $onOpenFile = null;

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
        $width = IntMath::clamp($width, 12, 200);
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

    public function width(): int
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
                $style = $this->focused ? Style::SELECTED_ROW : "\x1b[48;5;237m";
                $lines[] = $style.$text."\x1b[0m";
            } elseif ($index === $this->hoveredIndex) {
                // Subtle hover background beneath the pointer, keeping the dir/file foreground.
                $fg = $node['isDir'] ? "\x1b[38;5;110m" : '';
                $lines[] = Style::POPUP_ROW_BG.$fg.$text."\x1b[0m";
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
        $freshBefore = time() - self::POLL_GRACE_SECONDS;
        foreach ($this->scannedSigs as $dir => $scan) {
            // Read the mtime BEFORE the signature it vouches for: a change
            // landing after this read bumps the mtime again → caught next tick.
            $mtime = is_dir($dir) ? (int) @filemtime($dir) : -1;
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
        return is_dir($dir) ? implode("\0", @scandir($dir) ?: []) : '';
    }

    private function walk(string $dir, int $depth): void
    {
        $mtime = is_dir($dir) ? (int) @filemtime($dir) : -1; // before the scandir it vouches for
        $this->scannedSigs[$dir] = ['sig' => $this->dirSignature($dir), 'mtime' => $mtime];
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
