<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The base every plugin console command extends — what `porta <name> …` runs
 * when a bundled plugin's manifest names it in `extra.porta.commands`.
 * {@see \Porta\Console\PluginCommands} refuses any other class, so a manifest
 * can only point `porta` at code written for it.
 *
 * It carries what such a command needs and cannot get from core: the project
 * it was run in — no IDE is open to say — and the error stream, so a failure
 * never lands in a stdout a script is reading.
 */
abstract class PluginCommand extends Command
{
    /** @param string|null $root the project; null finds it from the working directory */
    public function __construct(
        private readonly ?string $root = null,
    ) {
        parent::__construct();
    }

    /**
     * The project the command acts on: the nearest directory up from the
     * working directory that holds `.porta` or `.git`, as though the IDE had
     * been opened there — else the working directory itself.
     */
    protected function projectRoot(): string
    {
        if (null !== $this->root) {
            return $this->root;
        }
        $cwd = (string) \getcwd();
        for ($dir = $cwd; \dirname($dir) !== $dir; $dir = \dirname($dir)) {
            if (\file_exists($dir.'/.porta') || \file_exists($dir.'/.git')) {
                return $dir;
            }
        }

        return $cwd;
    }

    /** Where a failure is said: stderr when there is one, so a piped stdout stays clean. */
    protected static function errors(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
