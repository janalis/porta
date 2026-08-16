<?php

declare(strict_types=1);

namespace Porta\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Routes command failures to stderr so a piped stdout stays clean.
 * CommandTester's plain output has no error channel and falls back to the
 * one stream.
 */
trait CommandErrorStream
{
    private static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
