<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Container\AsIdeService;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;

/**
 * Left-clicks on the status bar, the sibling of the right-click path in
 * {@see ContextMenuRouter}.
 *
 * The status bar had exactly one hit-test — `menuAt`, for right-click menus —
 * so the numbers people look at most (the problem counts, the caret position,
 * the LSP status) did nothing at all when clicked, while in PhpStorm every one
 * of them is a way in.
 *
 * Its own class rather than three more constructor arguments on
 * {@see MouseRouter}: the editor's action router is built INSIDE
 * {@see CodeEditorWidget} and is not a service, so reaching `goto_line` means
 * holding the widget — a dependency the mouse router has managed without.
 */
#[AsIdeService]
final readonly class StatusBarClickRouter
{
    public function __construct(
        private HitTester $hit,
        private StatusBarWidget $statusBar,
        private CommandRegistry $commands,
        private CodeEditorWidget $editor,
    ) {}

    /**
     * True when the click landed on an actionable cell and was consumed. A
     * cell with no action may still offer a menu — the branch's `▾` — and
     * $openMenu, the right-click router's, opens it: the menus are that
     * router's, and a select opens on a left click.
     *
     * @param \Closure(MouseEvent): bool $openMenu
     */
    public function route(MouseEvent $mouse, \Closure $openMenu): bool
    {
        $rect = $this->hit->rect($this->statusBar);
        if (null === $rect || !$rect->contains($mouse->row, $mouse->col)) {
            return false;
        }

        $action = $this->statusBar->clickAt($rect->toRelative($mouse->row, $mouse->col)['col']);
        if (null === $action) {
            return $openMenu($mouse);
        }

        // Registry commands (the tool windows) first; anything else is an
        // editor verb, which is where `goto_line` lives.
        if ($this->commands->has($action)) {
            $this->commands->run($action);

            return true;
        }

        $this->editor->runAction($action);

        return true;
    }
}
