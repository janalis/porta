<?php

declare(strict_types=1);

namespace Porta\Support;

/** The result of an async operation, replacing the old bool success flag. */
enum Outcome
{
    case Ok;
    case Failed;

    // @mago-expect lint:no-boolean-flag-parameter -- the single bool→Outcome conversion boundary
    public static function of(bool $ok): self
    {
        return $ok ? self::Ok : self::Failed;
    }

    public function isOk(): bool
    {
        return self::Ok === $this;
    }
}
