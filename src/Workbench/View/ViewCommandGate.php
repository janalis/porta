<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Workbench\Menu\MenuContributions;

/**
 * What a command does to an open view before it runs: a command that acts
 * on the editor behind the view window closes the window first, so its
 * effect is seen; a command the view lists as its own leaves it open.
 *
 * Every menu item stays enabled while a view is open — the menu used to grey
 * out all but the open view's short list, which also greyed the way to the
 * other views. The list survives with its meaning turned round: it no longer
 * says what may run, only what runs over the window.
 *
 * Commands a view runs from inside itself never pass here; only the menu,
 * the command palette and key bindings — the ways a user reaches past it.
 */
#[AsIdeService]
final readonly class ViewCommandGate
{
    /**
     * The core views' own commands, keyed by view id; plugins contribute
     * theirs with their menu ({@see MenuContributions::viewSafe()}).
     * `ide_welcome` is in every list: a user who followed a Welcome step into
     * Settings must be able to get back without losing their place.
     */
    private const array KEEPS_OPEN = [
        'settings' => ['ide_quit', 'ide_settings', 'ide_command_palette', 'ide_help', 'ide_welcome'],
        'welcome' => ['ide_quit', 'ide_welcome', 'ide_command_palette', 'ide_help'],
        // The docs' own toggle, so F1 over them closes them rather than
        // closing them first and opening them again.
        'help' => ['ide_quit', 'ide_help', 'ide_command_palette', 'ide_welcome'],
    ];

    /**
     * Commands that move around the workbench rather than act on the editor:
     * the menu bar, the palette, focus cycling, the context-menu key, a
     * toast's dismissal, quitting. They reach any open view without closing
     * it — F10 closing the window it was pressed over would leave no way to
     * reach its menu.
     */
    private const array CHROME = ['ide_menu', 'ide_command_palette', 'ide_quit', 'ide_dismiss_toast', 'focus_next', 'focus_previous', 'show_context_menu'];

    public function __construct(
        private ViewRegistry $views,
        private MenuContributions $contributions,
    ) {}

    /**
     * Before command $id of $scope (`i` an IDE command, `e` an editor action)
     * runs: close the open view unless the command is one of its own.
     */
    public function before(string $scope, string $id): void
    {
        $active = $this->views->activeId();
        if (null === $active || ('i' === $scope && $this->keepsOpen($active, $id))) {
            return;
        }
        $this->views->close($active);
    }

    /** Whether IDE command $id runs over view $viewId rather than closing it: the workbench's own, or the view's. */
    public function keepsOpen(string $viewId, string $id): bool
    {
        $own = [...self::CHROME, ...(self::KEEPS_OPEN[$viewId] ?? []), ...($this->contributions->viewSafe()[$viewId] ?? [])];

        return \in_array($id, $own, strict: true);
    }
}
