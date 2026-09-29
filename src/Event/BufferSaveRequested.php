<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Save the open buffer of $path — the verb Ctrl+S performs, asked for by
 * something that is not the keyboard: {@see \Porta\Editor\BufferSaver}, on
 * behalf of a plugin.
 *
 * A command, so exactly one listener performs it: the service that owns
 * buffers and their writing, which is the one place a save stamps the file,
 * clears the modified mark and announces {@see BufferSaved} — the fact local
 * history snapshots on and the language servers hear as didSave. An event
 * rather than a constructor edge because that service is far heavier than
 * anything that only wants to ask for a save.
 */
final readonly class BufferSaveRequested
{
    public function __construct(
        public string $path,
    ) {}
}
