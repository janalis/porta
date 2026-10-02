<?php

declare(strict_types=1);

namespace Porta\Filesystem;

use Porta\Support\AsyncProcess;

/**
 * A {@see RemoteTransport} that reaches its server through a command line
 * tool — `ssh`, `curl` — one short process per verb. What every such
 * transport needs and none should write twice: the processes it has in
 * flight, closed with the project, and the words a failure is said in.
 *
 * Never a prompt: the child's stdin is the bytes it is handed or nothing,
 * never the terminal the editor reads keys from, so a tool that would ask for
 * a password fails instead — and says so on stderr, which is kept.
 */
abstract class CommandTransport extends RemoteTransport
{
    /**
     * Long enough for a large file over a slow link. A link that stalls is the
     * tools' own to notice sooner — ssh's keepalives, curl's speed limit.
     */
    public const float TIMEOUT = 600.0;

    private const string NOTHING = '';

    /** @var array<int, AsyncProcess> */
    private array $running = [];
    private int $started = 0;
    /** How many may run at once: a server may take only so many connections from one address. */
    private int $limit = \PHP_INT_MAX;
    /** @var list<array{list<string>, ?string, \Closure(int, string, string): void}> commands waiting for one of those */
    private array $waiting = [];

    /**
     * Run $command, with $stdin as its input, and hand $done its exit code,
     * stdout and stderr. A command that cannot be started at all answers
     * 127, as a shell would.
     *
     * @param list<string>                   $command
     * @param \Closure(int, string, string): void $done
     */
    protected function run(array $command, ?string $stdin, \Closure $done): void
    {
        if (\count($this->running) >= $this->limit) {
            $this->waiting[] = [$command, $stdin, $done];

            return;
        }
        $id = ++$this->started;
        $process = new AsyncProcess($command, \sys_get_temp_dir(), $stdin, captureStderr: true, timeout: self::TIMEOUT);
        $this->running[$id] = $process;
        $started = $process->start(function (int $code, string $stdout) use ($id, $process, $done): void {
            unset($this->running[$id]);
            $this->next();
            $done($code, $stdout, $process->errorOutput());
        });
        if (!$started) {
            unset($this->running[$id]);
            // Its slot is free: the next in line goes, or nothing ever would.
            $this->next();
            $done(127, self::NOTHING, $command[0].': command not found');
        }
    }

    /** At most $limit commands at once; the rest wait their turn, in order. */
    protected function runAtMost(int $limit): void
    {
        $this->limit = \max(1, $limit);
    }

    private function next(): void
    {
        $waiting = \array_shift($this->waiting);
        if (null !== $waiting) {
            $this->run(...$waiting);
        }
    }

    /**
     * What a failed command says, for a person: the last thing it wrote to
     * stderr — where ssh, curl and the shell put the reason — or, when it
     * wrote nothing, how it ended.
     */
    protected static function failure(int $code, string $stderr): string
    {
        $lines = \array_values(\array_filter(\array_map(\trim(...), \explode("\n", $stderr)), static fn (string $line): bool => '' !== $line));

        return match (true) {
            [] !== $lines => $lines[\count($lines) - 1],
            -1 === $code => \sprintf('no answer in %d seconds', (int) self::TIMEOUT),
            default => \sprintf('exit code %d', $code),
        };
    }

    #[\Override]
    public function close(): void
    {
        $this->waiting = [];
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }
}
