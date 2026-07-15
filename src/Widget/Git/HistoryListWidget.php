<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Container\AsWidget;
use Porta\Git\LogEntry;
use Porta\I18n\Translator;
use Porta\Support\IntMath;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
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
 * The history tab's left column: recent commits on top, the selected
 * commit's files below. Tab toggles between the two lists; selecting a
 * file drives the read-only diff pane. Selection changes fire onSelectCommit
 * / onSelectFile so the controller can load `git show` data.
 */
#[AsWidget('git-history')]
final class HistoryListWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

    /** @var (callable(string): void)|null a remote-operation key: 'fetch' | 'pull' | 'push' */
    private $onRemote = null;

    private const int FILE_ROWS = 8;

    /** @var list<LogEntry> */
    private array $commits = [];
    /** @var list<array{status: string, path: string}> */
    private array $files = [];
    private readonly ListSelection $commitList;
    private readonly ListSelection $fileList;
    private bool $filesFocused = false;
    /** Rows tinted under the mouse pointer; visual only, never the selection. */
    private ?int $hoveredCommit = null;
    private ?int $hoveredFile = null;
    private int $lastRows = 12;

    /** @var (callable(LogEntry): void)|null */
    private $onSelectCommit = null;
    /** @var (callable(): void)|null fired once when the selection reaches the last loaded commit */
    private $onLoadMore = null;
    /** Whether an older page exists past the loaded commits. */
    private bool $hasMore = false;
    /** Latch: one load-more in flight at a time. */
    private bool $loadingMore = false;
    /** @var (callable(LogEntry, string): void)|null commit + file path */
    private $onSelectFile = null;
    /** @var (callable(): void)|null `1`: switch back to the Changes tab */
    private $onShowChanges = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        private int $width = 44,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->commitList = new ListSelection();
        $this->fileList = new ListSelection();
    }

    /** Resize the git sidebar column to $width columns (clamped sane). */
    public function setWidth(int $width): void
    {
        $width = IntMath::clamp($width, 24, 200);
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function onSelectCommit(callable $callback): self
    {
        $this->onSelectCommit = $callback;

        return $this;
    }

    public function onSelectFile(callable $callback): self
    {
        $this->onSelectFile = $callback;

        return $this;
    }

    public function onShowChanges(callable $callback): self
    {
        $this->onShowChanges = $callback;

        return $this;
    }

    /** @param list<LogEntry> $commits */
    public function setCommits(array $commits): void
    {
        $this->loadingMore = false;
        $this->commits = $commits;
        $this->commitList->reset();
        $this->hoveredFile = null;
        $this->hoveredCommit = null;
        $this->files = [];
        $this->fileList->reset();
        $this->filesFocused = false;
        $this->invalidate();
    }

    /**
     * Append an older page, preserving the selection/scroll and the files
     * pane. Deduped by sha — `--skip` offsets drift when new commits land
     * between pages.
     *
     * @param list<LogEntry> $commits
     */
    public function appendCommits(array $commits): void
    {
        $this->loadingMore = false;
        $known = array_flip(array_column($this->commits, 'sha'));
        foreach ($commits as $commit) {
            if (\array_key_exists($commit->sha, $known)) {
                continue;
            }

            $this->commits[] = $commit;
        }
        $this->invalidate();
    }

    public function setHasMore(bool $hasMore): void
    {
        $this->hasMore = $hasMore;
    }

    public function commitCount(): int
    {
        return \count($this->commits);
    }

    public function onLoadMore(callable $callback): self
    {
        $this->onLoadMore = $callback;

        return $this;
    }

    /** @param list<array{status: string, path: string}> $files */
    public function setFiles(array $files): void
    {
        $this->files = $files;
        $this->fileList->reset();
        $this->hoveredFile = null;
        $this->invalidate();
    }

    public function selectedCommit(): ?LogEntry
    {
        return $this->commits[$this->commitList->selected()] ?? null;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        foreach (['fetch', 'pull', 'push'] as $remoteOp) {
            if (!$kb->matches($data, $remoteOp)) {
                continue;
            }

            if (null !== $this->onRemote) {
                ($this->onRemote)($remoteOp);
            }

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
        if ($kb->matches($data, 'show_changes')) {
            if (null !== $this->onShowChanges) {
                ($this->onShowChanges)();
            }

            return;
        }
        if ($kb->matches($data, 'switch_list')) {
            $this->filesFocused = !$this->filesFocused && [] !== $this->files;
            $this->invalidate();

            return;
        }

        if ($this->filesFocused) {
            if (ListNavigation::handleKey($data, $kb, $this->fileList, \count($this->files), page: self::FILE_ROWS)) {
                $this->fireSelectFile();
                $this->invalidate();
            }

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->commitList, \count($this->commits), page: $this->lastRows)) {
            $this->fireSelectCommit();
            // Hitting the bottom of the loaded page pulls the next one in
            // (keyboard and wheel — GitMouseRouter translates scroll to keys).
            if ($this->hasMore && !$this->loadingMore && null !== $this->onLoadMore && $this->commitList->selected() === (\count($this->commits) - 1)) {
                $this->loadingMore = true;
                ($this->onLoadMore)();
            }
            $this->invalidate();
        }
    }

    /** Click routing: commit rows select a commit, file rows select a file. */
    public function handleClick(int $relRow): void
    {
        $commitRows = $this->commitRowCount();
        if ($relRow >= 1 && $relRow < (1 + $commitRows)) {
            $index = $this->commitList->scroll() + $relRow - 1;
            if (\array_key_exists($index, $this->commits)) {
                $this->filesFocused = false;
                // Re-clicking the selected commit must not reload its files —
                // that would clobber a diff the user already opened.
                if ($this->commitList->selectIfChanged($index)) {
                    $this->fireSelectCommit();
                }
                $this->invalidate();
            }

            return;
        }
        $fileHeader = 1 + $commitRows;
        $index = $this->fileList->scroll() + $relRow - $fileHeader - 1;
        if ($relRow > $fileHeader && \array_key_exists($index, $this->files)) {
            $this->filesFocused = true;
            $this->fileList->select($index);
            $this->fireSelectFile();
            $this->invalidate();
        }
    }

    /**
     * Tint the row under the pointer (commit or file section, same row math
     * as {@see handleClick}); a pure visual that never moves the selections.
     */
    public function hoverRow(int $relRow): bool
    {
        $commitRows = $this->commitRowCount();
        if ($relRow >= 1 && $relRow < (1 + $commitRows)) {
            $index = $this->commitList->scroll() + $relRow - 1;

            return $this->setHovered(\array_key_exists($index, $this->commits) ? $index : null, null);
        }
        $fileHeader = 1 + $commitRows;
        $index = $this->fileList->scroll() + $relRow - $fileHeader - 1;

        return $this->setHovered(null, $relRow > $fileHeader && \array_key_exists($index, $this->files) ? $index : null);
    }

    /** The pointer left the list: drop the hover tint. */
    public function clearHover(): bool
    {
        return $this->setHovered(null, null);
    }

    private function setHovered(?int $commit, ?int $file): bool
    {
        if ($commit === $this->hoveredCommit && $file === $this->hoveredFile) {
            return false;
        }
        $this->hoveredCommit = $commit;
        $this->hoveredFile = $file;
        $this->invalidate();

        return true;
    }

    private function fireSelectCommit(): void
    {
        if (null !== ($commit = $this->selectedCommit()) && null !== $this->onSelectCommit) {
            ($this->onSelectCommit)($commit);
        }
    }

    private function fireSelectFile(): void
    {
        $commit = $this->selectedCommit();
        $file = $this->files[$this->fileList->selected()] ?? null;
        if (null !== $commit && null !== $file && null !== $this->onSelectFile) {
            ($this->onSelectFile)($commit, $file['path']);
        }
    }

    private function commitRowCount(): int
    {
        return max(1, $this->lastRows - 2 - self::FILE_ROWS);
    }

    /** The repo-relative file this history is filtered to, or null for repo-wide. */
    private ?string $filterLabel = null;

    /** Show which file the history is filtered to (null = repo-wide). */
    public function setFilterLabel(?string $label): void
    {
        $this->filterLabel = $label;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(4, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $header = null === $this->filterLabel
            ? $this->translator->trans(' History (%count%)', ['%count%' => \count($this->commits)])
            // An em dash, not a colon: symfony's legacy plural handling treats
            // "label: message" specially whenever %count% is a parameter.
            : $this->translator->trans(' History — %path% (%count%)', ['%path%' => $this->filterLabel, '%count%' => \count($this->commits)]);
        $lines = [
            $this->palette->of(Role::HeaderBar)."\x1b[1m".AnsiUtils::truncateToWidth($header, $width, '…', pad: true)."\x1b[0m",
        ];

        $commitRows = $this->commitRowCount();
        $scroll = $this->commitList->follow($commitRows);
        for ($row = 0; $row < $commitRows; ++$row) {
            $commit = $this->commits[$scroll + $row] ?? null;
            if (null === $commit) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }
            $selected = $this->commitList->isSelected($scroll + $row);
            $bg = '';
            if ($selected) {
                $bg = $this->focused && !$this->filesFocused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::HeaderBar);
            }
            if (!$selected && ($scroll + $row) === $this->hoveredCommit) {
                $bg = $this->palette->of(Role::RowHighlightBg);
            }
            $text = ' '.$this->palette->of(Role::Modified).$commit->shortSha."\x1b[39m".' '.$commit->subject.$this->palette->of(Role::Dim).'  '.$commit->author."\x1b[39m";
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        }

        $lines[] =
            $this->palette->of(Role::RowHighlightBg)
            ."\x1b[1m"
            .AnsiUtils::truncateToWidth($this->translator->trans(' Files (%count%)', ['%count%' => \count($this->files)]), $width, '…', pad: true)
            ."\x1b[0m";
        $fileScroll = $this->fileList->follow(self::FILE_ROWS);
        for ($row = 0; $row < self::FILE_ROWS && \count($lines) < $rows; ++$row) {
            $file = $this->files[$fileScroll + $row] ?? null;
            if (null === $file) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }
            $selected = $this->fileList->isSelected($fileScroll + $row);
            $bg = '';
            if ($selected) {
                $bg = $this->focused && $this->filesFocused ? $this->palette->of(Role::SelectedRow) : $this->palette->of(Role::HeaderBar);
            }
            if (!$selected && ($fileScroll + $row) === $this->hoveredFile) {
                $bg = $this->palette->of(Role::RowHighlightBg);
            }
            $lines[] = $bg.AnsiUtils::truncateToWidth(' '.$file['status'].' '.$file['path'], $width, '…', pad: true)."\x1b[0m";
        }

        // commitRowCount + the two headers + FILE_ROWS always fill $rows exactly
        // (the file loop is height-capped), so no tail padding is needed.

        return $this->withMenuOverlay($lines, $width);
    }

    public function onRemote(callable $callback): self
    {
        $this->onRemote = $callback;

        return $this;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'switch_list' => [Key::ENTER],
            'show_changes' => ['1'],
            'fetch' => ['f'],
            'pull' => ['p'],
            'push' => ['shift+p'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
