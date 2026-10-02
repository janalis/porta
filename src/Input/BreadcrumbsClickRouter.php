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
        if (null === $rel || 0 !== $rel['row']) {
            return false;
        }
        $bar = $this->panes->breadcrumbs;
        $col = $rel['col'];
        if ($bar->pressToggle($col)) {
            $this->tui->requestRender();

            return true;
        }
        $path = $bar->pathAtColumn($col);
        if (null !== $path) {
            $this->panes->tree->revealPath($path);
            $this->tui->requestRender();

            return true;
        }
        $line = $bar->lineAtColumn($col);
        if (null === $line) {
            return false;
        }
        $this->tui->setFocus($this->panes->editor);
        $this->panes->editor->jumpTo($line, 0);
        $this->tui->requestRender();

        return true;
    }
}
