<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Support\AsyncProcess;

/**
 * What the grep a project's searches run said it takes — `--null`, `-P` —
 * asked in the background and remembered, and the searches waiting for an
 * answer still out. A search never waits on the loop: it waits here. One
 * instance may serve every engine searching the same project.
 *
 * A question is asked once it can be (a server not reached yet has no one
 * to answer) and settled only by grep's own answer: 1 is no `x` in nothing,
 * the flag taken; 2 a usage error, refused. Anything else — ssh failing, the
 * probe killed or out of time — is no answer: not asked again during that
 * search, asked again by the next. A question that once went unanswered
 * holds no search up again: a slow link would have stalled every one.
 */
final class GrepAnswers
{
    /** @var array<string, bool> by flag, what grep said; a flag not here was not answered */
    private array $takes = [];

    /** @var array<string, bool> the flags asked about whose answer is still out */
    private array $asking = [];

    /** @var array<string, bool> the flags whose question got no answer during this search */
    private array $missed = [];

    /** @var array<string, bool> the flags whose question ever went unanswered: never waited on again */
    private array $unreliable = [];

    /** @var array<int, array{list<string>, \Closure(): void}> by engine, the flags its search waits on, and the search */
    private array $waiting = [];

    /** @param float $timeout seconds a question may take before it counts as unanswered */
    public function __construct(
        private readonly string $rootDir,
        private readonly float $timeout = 5.0,
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

    /** $engine's waiting search is not to run: it was cancelled or replaced. */
    public function drop(object $engine): void
    {
        unset($this->waiting[\spl_object_id($engine)]);
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
        $this->asking[$flag] = new AsyncProcess($command, $this->rootDir, timeout: $this->timeout)->start(onExit: function (int $code) use ($flag): void {
            $this->asking[$flag] = false;
            $this->missed[$flag] = 1 !== $code && 2 !== $code;
            $this->unreliable[$flag] = ($this->unreliable[$flag] ?? false) || $this->missed[$flag];
            if (!$this->missed[$flag]) {
                $this->takes[$flag] = 1 === $code;
            }
            $this->runAnswered();
        });
        // A grep that cannot even be spawned answers nothing: the search's own fallback takes over.
        $this->missed[$flag] = !$this->asking[$flag];
    }

    /**
     * Run $search for $engine now if none of $flags is still being asked,
     * else once the last of them lands. A newer one from the same engine
     * replaces it.
     *
     * @param list<string>      $flags
     * @param \Closure(): void  $search
     */
    public function whenAnswered(object $engine, array $flags, \Closure $search): void
    {
        $this->waiting[\spl_object_id($engine)] = [$flags, $search];
        $this->runAnswered();
    }

    private function runAnswered(): void
    {
        foreach ($this->waiting as $id => [$flags, $search]) {
            if (\array_any($flags, fn (string $flag): bool => ($this->asking[$flag] ?? false) && !($this->unreliable[$flag] ?? false))) {
                continue;
            }
            unset($this->waiting[$id]);
            $search();
        }
    }
}
