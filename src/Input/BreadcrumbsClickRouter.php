<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Symfony\Component\Tui\Tui;

/**
 * Left-clicks on the navigation bar under the tabs: a previewed file's
 * arrangement toggle picks its arrangement, a folder or the file reveals
 * itself in the tree, and a scope moves the caret to its declaration.
 *
 * Its own class, the {@see StatusBarClickRouter} shape: the bar grew a second
 * kind of crumb, and with it {@see MouseRouter} grew past the complexity a
 * single router is allowed.
 */
#[AsIdeService]
final readonly class BreadcrumbsClickRouter
{
    public function __construct(
        private HitTester $hit,
        private EditorLayoutPanes $panes,
        private Tui $tui,
    ) {}

    /**
     * True when the press landed on a toggle or a crumb and was consumed.
     *
     * The bar renders zero rows without a trail (help/image tabs) but the
     * renderer keeps its last non-empty rect, so the click only counts when
     * something is actually under the pointer — otherwise it falls through to
     * whatever now owns the row. Only row 0 holds crumbs: the rule under it
     * is nobody's.
     */
    public function route(MouseEvent $mouse): bool
    {
        $rel = $this->hit->hit($this->panes->breadcrumbs, $mouse->row, $mouse->col);
        $consumed = null !== $rel && 0 === $rel['row'] && $this->press($rel['col']);
        if ($consumed) {
            $this->tui->requestRender();
        }

        return $consumed;
    }

    /** The toggle first, then a path crumb, then a scope crumb — lazily, in that order. */
    private function press(int $col): bool
    {
        $bar = $this->panes->breadcrumbs;

        return $bar->pressToggle($col) || $this->reveal($bar->pathAtColumn($col)) || $this->jump($bar->lineAtColumn($col));
    }

    private function reveal(?string $path): bool
    {
        if (null !== $path) {
            $this->panes->tree->revealPath($path);
        }

        return null !== $path;
    }

    private function jump(?int $line): bool
    {
        if (null !== $line) {
            $this->tui->setFocus($this->panes->editor);
            $this->panes->editor->jumpTo($line, 0);
        }

        return null !== $line;
    }
}
