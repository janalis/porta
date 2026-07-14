<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\Buffer;

/** A text buffer was written to disk (after markSaved/stampFromDisk). */
final readonly class BufferSaved
{
    public function __construct(
        public Buffer $buffer,
    ) {}
}
