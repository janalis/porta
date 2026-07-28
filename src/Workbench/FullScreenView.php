<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The role a view controller plays inside {@see WorkspaceViews}: the
 * lifecycle verbs the interlock drives plus the focus hand-off. Three
 * implementations (git, settings, welcome), so the alias pass leaves it
 * alone — instances travel through {@see WorkspaceViews::attach}, never
 * autowiring.
 */
interface FullScreenView
{
    public function isOpen(): bool;

    public function open(): void;

    public function close(): void;

    public function toggle(): void;

    /** Where focus should land when a popup closes over the open view. */
    public function focusTarget(): AbstractWidget&FocusableInterface;

    /**
     * Cycle focus between the view's panes; false when the view has no
     * focus ring of its own (the Welcome modal: the dispatcher's modal
     * branch swallows every key first, so the caller falls through).
     */
    public function cycleFocus(int $direction): bool;
}
