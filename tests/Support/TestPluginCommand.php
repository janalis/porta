<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Plugin\Api\PluginCommand;
use Symfony\Component\Console\Output\OutputInterface;

/** A plugin console command that only exposes what its base gives it: the project root and the error stream. */
final class TestPluginCommand extends PluginCommand
{
    public function root(): string
    {
        return $this->projectRoot();
    }

    public static function errorsOf(OutputInterface $output): OutputInterface
    {
        return self::errors($output);
    }
}
