<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Debug\DebugController;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Debugger commands. The step/stop keys (F7/F8/F9/Ctrl+F2) are shared with
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
            ->add('ide_debug', fn () => $this->debug->startForCurrentFile(), keyBound: KeyDispatch::Bound)
            ->add('debug_toggle_breakpoint', fn () => $this->debug->toggleBreakpointAtCaret(), keyBound: KeyDispatch::Bound)
            ->add('debug_edit_breakpoint', fn () => $this->debug->editBreakpointAtCaret(), keyBound: KeyDispatch::Bound)
            ->add('debug_add_watch', fn () => $this->debug->addWatch(), keyBound: KeyDispatch::Bound)
            ->add('debug_step_over', fn () => $this->debug->stepOver(), keyBound: KeyDispatch::Bound, when: $paused)
            ->add('debug_step_into', fn () => $this->debug->stepIn(), keyBound: KeyDispatch::Bound, when: $paused)
            ->add('debug_step_out', fn () => $this->debug->stepOut(), keyBound: KeyDispatch::Bound, when: $paused)
            ->add('debug_continue', fn () => $this->debug->continueRun(), keyBound: KeyDispatch::Bound, when: $paused)
            ->add('debug_run_to_cursor', fn () => $this->debug->runToCursor(), keyBound: KeyDispatch::Bound, when: $paused)
            ->add('debug_stop', fn () => $this->debug->stop(), keyBound: KeyDispatch::Bound, when: $active)
            ->add('debug_evaluate', fn () => $this->debug->evaluatePrompt(), label: 'Debug: Evaluate Expression', when: $paused)
            ->add('debug_exception_breakpoints', fn () => $this->debug->exceptionBreakpointsOverlay(), label: 'Debug: Exception Breakpoints', when: $active);
    }
}
