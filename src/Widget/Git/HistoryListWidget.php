<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\LogEntry;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Style;
use Porta\Widget\InputHook;
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
final class HistoryListWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;

    private const int FILE_ROWS = 8;

    /** @var list<LogEntry> */
    private array $commits = [];
    /** @var list<array{status: string, path: string}> */
    private array $files = [];
    private readonly ListSelection $commitList;
    private readonly ListSelection $fileList;
    private bool $filesFocused = false;
    private int $lastRows = 12;

    /** @var (callable(LogEntry): void)|null */
    private $onSelectCommit = null;
    /** @var (callable(LogEntry, string): void)|null commit + file path */
    private $onSelectFile = null;
    /** @var (callable(): void)|null */
    private $onFocusNext = null;
    /** @var (callable(): void)|null */
    private $onExit = null;
    /** @var (callable(): void)|null `1`: switch back to the Changes tab */
    private $onShowChanges = null;

    public function __construct(
        private readonly int $width = 44,
    ) {
        $this->commitList = new ListSelection();
        $this->fileList = new ListSelection();
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

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

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
        $this->commits = $commits;
        $this->commitList->reset();
        $this->files = [];
        $this->fileList->reset();
        $this->filesFocused = false;
        $this->invalidate();
    }

    /** @param list<array{status: string, path: string}> $files */
    public function setFiles(array $files): void
    {
        $this->files = $files;
        $this->fileList->reset();
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

        if ($kb->matches($data, 'exit_view')) {
            if (null !== $this->onExit) {
                ($this->onExit)();
            }

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            if (null !== $this->onFocusNext) {
                ($this->onFocusNext)();
            }

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
            $this->invalidate();
        }
    }

    /** Click routing: commit rows select a commit, file rows select a file. */
    public function handleClick(int $relRow): void
    {
        $commitRows = $this->commitRowCount();
        if ($relRow >= 1 && $relRow < 1 + $commitRows) {
            $index = $this->commitList->scroll() + $relRow - 1;
            if (isset($this->commits[$index])) {
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
        if ($relRow > $fileHeader && isset($this->files[$index])) {
            $this->filesFocused = true;
            $this->fileList->select($index);
            $this->fireSelectFile();
            $this->invalidate();
        }
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

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(4, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $lines = ["\x1b[48;5;237m\x1b[1m".AnsiUtils::truncateToWidth(\sprintf(' History (%d)  ·  1: Changes', \count($this->commits)), $width, '…', pad: true)."\x1b[0m"];

        $commitRows = $this->commitRowCount();
        $scroll = $this->commitList->follow($commitRows);
        for ($row = 0; $row < $commitRows; ++$row) {
            $commit = $this->commits[$scroll + $row] ?? null;
            if (null === $commit) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }
            $selected = ($scroll + $row) === $this->commitList->selected();
            $bg = $selected ? ($this->focused && !$this->filesFocused ? Style::SELECTED_ROW : "\x1b[48;5;237m") : '';
            $text = ' '."\x1b[38;5;179m".$commit->shortSha."\x1b[39m".' '.$commit->subject."\x1b[38;5;245m".'  '.$commit->author."\x1b[39m";
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        }

        $lines[] = "\x1b[48;5;236m\x1b[1m".AnsiUtils::truncateToWidth(\sprintf(' Files (%d)', \count($this->files)), $width, '…', pad: true)."\x1b[0m";
        $fileScroll = $this->fileList->follow(self::FILE_ROWS);
        for ($row = 0; $row < self::FILE_ROWS && \count($lines) < $rows; ++$row) {
            $file = $this->files[$fileScroll + $row] ?? null;
            if (null === $file) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }
            $selected = ($fileScroll + $row) === $this->fileList->selected();
            $bg = $selected ? ($this->focused && $this->filesFocused ? Style::SELECTED_ROW : "\x1b[48;5;237m") : '';
            $lines[] = $bg.AnsiUtils::truncateToWidth(' '.$file['status'].' '.$file['path'], $width, '…', pad: true)."\x1b[0m";
        }
        // commitRowCount + the two headers + FILE_ROWS always fill $rows exactly
        // (the file loop is height-capped), so no tail padding is needed.

        return $this->withMenuOverlay($lines, $width);
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // always fills its pane
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
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
