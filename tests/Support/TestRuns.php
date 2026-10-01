<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Debug\DebugController;
use Porta\Plugin\Api\Runs;
use Porta\Run\RunController;

/**
 * The {@see Runs} a plugin is handed, for a hand-built
 * {@see \Porta\Plugin\Runtime\PluginRuntime} or a runs test: over $runs when
 * the test drives one, an idle debugger when it does not name one.
 */
final class TestRuns
{
    public static function create(?RunController $runs = null, ?DebugController $debug = null): Runs
    {
        return new Runs($runs ?? TestRunController::create(), $debug ?? TestDebugController::create());
    }
}
