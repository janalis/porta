<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The debug session's user-visible status changed (started, paused, resumed,
 * terminated). Signals staleness only — reactors pull the current segment.
 */
final readonly class DebugStatusChanged {}
