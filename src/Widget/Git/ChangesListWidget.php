<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Git\ChangeEntry;
use Porta\I18n\Translator;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Style;
use Porta\Widget\FillsPaneTrait;
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
 * The git view's changed-files list (GitHub Desktop's left column): one row
 * per {@see ChangeEntry} as `[x] M path`, where the checkbox mirrors the
 * staged state. Space toggles staging, `d` arms an inline discard
 * confirmation, Enter/selection drives the diff pane via onSelect.
 */
final class ChangesListWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;

    private const array STATUS_FG = [
        'A' => "\x1b[38;5;114m",
        'M' => "\x1b[38;5;179m",
        'D' => "\x1b[38;5;203m",
        'R' => "\x1b[38;5;75m",
        'C' => "\x1b[38;5;75m",
    ];

    /** @var list<ChangeEntry> */
    private array $entries = [];
    private readonly ListSelection $list;
    private int $lastRows = 10;
    /** Index of the entry an inline discard confirmation is armed for, or null. */
    private ?int $confirmDiscard = null;
    /** Row tinted under the mouse pointer; visual only, never the selection. */
    private ?int $hoveredIndex = null;

    /** @var (callable(ChangeEntry): void)|null selection moved to this entry */
    private $onSelect = null;
    /** @var (callable(ChangeEntry): void)|null checkbox toggled */
    private $onToggleStage = null;
    /** @var (callable(ChangeEntry): void)|null discard confirmed */
    private $onDiscard = null;
    /** @var (callable(): void)|null Tab: move focus to the next git pane */
    private $onFocusNext = null;
    /** @var (callable(): void)|null Esc: leave the git view */
    private $onExit = null;
    /** @var (callable(): void)|null `2`: switch to the History tab */
    private $onShowHistory = null;

    public function __construct(
        private readonly Translator $translator,
        private int $width = 44,
    ) {
        $this->list = new ListSelection();
    }

    /** Resize the git sidebar column to $width columns (clamped sane). */
    public function setWidth(int $width): void
    {
        $width = max(24, min($width, 200));
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function onSelect(callable $callback): self
    {
        $this->onSelect = $callback;

        return $this;
    }

    public function onToggleStage(callable $callback): self
    {
        $this->onToggleStage = $callback;

        return $this;
    }

    public function onDiscard(callable $callback): self
    {
        $this->onDiscard = $callback;

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

    public function onShowHistory(callable $callback): self
    {
        $this->onShowHistory = $callback;

        return $this;
    }

    /**
     * Replace the entries, keeping the selection on the same path when it
     * still exists (staging ops reorder nothing but statuses change).
     *
     * @param list<ChangeEntry> $entries
     */
    public function setEntries(array $entries): void
    {
        $previous = $this->selectedEntry()?->path;
        $this->entries = $entries;
        $this->hoveredIndex = null; // rows moved under the pointer
        if (null !== $previous) {
            foreach ($entries as $i => $entry) {
                if ($entry->path === $previous) {
                    $this->list->select($i);
                    $this->confirmDiscard = null !== $this->confirmDiscard ? $i : null;
                    $this->invalidate();

                    return;
                }
            }
        }
        $this->list->clamp(\count($entries));
        $this->confirmDiscard = null;
        $this->invalidate();
    }

    public function selectedEntry(): ?ChangeEntry
    {
        return $this->entries[$this->list->selected()] ?? null;
    }

    public function entryCount(): int
    {
        return \count($this->entries);
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        // An armed discard confirmation captures y / Esc first.
        if (null !== $this->confirmDiscard) {
            $entry = $this->entries[$this->confirmDiscard] ?? null;
            if (('y' === $data || 'Y' === $data) && null !== $entry) {
                $this->confirmDiscard = null;
                if (null !== $this->onDiscard) {
                    ($this->onDiscard)($entry);
                }
            } else {
                $this->confirmDiscard = null;
            }
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'exit_view')) {
            if (null !== $this->onExit) {
                ($this->onExit)();
            }
        } elseif ($kb->matches($data, 'focus_next_pane')) {
            if (null !== $this->onFocusNext) {
                ($this->onFocusNext)();
            }
        } elseif (ListNavigation::handleKey($data, $kb, $this->list, \count($this->entries), page: $this->lastRows)) {
            $this->fireSelect();
            $this->invalidate();
        } elseif ($kb->matches($data, 'toggle_stage')) {
            if (null !== ($entry = $this->selectedEntry()) && null !== $this->onToggleStage) {
                ($this->onToggleStage)($entry);
            }
        } elseif ($kb->matches($data, 'show_history')) {
            if (null !== $this->onShowHistory) {
                ($this->onShowHistory)();
            }
        } elseif ($kb->matches($data, 'discard')) {
            if (null !== $this->selectedEntry()) {
                $this->confirmDiscard = $this->list->selected();
                $this->invalidate();
            }
        }
    }

    /** Left-click: the checkbox column toggles staging, anywhere else selects. */
    public function handleClick(int $relRow, int $relCol): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return;
        }
        $this->list->select($index);
        $entry = $this->entries[$index];
        if ($relCol <= 3 && null !== $this->onToggleStage) {
            ($this->onToggleStage)($entry);
        } else {
            $this->fireSelect();
        }
        $this->invalidate();
    }

    /**
     * Tint the row under the pointer; returns whether it changed. Hover is a
     * pure visual — it must never move the selection, because the controller
     * reloads the SELECTED entry's diff on every refresh/poll.
     */
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowIndexAt($relRow));
    }

    /** The pointer left the list: drop the hover tint. */
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

    private function rowIndexAt(int $relRow): ?int
    {
        $index = $this->list->scroll() + $relRow - 1; // row 0 is the header
        if ($relRow < 1 || !isset($this->entries[$index])) {
            return null;
        }

        return $index;
    }

    private function fireSelect(): void
    {
        if (null !== ($entry = $this->selectedEntry()) && null !== $this->onSelect) {
            ($this->onSelect)($entry);
        }
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $count = \count($this->entries);
        $title = 1 === $count
            ? $this->translator->trans(' %count% changed file', ['%count%' => $count])
            : $this->translator->trans(' %count% changed files', ['%count%' => $count]);
        $lines = ["\x1b[48;5;237m\x1b[1m".AnsiUtils::truncateToWidth($title, $width, '…', pad: true)."\x1b[0m"];

        $scroll = $this->list->follow($rows - 1);
        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $entry = $this->entries[$index] ?? null;
            if (null === $entry) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }
            if ($index === $this->confirmDiscard) {
                $confirm = $this->translator->trans(' Discard changes? y = yes · any key = no');
                $lines[] = "\x1b[48;5;88m\x1b[38;5;231m".AnsiUtils::truncateToWidth($confirm, $width, '…', pad: true)."\x1b[0m";
                continue;
            }

            $checkbox = match ($entry->stagedState()) {
                'full' => '[x]',
                'partial' => '[~]',
                default => '[ ]',
            };
            $status = $entry->displayStatus();
            $statusFg = self::STATUS_FG[$status] ?? '';
            $label = $entry->path.(null !== $entry->renamedFrom ? ' ← '.$entry->renamedFrom : '');

            $selected = $this->list->isSelected($index);
            $bg = $selected
                ? ($this->focused ? Style::SELECTED_ROW : "\x1b[48;5;237m")
                : ($index === $this->hoveredIndex ? Style::POPUP_ROW_BG : '');
            $text = ' '.$checkbox.' '.$statusFg.$status."\x1b[39m".' '.$label;
            $lines[] = $bg.AnsiUtils::truncateToWidth($text, $width, '…', pad: true)."\x1b[0m";
        }

        return $this->withMenuOverlay($lines, $width);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'toggle_stage' => [Key::SPACE],
            'discard' => ['d', Key::DELETE],
            'show_history' => ['2'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
