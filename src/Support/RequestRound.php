<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * A generation counter for "race an async response against a timeout, drop
 * whichever loses" — the bookkeeping {@see \Porta\Lsp\FoldingController} and
 * {@see \Porta\Lsp\FormatOnSave} both need: {@see start()} issues a token for
 * the round about to begin, and {@see supersede()} is the check-then-bump a
 * timeout or response callback makes before acting, so only the first of the
 * two to arrive — or neither, if a newer round already started — does
 * anything.
 */
final class RequestRound
{
    private int $round = 0;

    /** Start a new round, superseding whatever was still pending. */
    public function start(): int
    {
        return ++$this->round;
    }

    /** True (and advances the round) when $generation is still the current one. */
    public function supersede(int $generation): bool
    {
        if ($generation !== $this->round) {
            return false;
        }
        ++$this->round;

        return true;
    }
}
