<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Workbench\Menu\ViewMenuSource;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * A view that can take over the workspace: its identity, its lifecycle and its
 * focus ring.
 *
 * Identity is a STRING the view supplies, not an enum case core declares. The
 * enum was the reason a view had to be known at compile time — adding one meant
 * editing `WorkspaceView`, and a plugin cannot add a case to it. Everything
 * that used to range over `WorkspaceView::cases()` now ranges over what has
 * registered.
 *
 * {@see ownsMainArea()} moved here from the enum for the same reason: whether a
 * view detaches the editor or floats over it is a fact about the view, and the
 * Welcome modal — which floats, so "is the editor detached?" must not count it
 * — is the one that proves the distinction is real rather than decorative.
 */
interface ContributedView
{
    /** Stable, lowercase; the key every registry verb takes. */
    public function viewId(): string;

    /**
     * Whether opening this view DETACHES the editor layout, rather than
     * floating over a workspace that stays mounted behind it.
     */
    public function ownsMainArea(): bool;

    public function isOpen(): bool;

    public function open(): void;

    public function close(): void;

    public function toggle(): void;

    /**
     * This view's pointer surface, or null when it has no panes to click.
     *
     * Owned by the view rather than registered separately: a pointer target
     * that outlives its view, or exists without one, is a bug with no error —
     * and three services carried #[AsEager] purely to register themselves
     * because nothing else referenced them.
     */
    public function pointer(): ?ViewPointer;

    /** This view's context menus, or null when its panes offer none. */
    public function contextMenus(): ?ViewMenuSource;

    /** Where focus should land when a popup closes over the open view. */
    public function focusTarget(): AbstractWidget&FocusableInterface;

    /**
     * Cycle focus between the view's panes; false when the view has no focus
     * ring of its own (the Welcome modal: the dispatcher's modal branch
     * swallows every key first, so the caller falls through).
     */
    public function cycleFocus(int $direction): bool;
}
