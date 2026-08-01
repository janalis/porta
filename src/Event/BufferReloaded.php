<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\Buffer;

/**
 * A clean buffer's content was replaced with what the file now holds on disk,
 * after the poller found it changed underneath (document replaced, marked
 * saved, LSP told).
 *
 * A fact: everything named above has already happened when this is dispatched.
 * Not {@see BufferSaved} — nothing was written, the buffer caught up — and not
 * {@see BufferOpened}, which reacts to a tab appearing.
 *
 * Internal, like the rest of the buffer lifecycle: it carries a mutable
 * {@see Buffer} and so is deliberately not {@see PluginObservable}.
 */
final readonly class BufferReloaded
{
    public function __construct(
        public Buffer $buffer,
    ) {}
}
