<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\Buffer;

/**
 * A buffer was shown in the editor area. Dispatched on every show, including
 * re-shows of the already-active buffer (config re-apply, pane swaps) — core
 * listeners rely on re-firing; the plugin bridge dedups consecutive paths.
 */
final readonly class BufferSwitched
{
    public function __construct(
        public Buffer $buffer,
    ) {}
}
