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

    /**
     * Every terminal tab — a shell, a run, the debugger's output — by its id
     * (`shell:N`, `run:<title>`, …), its title and whether a process is
     * running in it.
     *
     * @return list<array{id: string, title: string, running: bool}>
     */
    public function terminals(): array
    {
        return $this->runs->terminals();
    }

    /**
     * What a terminal shows, as it would be printed again — colours kept,
     * lines ended with CR LF — or null when there is no such tab.
     */
    public function scrollback(string $id): ?string
    {
        return $this->runs->scrollback($id);
    }

    /**
     * Read a terminal's output as its process prints it — raw bytes, escape
     * sequences and all — until called again with null. False when there is
     * no such tab.
     *
     * @param (\Closure(string): void)|null $onOutput
     */
    public function watch(string $id, ?\Closure $onOutput): bool
    {
        return $this->runs->watch($id, $onOutput);
    }

    /** Type $bytes into a terminal's process. False when there is no such tab, or no process running in it. */
    public function write(string $id, string $bytes): bool
    {
        return $this->runs->write($id, $bytes);
    }

    /**
     * A terminal tab whose process runs elsewhere — someone else's, say:
     * made once, retitled after, behind the tab in front. It shows what
     * {@see feed()} gives it, and the keys typed into it go to `$onInput`
     * (none, with null).
     *
     * @param (\Closure(string): void)|null $onInput
     */
    public function mirror(string $id, string $title, ?\Closure $onInput): void
    {
        $this->runs->mirror($id, $title, $onInput);
    }

    /** Output for a mirrored terminal, shown as though its process printed it. */
    public function feed(string $id, string $bytes): void
    {
        $this->runs->feed($id, $bytes);
    }
}
