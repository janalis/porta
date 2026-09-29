<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Run\RunController;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The Run menu's tasks, and runs a plugin starts itself, in the terminal
 * panel where the user can watch them.
 *
 * Reached through {@see PluginApi::runs()}. A run lives in the `run:<title>`
 * terminal session the menu uses: it shows as a tab, but starting one neither
 * opens the panel nor takes focus — the user was doing something else. A run
 * of a title already going replaces it, as a re-run from the menu does.
 */
final readonly class Runs
{
    /** Output kept for a reader: the end of it, which is where results and failures are. */
    public const int OUTPUT_LINES = 400;

    public function __construct(
        private RunController $runs,
    ) {}

    /**
     * What the Run menu would offer right now: the active PHP file, the
     * composer scripts, the Makefile targets.
     *
     * @return list<array{label: string, command: list<string>}>
     */
    public function configurations(): array
    {
        return $this->runs->configurations();
    }

    /**
     * Start $command in the `run:<title>` session. `$onExit` gets the exit
     * code — or -1 when the run is replaced or closed before it ends, since a
     * killed process reports nothing.
     *
     * @param list<string>               $command
     * @param (\Closure(int): void)|null $onExit
     */
    public function start(string $title, array $command, ?\Closure $onExit = null): void
    {
        $this->runs->runInBackground($command, $title, $onExit);
    }

    /**
     * What the run of $title printed — plain text, its last
     * {@see OUTPUT_LINES} lines — whether it is still running, and its exit
     * code once it ended by itself. Null when there has been no such run.
     *
     * @return array{running: bool, exitCode: ?int, output: string}|null
     */
    public function output(string $title): ?array
    {
        $session = $this->runs->runSession($title);
        if (null === $session) {
            return null;
        }
        $lines = \array_slice($session->buffer->lines, -self::OUTPUT_LINES);

        return [
            'running' => $session->isRunning(),
            'exitCode' => $session->process?->exitCode(),
            'output' => \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines)),
        ];
    }
}
