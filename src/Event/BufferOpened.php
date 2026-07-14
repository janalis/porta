<?php

declare(strict_types=1);

namespace Porta\Event;

use Porta\Editor\Buffer;

/** A file (text, image or help page) was opened into a new buffer/tab. */
final readonly class BufferOpened
{
    public function __construct(
        public Buffer $buffer,
    ) {}
}
