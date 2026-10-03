<?php

declare(strict_types=1);

namespace Porta\Support;

use Porta\Container\AsIdeService;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * The servers this IDE started never outlive it, however it ends.
 *
 * A language server or debug adapter is a child process, and a child
 * outlives its parent unless something ends it: the IDE's own teardown does
 * on a quit, but a terminal closed under it, a crash or a kill skips that
 * teardown — and a quit's own polite stop leans on loop timers that never
 * fire once the loop has stopped. Left alone, such a server keeps running,
 * some of them spinning on the closed pipe at most of a core.
 *
 * So one watcher per IDE, a `/bin/sh` loop holding the pid of every child
 * {@see watch()}ed and not yet {@see forget()}ten — forgotten once reaped, so
 * a reused pid is never mistaken for one. It reads them from a pipe only the
 * IDE writes; when the IDE ends, however it ends, the kernel closes that pipe,
 * and the watcher gives the children still listed a second to finish the
 * exit they may have been asked for, then terminates them, then kills them.
 * It ignores the hang-up, interrupt and terminate signals a closed terminal
 * sends the whole process group, so it is still there to do it.
 */
#[AsIdeService]
final class OrphanGuard
{
    /**
     * The watcher: collect pids — and the IDE's process group, when it leads
     * one — until the pipe closes; then end whichever are still alive, the
     * group last of all, its own member included, since that is all that is
     * left to do.
     */
    private const string SCRIPT = <<<'SH'
        trap '' HUP INT TERM
        pids=''
        group=''
        while IFS= read -r line; do
          case $line in
            +*) pids="$pids ${line#+}" ;;
            -*) gone=${line#-}; kept=''; for pid in $pids; do [ "$pid" = "$gone" ] || kept="$kept $pid"; done; pids=$kept ;;
            g*) group=${line#g} ;;
          esac
        done
        alive() { left=''; for pid in $pids; do kill -0 "$pid" 2>/dev/null && left="$left $pid"; done; pids=$left; [ -n "$pids" ]; }
        settle() { i=0; while [ "$i" -lt "$1" ] && alive; do sleep 0.1; i=$((i + 1)); done; }
        settle 10
        [ -n "$group" ] && kill -TERM -- "-$group" 2>/dev/null
        alive && kill -TERM $pids 2>/dev/null
        settle 20
        alive && kill -KILL $pids 2>/dev/null
        [ -n "$group" ] && kill -KILL -- "-$group" 2>/dev/null
        exit 0
        SH;

    /** @var resource|null the watcher's stdin; null until the first watch, and again once a write failed */
    private $pipe;

    /** @var resource|null the watcher, held: freeing the handle would close the pipe, and the watcher would end everything at once */
    private $watcher;

    /** Guard the child $pid until it is forgotten. */
    public function watch(int $pid): void
    {
        $this->send('+'.$pid);
    }

    /** The child $pid was reaped: its pid is no longer its. */
    public function forget(int $pid): void
    {
        if (null !== $this->pipe) {
            $this->send('-'.$pid);
        }
    }

    /**
     * Guard every process this IDE starts, not only the ones watched — a run
     * configuration, a terminal's shell, git, a plugin's server — by guarding
     * the IDE's process group: they all join it unless they leave on purpose.
     * Only when the IDE leads that group, as a shell's job does; one sharing
     * its launcher's group would take the launcher with it. And only on a
     * real $terminal: an IDE on a virtual one is a test's, inside a test
     * runner's group.
     */
    public function guardGroup(TerminalInterface $terminal): void
    {
        $pid = \getmypid();
        if (!$terminal->isVirtual() && false !== $pid && \posix_getpgrp() === $pid) {
            $this->send('g'.$pid);
        }
    }

    private function send(string $line): void
    {
        $pipe = \is_resource($this->watcher) ? $this->pipe : null;
        $pipe ??= $this->start();
        // @mago-expect lint:no-error-control-operator -- a watcher that died leaves a broken pipe: the write fails with a warning, and the false return drops the guard below.
        if (null !== $pipe && false === @\fwrite($pipe, $line."\n")) {
            $this->pipe = null;
        }
    }

    /** @return resource|null the new watcher's stdin; null when there is none to start, and the next send tries again */
    private function start()
    {
        $pipes = [];
        // @mago-expect lint:no-error-control-operator -- proc_open on an unusable /bin/sh emits a spawn warning that guarding the inputs cannot prevent; the false return is handled below.
        $watcher = @\proc_open(['/bin/sh', '-c', self::SCRIPT], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($watcher)) {
            return null;
        }
        $this->watcher = $watcher;
        /** @var array{0: resource} $pipes */
        $this->pipe = $pipes[0];

        return $this->pipe;
    }
}
