<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Support;

use Porta\DiffPlugin\Event\DiffContextCycleRequested;
use Porta\DiffPlugin\Event\DiffCopyRequested;
use Porta\DiffPlugin\Event\DiffLayoutToggled;
use Porta\DiffPlugin\Event\DiffStagingRequested;
use Porta\DiffPlugin\Event\DiffWhitespaceToggleRequested;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Registers listeners for the diff pane's events from closures shaped like
 * the callbacks they replaced — the diff half of the git plugin's
 * `GitWidgetEvents`, moved with the pane so this plugin's tests need nothing
 * of git's.
 */
final class DiffWidgetEvents
{
    public static function wire(
        EventDispatcher $events,
        ?callable $onStageSelection = null,
        ?callable $onCycleContext = null,
        ?callable $onLayoutToggle = null,
        ?callable $onToggleWhitespace = null,
        ?callable $onCopy = null,
    ): void {
        if (null !== $onStageSelection) {
            $events->addListener(DiffStagingRequested::class, static fn (DiffStagingRequested $event) => $onStageSelection($event->selection));
        }
        if (null !== $onCycleContext) {
            $events->addListener(DiffContextCycleRequested::class, static fn (DiffContextCycleRequested $event) => $onCycleContext());
        }
        if (null !== $onLayoutToggle) {
            $events->addListener(DiffLayoutToggled::class, static fn (DiffLayoutToggled $event) => $onLayoutToggle());
        }
        if (null !== $onToggleWhitespace) {
            $events->addListener(DiffWhitespaceToggleRequested::class, static fn (DiffWhitespaceToggleRequested $event) => $onToggleWhitespace());
        }
        if (null !== $onCopy) {
            $events->addListener(DiffCopyRequested::class, static fn (DiffCopyRequested $event) => $onCopy($event->text));
        }
    }
}
