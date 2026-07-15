<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Debug\DebugController;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Debugger commands. The step/stop keys (F7/F8/F9/^F2) are shared with
 * editor/widget bindings, so their key dispatch is gated on the session
 * state and falls through to the focused widget when not debugging.
 */
#[AsTaggedItem(priority: 600)]
final class DebugCommands implements CommandProvider
{
    public function __construct(
        private readonly DebugController $debug,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $paused = fn (): bool => $this->debug->isPaused();
        $active = fn (): bool => $this->debug->isActive();
        $commands
            ->add('ide_debug', fn () => $this->debug->startForCurrentFile(), keyBound: true)
            ->add('debug_toggle_breakpoint', fn () => $this->debug->toggleBreakpointAtCaret(), keyBound: true)
            ->add('debug_step_over', fn () => $this->debug->stepOver(), keyBound: true, when: $paused)
            ->add('debug_step_into', fn () => $this->debug->stepIn(), keyBound: true, when: $paused)
            ->add('debug_step_out', fn () => $this->debug->stepOut(), keyBound: true, when: $paused)
            ->add('debug_continue', fn () => $this->debug->continueRun(), keyBound: true, when: $paused)
            ->add('debug_stop', fn () => $this->debug->stop(), keyBound: true, when: $active);
    }
}
