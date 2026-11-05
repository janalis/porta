<?php

declare(strict_types=1);

use Porta\PhpUnit\TestRunner;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\Menu\MenuSurface;

/**
 * PHPUnit from the editor: the test at the caret, the file, the suite, or the
 * ones that failed last time — in a terminal tab of their own, with the
 * results in the dock, failures as diagnostics and a mark after each test.
 *
 * Off by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}),
 * like every plugin that runs a tool of the project's.
 */
return new class('phpunit', 'phpunit') extends AbstractToolPlugin {
    #[\Override]
    protected function runsProjectCode(): bool
    {
        return true; // a test suite is the project's own code
    }

    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api);
        $runner = new TestRunner($api, fn (): ?string => $this->acquire($api), $this->release(...));

        $api->registerListTool(TestRunner::TOOL, 'Tests', rerun: $runner->rerun(...));
        $api->registerCommand('run_at_caret', 'PHPUnit: Run Test at Caret', $runner->runAtCaret(...), ['ctrl+shift+f10']);
        $api->registerCommand('run_file', 'PHPUnit: Run Tests in File', $runner->runFile(...));
        $api->registerCommand('run_all', 'PHPUnit: Run All Tests', $runner->runAll(...));
        $api->registerCommand('rerun_failed', 'PHPUnit: Rerun Failed Tests', $runner->rerunFailed(...));

        $available = $this->binaryAvailable($api);
        foreach (['run_at_caret', 'run_file', 'run_all', 'rerun_failed'] as $command) {
            $api->addMenuItem($command, $available);
        }
        $api->addMenuItem('run_at_caret', $available, MenuSurface::EditorContext);
        $api->addMenuItem('run_file', $available, MenuSurface::FileContext);

        $api->registerStatusSegment($runner->status(...));
        $api->onBufferSwitched(static fn (string $path) => $runner->annotate());
        $api->onFileOpened($runner->opened(...));
    }
};
