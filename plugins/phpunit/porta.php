<?php

declare(strict_types=1);

use Porta\PhpUnit\CoverageView;
use Porta\PhpUnit\TestRunner;
use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\ComposerLayout;
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
        $coverage = new CoverageView($api);
        $runner = new TestRunner(
            $api,
            function () use ($api): ?string {
                $binary = $this->acquire($api);
                // A Pest project runs its tests with Pest — its own vendor/bin/phpunit
                // cannot read them — unless a binary was named.
                $pest = ComposerLayout::of($api->projectRoot())->binDir.'/pest';

                return null !== $binary && '' === $api->setting('binary') && \is_file($pest) ? $pest : $binary;
            },
            $this->release(...),
            $coverage,
        );

        $api->registerListTool(TestRunner::TOOL, title: 'Tests', rerun: $runner->rerun(...));
        $api->registerCommand('run_at_caret', label: 'PHPUnit: Run Test at Caret', handler: $runner->runAtCaret(...), keys: ['ctrl+shift+f10']);
        $api->registerCommand('run_file', label: 'PHPUnit: Run Tests in File', handler: $runner->runFile(...));
        $api->registerCommand('run_all', label: 'PHPUnit: Run All Tests', handler: $runner->runAll(...));
        $api->registerCommand('rerun_failed', label: 'PHPUnit: Rerun Failed Tests', handler: $runner->rerunFailed(...));
        $api->registerCommand('run_with_coverage', label: 'PHPUnit: Run All Tests with Coverage', handler: $runner->runWithCoverage(...));
        $api->registerCommand('hide_coverage', label: 'PHPUnit: Hide Coverage', handler: $coverage->hide(...));

        $available = $this->binaryAvailable($api);
        foreach (['run_at_caret', 'run_file', 'run_all', 'rerun_failed', 'run_with_coverage'] as $command) {
            $api->addMenuItem($command, $available);
        }
        $api->addMenuItem('hide_coverage', $coverage->shown(...));
        $api->addMenuItem('run_at_caret', $available, MenuSurface::EditorContext);
        $api->addMenuItem('run_file', $available, MenuSurface::FileContext);

        $api->registerStatusSegment($runner->status(...));
        $api->onBufferSwitched(static fn (string $path) => $runner->annotate());
        $api->onFileOpened(static function (string $path) use ($runner, $coverage): void {
            $runner->opened($path);
            $coverage->opened($path);
        });
        $api->onFileSaved($coverage->forget(...));
        $api->onFileReloaded($coverage->forget(...));
        $api->onFileRewritten($coverage->forget(...)); // the text it replaced is not needed
    }
};
