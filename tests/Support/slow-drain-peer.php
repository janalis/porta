<?php

declare(strict_types=1);

/**
 * A peer that starts draining its stdin only after a pause — deterministic
 * back-pressure for the NonBlockingTerminal overflow tests: the writer's
 * blocking-flush fallback blocks briefly against this peer instead of
 * deadlocking against one that never reads. Echoes everything to stdout.
 */
usleep(150_000);
fpassthru(\STDIN);
