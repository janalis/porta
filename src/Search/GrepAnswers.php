<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Support\AsyncProcess;

/**
 * What the grep a project's searches run said it takes — `--null`, `-P` —
 * asked in the background and remembered, and the one search waiting for an
 * answer still out. A search never waits on the loop: it waits here.
 *
 * A question is asked once it can be (a server not reached yet has no one
 * to answer) and settled only by grep's own answer: 1 is no `x` in nothing,
 * the flag taken; 2 a usage error, refused. Anything else — ssh failing, the
 * probe killed or out of time — is no answer: not asked again during that
 * search, asked again by the next.
 */
final class GrepAnswers
{
    /** @var array<string, bool> by flag, what grep said; a flag not here was not answered */
    private array $takes = [];

    /** @var array<string, bool> the flags asked about whose answer is still out */
    private array $asking = [];

    /** @var array<string, bool> the flags whose question got no answer during this search */
    private array $missed = [];

    /** The search waiting for the answers still out: run as the last one lands. */
    private ?\Closure $parked = null;

    public function __construct(
        private readonly string $cwd,
    ) {}

    /** Whether grep said it takes $flag. */
    public function takes(string $flag): bool
    {
        return $this->takes[$flag] ?? false;
    }

    /** A new search: a question that got no answer may be asked again. */
    public function newSearch(): void
    {
        $this->missed = [];
    }

    /** The waiting search is not to run: it was cancelled or replaced. */
    public function drop(): void
    {
        $this->parked = null;
    }

    /**
     * Ask whether grep takes $flag, by running the command $probe builds —
     * built only when it is asked; null is no one to ask yet.
     *
     * @param \Closure(): (list<string>|null) $probe
     */
    public function ask(string $flag, \Closure $probe): void
    {
        if (\array_key_exists($flag, $this->takes) || ($this->asking[$flag] ?? false) || ($this->missed[$flag] ?? false)) {
            return;
        }
        $command = $probe();
        if (null === $command) {
            return;
        }
        $this->asking[$flag] = new AsyncProcess($command, $this->cwd, timeout: 5.0)->start(onExit: function (int $code) use ($flag): void {
            $this->asking[$flag] = false;
            $this->missed[$flag] = 1 !== $code && 2 !== $code;
            if (!$this->missed[$flag]) {
                $this->takes[$flag] = 1 === $code;
            }
            $this->runIfAnswered();
        });
        // A grep that cannot even be spawned answers nothing: the search's own fallback takes over.
        $this->missed[$flag] = !$this->asking[$flag];
    }

    /** Run $search now if no answer is out, else once the last one lands. */
    public function whenAnswered(\Closure $search): void
    {
        $this->parked = $search;
        $this->runIfAnswered();
    }

    private function runIfAnswered(): void
    {
        $parked = \in_array(true, $this->asking, strict: true) ? null : $this->parked;
        if (null !== $parked) {
            $this->parked = null;
            $parked();
        }
    }
}
