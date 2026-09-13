<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Event\ProjectOpenRequested;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * The performer of {@see ProjectOpenRequested}: end this session, and remember
 * where to go next.
 *
 * A service rather than a closure in {@see \Porta\Ide}, which is where this
 * started. The closure worked and was invisible to the bus's own gate — a
 * command event is required to have exactly ONE performer, and that is checked
 * against the container, so a listener registered at boot by the object that
 * owns the container could never be counted. An event nobody can see the
 * listener for is an event nobody can reason about.
 *
 * Stopping the Tui is all the "switching" there is. `Ide::run()` then unwinds
 * through its ordinary teardown — buffers saved, plugins shut down, terminal
 * restored — and the console entrypoint reads {@see root()} back afterwards to
 * decide whether to boot again somewhere else. Nothing here knows what a
 * project holds, because nothing here has to.
 */
#[AsIdeService]
final class ReopenRequest
{
    private ?string $root = null;

    public function __construct(
        private readonly Tui $tui,
    ) {}

    #[AsEventListener]
    public function onProjectOpenRequested(ProjectOpenRequested $event): void
    {
        // The LAST request wins. Nothing in the UI can send two — the overlay
        // closes on a pick — but "the most recent thing you asked for is where
        // you go" is the reading that needs no rule.
        $this->root = $event->rootDir;
        $this->tui->stop();
    }

    /** Where to reopen once the session ends, or null on an ordinary quit. */
    public function root(): ?string
    {
        return $this->root;
    }
}
