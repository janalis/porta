<?php

declare(strict_types=1);

namespace Porta\Desktop;

use Porta\Container\AsIdeService;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\Menu\MenuItem;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The application menus as the operating system's menu bar shows them: the same
 * model the in-window bar draws, so a plugin's or a locale's change reaches both.
 *
 * Whether an item is enabled or ticked is only known by asking it, and that
 * changes with the caret. {@see changes()} is polled, and says something only
 * when the answer differs. The generation names the menus' shape alone: a click
 * that crosses a state change still runs its item, one that crosses a rebuild
 * of the menus is dropped rather than landing on whatever moved under it.
 */
#[AsIdeService]
final class SystemMenu
{
    private string $sent = '';

    public function __construct(
        private readonly MenuBarWidget $menuBar,
    ) {}

    /** @return string|null the menus to send, base64-encoded JSON; null when the host already has them */
    public function changes(): ?string
    {
        $payload = \json_encode(
            ['generation' => $this->generation(), 'menus' => \array_map(self::menu(...), $this->menuBar->menus())],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($payload === $this->sent) {
            return null;
        }
        $this->sent = $payload;

        return \base64_encode($payload);
    }

    /** Runs what the user chose, provided the menus are still the ones they were looking at. */
    public function choose(string $generation, int $menu, int $item): void
    {
        if ($generation === $this->generation()) {
            $this->menuBar->runMenuItem($menu, $item);
        }
    }

    private function generation(): string
    {
        $shape = \array_map(
            static fn (Menu $menu): array => [$menu->title, \array_map(static fn (MenuItem $item): string => $item->label, $menu->items)],
            $this->menuBar->menus(),
        );

        return \hash('xxh128', \json_encode($shape, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return array{title: string, items: list<array{label: string, shortcut: string, separator: bool, enabled: bool, checked: ?bool}>} */
    private static function menu(Menu $menu): array
    {
        return [
            'title' => AnsiUtils::stripAnsiCodes($menu->title),
            'items' => \array_map(static fn (MenuItem $item): array => [
                'label' => AnsiUtils::stripAnsiCodes($item->label),
                'shortcut' => AnsiUtils::stripAnsiCodes($item->shortcut),
                'separator' => $item->separator,
                'enabled' => !$item->separator && $item->enabled(),
                'checked' => $item->separator ? null : $item->checked(),
            ], $menu->items),
        ];
    }
}
