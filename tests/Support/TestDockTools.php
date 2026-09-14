<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Dock\DockToolSource;

/**
 * A {@see DockToolController} over a real dock, for the tests that only need
 * one to build a {@see \Porta\Plugin\Runtime\PluginRuntime} — the dock is a
 * ten-collaborator object and none of those tests care which one it is.
 *
 * A test that DOES care (the publish/refresh/staleness paths) builds its own
 * through {@see TestBottomDock} so it can assert on the panel.
 */
final class TestDockTools
{
    /** @param list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> $tools */
    public static function controller(array $tools = [], ?BottomDock $dock = null, string $rootDir = '/p'): DockToolController
    {
        $tui = TestTui::create(100, 24);

        return new DockToolController(
            self::source($tools),
            $dock ?? TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            new ProjectPaths($rootDir),
        );
    }

    /**
     * A {@see DocumentReplacer} for the same tests — they need one to build a
     * PluginRuntime and none of them care which buffers it sees.
     */
    public static function replacer(?BufferManager $buffers = null): DocumentReplacer
    {
        return new DocumentReplacer(
            $buffers ?? new BufferManager(),
            new CodeEditorWidget(),
            new LspSessionPool('/p', new FixedActiveFile(), new Translator('en')),
        );
    }

    /** @param list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> $tools */
    public static function source(array $tools): DockToolSource
    {
        return new readonly class($tools) implements DockToolSource {
            /** @param list<array{plugin: string, id: string, title: string, rerun: (\Closure(): void)|null, activate: (\Closure(string, int): void)|null}> $tools */
            public function __construct(
                private array $tools,
            ) {}

            public function listTools(): array
            {
                return $this->tools;
            }
        };
    }
}
