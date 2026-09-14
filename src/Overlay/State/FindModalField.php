<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

/** Which of the find-in-files modal's three text fields typing edits. */
enum FindModalField
{
    case Query;
    case Replace;
    case Mask;

    /** Tab order: query → replace → mask → query. */
    public function next(): self
    {
        return match ($this) {
            self::Query => self::Replace,
            self::Replace => self::Mask,
            self::Mask => self::Query,
        };
    }
}
