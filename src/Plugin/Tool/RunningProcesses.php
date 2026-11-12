<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Container\AsIdeService;
use Porta\Support\AsyncProcess;

/**
 * The programs the installs have running — curl, tar, the new binary asked
 * its version — so the IDE going down stops them, rather than have a
 * download finish into nothing.
 */
#[AsIdeService]
final class RunningProcesses
{
    /** @var array<int, AsyncProcess> by object id */
    private array $running = [];

    public function keep(AsyncProcess $process): void
    {
        $this->running[\spl_object_id($process)] = $process;
    }

    public function forget(AsyncProcess $process): void
    {
        unset($this->running[\spl_object_id($process)]);
    }

    public function busy(): bool
    {
        return [] !== $this->running;
    }

    /** Stop them all: a cancelled process never calls back. */
    public function cancel(): void
    {
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }
}
