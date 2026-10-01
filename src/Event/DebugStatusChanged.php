<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * The debug session's user-visible status changed (started, paused, resumed,
 * terminated). Signals staleness only — reactors pull the current segment,
 * and a plugin reads the session through {@see \Porta\Plugin\Api\Runs::debugger()}.
 */
final readonly class DebugStatusChanged implements PluginObservable {}
