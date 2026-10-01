<?php

declare(strict_types=1);

namespace Porta\Filesystem;

/**
 * Changes on their way to a server, one at a time and in the order they were
 * made: a file cannot be uploaded into a directory whose creation has not
 * landed, nor renamed before it exists.
 *
 * A change is a closure handed a `$next` to call when the server has
 * answered; the queue moves on then, and only then. A second call of the
 * same `$next` is ignored, so a transport that answers twice cannot run the
 * queue twice.
 */
final class ChangeQueue
{
    /** @var list<array{path: string, run: \Closure(\Closure(): void): void}> */
    private array $waiting = [];
    private bool $running = false;
    /** @var array<string, int> how many queued or running changes touch each path */
    private array $touched = [];

    /** @param \Closure(\Closure(): void): void $run */
    public function add(string $relative, \Closure $run): void
    {
        $this->touched[$relative] = ($this->touched[$relative] ?? 0) + 1;
        $this->waiting[] = ['path' => $relative, 'run' => $run];
        $this->next();
    }

    /** How many changes have not been answered yet. */
    public function pending(): int
    {
        return \count($this->waiting) + ($this->running ? 1 : 0);
    }

    /** Whether a change to $relative is waiting or running — what the server says of it now is about to be stale. */
    public function touches(string $relative): bool
    {
        return \array_key_exists($relative, $this->touched);
    }

    private function next(): void
    {
        $change = $this->running ? null : \array_shift($this->waiting);
        if (null === $change) {
            return;
        }
        $this->running = true;
        $done = false;
        $change['run'](function () use ($change, &$done): void {
            // @mago-expect analysis:impossible-condition -- $done is by reference: a SECOND call sees the first one's true
            if ($done) {
                return;
            }
            $done = true;
            $this->running = false;
            $path = $change['path'];
            $this->touched[$path] = ($this->touched[$path] ?? 1) - 1;
            if (0 === $this->touched[$path]) {
                unset($this->touched[$path]);
            }
            $this->next();
        });
    }
}
