<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Show the active terminal session — a session tab was clicked, or ctrl+`.
 *
 * No payload: "which session" is the run controller's own state, and an event
 * that re-stated it could disagree with it.
 */
final readonly class TerminalRevealRequested {}
