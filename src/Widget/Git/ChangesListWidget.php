<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Container\AsWidget;
use Porta\Git\ChangeEntry;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\HoverableList;
use Porta\Widget\HoverTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PanelWidth;
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
 * The git view's changed-files list (GitHub Desktop's left column): one row
 * per {@see ChangeEntry} as `[x] M path`, where the checkbox mirrors the
 * staged state. Space toggles staging, `d` arms an inline discard
 * confirmation, Enter/selection drives the diff pane via onSelect.
 */
#[AsWidget('git-changes', flex: 0)]
final class ChangesListWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PanelWidth;
    use PaneNavigationCallbacks;

    /** @var (callable(string): void)|null a remote-operation key: 'fetch' | 'pull' | 'push' */
    private $onRemote = null;

    /** @var array<string, Role> file-status char → color role */
    private const array STATUS_ROLE = [
        'A' => Role::Added,
        'M' => Role::Modified,
        'D' => Role::Deleted,
        'R' => Role::Hunk,
        'C' => Role::Hunk,
    ];

    private readonly Styles $styles;

    /** @var list<ChangeEntry> */
    private array $entries = [];
    private readonly ListSelection $list;
    private int $lastRows = 10;
    /** Index of the entry an inline discard confirmation is armed for, or null. */
    private ?int $confirmDiscard = null;

    /** @var (callable(ChangeEntry): void)|null selection moved to this entry */
    private $onSelect = null;
    /** @var (callable(ChangeEntry): void)|null checkbox toggled */
    private $onToggleStage = null;
    /** @var (callable(ChangeEntry): void)|null discard confirmed */
    private $onDiscard = null;
    /** @var (callable(): void)|null `2`: switch to the History tab */
    private $onShowHistory = null;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
    ) {
        $this->width = $width;
        $this->styles = $styles ?? Styles::dark();
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    protected function minWidth(): int
    {
        return 24;
    }

    protected function maxWidth(): int
    {
        return 200;
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
                if ($entry->path !== $previous) {
                    continue;
                }

                $this->list->select($i);
                $this->confirmDiscard = null !== $this->confirmDiscard ? $i : null;
                $this->invalidate();

                return;
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
            $this->confirmDiscard = null;
            if (('y' === $data || 'Y' === $data) && null !== $entry && null !== $this->onDiscard) {
                ($this->onDiscard)($entry);
            }
            $this->invalidate();

            return;
        }

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
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->entries), page: $this->lastRows)) {
            $this->fireSelect();
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'toggle_stage')) {
            if (null !== ($entry = $this->selectedEntry()) && null !== $this->onToggleStage) {
                ($this->onToggleStage)($entry);
            }

            return;
        }
        if ($kb->matches($data, 'show_history')) {
            if (null !== $this->onShowHistory) {
                ($this->onShowHistory)();
            }

            return;
        }
        if ($kb->matches($data, 'discard')) {
            if (null !== $this->selectedEntry()) {
                $this->confirmDiscard = $this->list->selected();
                $this->invalidate();
            }
        }
    }

    /** A right-click landed on a row: select it (like a left click) and return its entry. */
    public function selectRowAt(int $relRow): ?ChangeEntry
    {
        $index = $this->rowIndexAt($relRow);
        if (null === $index) {
            return null;
        }
        $this->list->select($index);
        $this->fireSelect();
        $this->invalidate();

        return $this->entries[$index];
    }

    /** Context-menu trigger: toggle the selected entry's staging (same path as Space). */
    public function toggleStageSelected(): void
    {
        if (null !== ($entry = $this->selectedEntry()) && null !== $this->onToggleStage) {
            ($this->onToggleStage)($entry);
            $this->invalidate();
        }
    }

    /** Context-menu trigger: arm the selected entry's two-press discard confirmation. */
    public function armDiscardSelected(): void
    {
        if (null !== $this->selectedEntry()) {
            $this->confirmDiscard = $this->list->selected();
            $this->invalidate();
        }
    }

    /** Context-menu trigger: switch to the History tab (same path as the `2` key). */
    public function showHistory(): void
    {
        if (null !== $this->onShowHistory) {
            ($this->onShowHistory)();
        }
    }

    /** The selected entry's widget-relative row (row 0 is the header). */
    public function selectedRow(): int
    {
        return $this->list->selected() - $this->list->scroll() + 1;
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
            $this->invalidate();

            return;
        }
        $this->fireSelect();
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

    private function rowIndexAt(int $relRow): ?int
    {
        return $this->list->rowIndexAt($relRow, \count($this->entries));
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
        $lines = [$this->styles
            ->of(Role::HeaderBar)
            ->withBold()
            ->apply(AnsiUtils::truncateToWidth($title, $width, '…', pad: true))];

        $scroll = $this->list->follow($rows - 1);
        for ($row = 1; $row < $rows; ++$row) {
            $index = $scroll + $row - 1;
            $entry = $this->entries[$index] ?? null;
            if (null === $entry) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }
            if ($index === $this->confirmDiscard) {
                $confirm = $this->translator->trans(' Discard changes? y = yes · any key = no');
                $lines[] = $this->styles->merge(Role::DangerBg, Role::Bright)->apply(AnsiUtils::truncateToWidth($confirm, $width, '…', pad: true));
                continue;
            }

            $checkbox = match ($entry->stagedState()) {
                'full' => Glyphs::CHECKBOX_ON,
                'partial' => Glyphs::CHECKBOX_PARTIAL,
                default => Glyphs::CHECKBOX_OFF,
            };
            $status = $entry->displayStatus();
            $statusText = \array_key_exists($status, self::STATUS_ROLE) ? $this->styles->of(self::STATUS_ROLE[$status])->apply($status) : $status;
            $label = $entry->path.(null !== $entry->renamedFrom ? ' ← '.$entry->renamedFrom : '');

            $text = AnsiUtils::truncateToWidth(' '.$checkbox.' '.$statusText.' '.$label, $width, '…', pad: true);
            $lines[] = match (true) {
                $this->list->isSelected($index) => $this->applyElement('selected', $text),
                $index === $this->hoveredIndex => $this->styles->of(Role::RowHighlightBg)->apply($text),
                default => $text,
            };
        }

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
            'toggle_stage' => [Key::SPACE],
            'discard' => ['d', Key::DELETE],
            'show_history' => ['2'],
            'fetch' => ['f'],
            'pull' => ['p'],
            'push' => ['shift+p'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
