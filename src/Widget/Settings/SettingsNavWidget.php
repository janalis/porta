<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\LanguageCatalog;
use Porta\I18n\Translator;
use Porta\Layout\ResizableSidebar;
use Porta\Support\IntMath;
use Porta\Ui\ListSelection;
use Porta\Ui\Style;
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
 * The settings view's left navigation sidebar: General, Keybindings and a
 * (non-selectable) Languages header followed by one row per configurable
 * language. Selecting a row (arrow/click) fires onSelect with its id
 * ('general' | 'keybindings' | 'lang:<id>'); the header is skipped. Self-sizing
 * so the sidebar can be dragged wider/narrower.
 *
 * @phpstan-type Entry array{id: string|null, label: string, kind: string}
 */
final class SettingsNavWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface, ResizableSidebar
{
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

    private const array SECTIONS = ['general' => 'General', 'keybindings' => 'Keybindings'];

    /** @var list<Entry> */
    private readonly array $entries;
    private readonly ListSelection $list;
    private ?int $hoveredIndex = null;

    /** @var (callable(string): void)|null selection moved to this entry id */
    private $onSelect = null;

    public function __construct(
        private readonly Translator $translator,
        private int $width = 22,
    ) {
        $entries = [];
        foreach (self::SECTIONS as $id => $label) {
            $entries[] = ['id' => $id, 'label' => $label, 'kind' => 'section'];
        }
        $entries[] = ['id' => null, 'label' => 'Languages', 'kind' => 'header'];
        foreach (LanguageCatalog::LANGUAGES as $id => $name) {
            $entries[] = ['id' => 'lang:'.$id, 'label' => $name, 'kind' => 'language'];
        }
        $this->entries = $entries;
        $this->list = new ListSelection();
    }

    /** Resize the sidebar to $width columns (clamped sane). */
    public function setWidth(int $width): void
    {
        $width = IntMath::clamp($width, 14, 60);
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function onSelect(callable $callback): self
    {
        $this->onSelect = $callback;

        return $this;
    }

    /** The id of the currently selected entry ('general' | 'keybindings' | 'lang:<id>'). */
    public function selectedId(): ?string
    {
        return $this->entries[$this->list->selected()]['id'] ?? null;
    }

    /** Highlight the entry with this id (the controller syncs the active section). */
    public function setActive(string $id): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['id'] === $id) {
                $this->list->select($i);
                $this->invalidate();

                return;
            }
        }
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);
        } elseif ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);
        } elseif ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->prevSelectable($this->list->selected()));
        } elseif ($kb->matches($data, 'move_down')) {
            $this->moveTo($this->nextSelectable($this->list->selected()));
        }
    }

    /** A click selects (and fires) the entry under the pointer; the header is inert. */
    public function handleClick(int $relRow): void
    {
        $index = $this->rowIndexAt($relRow);
        if (null !== $index && 'header' !== $this->entries[$index]['kind']) {
            $this->moveTo($index);
        }
    }

    public function hoverRow(int $relRow): bool
    {
        $index = $this->rowIndexAt($relRow);

        return $this->setHovered(null !== $index && 'header' !== $this->entries[$index]['kind'] ? $index : null);
    }

    public function clearHover(): bool
    {
        return $this->setHovered(null);
    }

    private function moveTo(int $index): void
    {
        if ($index !== $this->list->selected()) {
            $this->list->select($index);
            $this->invalidate();
        }
        $id = $this->entries[$index]['id'] ?? null;
        if (null !== $id && null !== $this->onSelect) {
            ($this->onSelect)($id);
        }
    }

    private function prevSelectable(int $from): int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            if ('header' !== $this->entries[$i]['kind']) {
                return $i;
            }
        }

        return $from;
    }

    private function nextSelectable(int $from): int
    {
        $count = \count($this->entries);
        for ($i = $from + 1; $i < $count; ++$i) {
            if ('header' !== $this->entries[$i]['kind']) {
                return $i;
            }
        }

        return $from;
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
        $index = $this->list->scroll() + $relRow;

        return $relRow >= 0 && isset($this->entries[$index]) ? $index : null;
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);

        $scroll = $this->list->follow($rows);
        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $index = $scroll + $row;
            $entry = $this->entries[$index] ?? null;
            if (null === $entry) {
                $lines[] = str_repeat(' ', $width);
                continue;
            }

            if ('header' === $entry['kind']) {
                $lines[] = "\x1b[48;5;235m\x1b[38;5;245m\x1b[1m".AnsiUtils::truncateToWidth(' '.$this->translator->trans($entry['label']), $width, '…', pad: true)."\x1b[0m";
                continue;
            }

            $selected = $index === $this->list->selected();
            $bg = $selected
                ? ($this->focused ? Style::SELECTED_ROW : "\x1b[48;5;237m")
                : ($index === $this->hoveredIndex ? Style::POPUP_ROW_BG : '');
            $text = 'language' === $entry['kind'] ? '   '.$entry['label'] : ' '.$this->translator->trans($entry['label']);
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
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
