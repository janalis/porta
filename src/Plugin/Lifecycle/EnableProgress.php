<?php

declare(strict_types=1);

namespace Porta\Plugin\Lifecycle;

/**
 * What an enable has managed so far, carried through its queue.
 *
 * One enable can span several plugins and several composer runs, and the
 * closing report needs both halves of the story: whether ANYTHING loaded, and
 * whether anything already explained itself with an error. Two bools threaded
 * through four signatures is what this replaces — as a pair they are one fact
 * ("how did it go"), and naming it is what makes `withFailure()` read as an
 * event rather than as a flag being set.
 */
final readonly class EnableProgress
{
    public function __construct(
        /** Whether at least one target's code actually loaded. */
        public bool $activated = false,
        /** Whether something went wrong AND already said so — the closing flash then stays quiet. */
        public bool $reported = false,
    ) {}

    public function withActivation(bool $loaded): self
    {
        return new self($this->activated || $loaded, $this->reported);
    }

    public function withFailure(): self
    {
        return new self($this->activated, reported: true);
    }
}
