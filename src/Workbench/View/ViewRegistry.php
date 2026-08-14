<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Workbench\Menu\ViewMenuSources;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The view interlock: at most one view owns the main area at a time, and
 * everything that needs "is a full view open?" asks here instead of holding a
 * controller.
 *
 * Keyed by the id a view supplies, so registering is the whole of adding one.
 * The predecessor kept two CONCRETE controller fields beside its map — for
 * opening git's file history and refreshing the settings rows — which is why
 * `Workbench` depended on `Git`, and why `GitViewController` sat inside a
 * pinned dependency cycle. Both capabilities belong to their own view and are
 * reached by asking for it, not by the registry knowing its type.
 *
 * Queries answer false for a view that has not registered, which is what makes
 * the boot order safe: the input stack is built before the view controllers,
 * and asked questions before they exist.
 */
#[AsIdeService]
final class ViewRegistry
{
    /** @var array<string, ContributedView> */
    private array $views = [];

    public function __construct(
        private readonly ViewPointers $pointers = new ViewPointers(),
        private readonly ViewMenuSources $menus = new ViewMenuSources(),
    ) {}

    /**
     * Each view registers itself when constructed, and its pointer and context
     * menus go with it — they are the view's, not separate services that
     * happen to answer for the same id.
     */
    public function register(ContributedView $view): void
    {
        $this->views[$view->viewId()] = $view;
        $pointer = $view->pointer();
        if (null !== $pointer) {
            $this->pointers->register($view->viewId(), $pointer);
        }
        $menus = $view->contextMenus();
        if (null !== $menus) {
            $this->menus->register($view->viewId(), $menus);
        }
    }

    /** The registered view for $id, or null. The way to reach a view's own verbs. */
    public function find(string $id): ?ContributedView
    {
        return $this->views[$id] ?? null;
    }

    public function isOpen(string $id): bool
    {
        return $this->find($id)?->isOpen() ?? false;
    }

    /**
     * Whether any view owns the main area.
     *
     * Callers that only need "is the editor layout detached?" ask this rather
     * than OR-ing individual views, so adding a view does not mean hunting down
     * every guard that would otherwise grow a term. A view that floats instead
     * of detaching says so itself ({@see ContributedView::ownsMainArea()}).
     */
    public function isAnyOpen(): bool
    {
        foreach ($this->views as $view) {
            if ($view->ownsMainArea() && $view->isOpen()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The open view, of ANY kind — the floating Welcome modal included.
     *
     * Deliberately not the same question as {@see isAnyOpen()}: focus and the
     * menu's view-safe command list care that a view is up at all, while the
     * layout cares only whether the editor got detached. Welcome is open and
     * does not detach, and conflating the two silently gives it the editor's
     * focus ring and the editor's menu.
     */
    public function activeView(): ?ContributedView
    {
        foreach ($this->views as $view) {
            if ($view->isOpen()) {
                return $view;
            }
        }

        return null;
    }

    /** The open view's id — what the menu model keys its view-safe command list by. */
    public function activeId(): ?string
    {
        return $this->activeView()?->viewId();
    }

    /** Toggle a view; every other yields first (one at a time). */
    public function toggle(string $id): void
    {
        $this->closeOthers($id);
        $this->find($id)?->toggle();
    }

    /** Open a view; the others yield first. */
    public function open(string $id): void
    {
        $this->closeOthers($id);
        $this->find($id)?->open();
    }

    /** Close one view alone — e.g. a floating picker taking the span slot from Welcome. */
    public function close(string $id): void
    {
        $this->find($id)?->close();
    }

    /** Close whichever view is open, returning the main area to the editor layout. */
    public function closeAll(): void
    {
        foreach ($this->views as $view) {
            $view->close();
        }
    }

    private function closeOthers(string $target): void
    {
        foreach ($this->views as $id => $view) {
            if ($id === $target) {
                continue;
            }

            $view->close();
        }
    }

    /** The open view's focus target, or null when the editor layout owns the main area. */
    public function focusTarget(): ?AbstractWidget
    {
        return $this->activeView()?->focusTarget();
    }

    /** Cycle focus inside the open view; false when the editor layout owns the ring. */
    public function cycleFocus(int $direction): bool
    {
        return $this->activeView()?->cycleFocus($direction) ?? false;
    }
}
