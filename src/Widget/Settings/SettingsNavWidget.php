<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Config\LanguageCatalog;
use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Layout\ResizableSidebar;
use Porta\Ui\ColorPalette;
use Porta\Ui\ListSelection;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Widget\FillsPaneTrait;
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
 * The settings view's left navigation sidebar: General, Keybindings and a
 * (non-selectable) Languages header followed by one row per configurable
 * language. Selecting a row (arrow/click) fires onSelect with its id
 * ('general' | 'keybindings' | 'lang:<id>'); the header is skipped. Self-sizing
 * so the sidebar can be dragged wider/narrower.
 *
 * @phpstan-type Entry array{id: string|null, label: string, kind: string}
 */
#[AsWidget('settings-nav', flex: 0)]
final class SettingsNavWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface, ResizableSidebar
{
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;
    use PanelWidth;

    private const array SECTIONS = ['general' => 'General', 'keybindings' => 'Keybindings', 'plugins' => 'Plugins'];

    /** @var list<Entry> */
    private array $entries;
    private readonly ListSelection $list;
    private ?int $hoveredIndex = null;

    /** @var (callable(string): void)|null selection moved to this entry id */
    private $onSelect = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        int $width = 22,
        ?Palette $palette = null,
    ) {
        $this->width = $width;
        $this->palette = $palette ?? ColorPalette::dark();
        $this->entries = $this->buildEntries([]);
        $this->list = new ListSelection();
    }

    /**
     * The nav rows: sections, one sub-item per active plugin under Plugins,
     * then the Languages header and its rows.
     *
     * @param list<string> $pluginNames
     *
     * @return list<Entry>
     */
    private function buildEntries(array $pluginNames): array
    {
        $entries = [];
        foreach (self::SECTIONS as $id => $label) {
            $entries[] = ['id' => $id, 'label' => $label, 'kind' => 'section'];
            if ('plugins' === $id) {
                foreach ($pluginNames as $name) {
                    $entries[] = ['id' => 'plugin:'.$name, 'label' => $name, 'kind' => 'plugin'];
                }
            }
        }
        $entries[] = ['id' => null, 'label' => 'Languages', 'kind' => 'header'];
        foreach (LanguageCatalog::LANGUAGES as $id => $name) {
            $entries[] = ['id' => 'lang:'.$id, 'label' => $name, 'kind' => 'language'];
        }

        return $entries;
    }

    /**
     * Rebuild the per-plugin sub-items (a plugin was installed or removed),
     * keeping the selection on the same id — or falling back to the Plugins
     * section when the selected plugin just vanished.
     *
     * @param list<string> $names
     */
    public function setPluginEntries(array $names): void
    {
        $selected = $this->selectedId();
        $this->entries = $this->buildEntries($names);
        $ids = array_column($this->entries, 'id');
        $this->setActive(null !== $selected && \in_array($selected, $ids, strict: true) ? $selected : 'plugins');
        $this->invalidate();
    }

    protected function minWidth(): int
    {
        return 14;
    }

    protected function maxWidth(): int
    {
        return 60;
    }

    public function onSelect(callable $callback): self
    {
        $this->onSelect = $callback;

        return $this;
    }

    /** The id of the currently selected entry ('general' | 'keybindings' | 'plugins' | 'plugin:<name>' | 'lang:<id>'). */
    public function selectedId(): ?string
    {
        return $this->entries[$this->list->selected()]['id'] ?? null;
    }

    /** Highlight the entry with this id (the controller syncs the active section). */
    public function setActive(string $id): void
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry['id'] !== $id) {
                continue;
            }

            $this->list->select($i);
            $this->invalidate();

            return;
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

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->prevSelectable($this->list->selected()));

            return;
        }
        if ($kb->matches($data, 'move_down')) {
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

        return $relRow >= 0 && array_key_exists($index, $this->entries) ? $index : null;
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
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            if ('header' === $entry['kind']) {
                $lines[] =
                    $this->palette->of(Role::Toolbar)
                    .$this->palette->of(Role::Dim)
                    ."\x1b[1m"
                    .AnsiUtils::truncateToWidth(' '.$this->translator->trans($entry['label']), $width, '…', pad: true)
                    ."\x1b[0m";
                continue;
            }

            $selected = $index === $this->list->selected();
            $bg = match (true) {
                $selected && $this->focused => $this->palette->of(Role::SelectedRow),
                $selected => $this->palette->of(Role::SelectedRowUnfocused),
                $index === $this->hoveredIndex => $this->palette->of(Role::RowHighlightBg),
                default => '',
            };
            // Language and plugin sub-items indent under their section and keep
            // their own (untranslated) names.
            $text = \in_array($entry['kind'], ['language', 'plugin'], strict: true) ? '   '.$entry['label'] : ' '.$this->translator->trans($entry['label']);
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
