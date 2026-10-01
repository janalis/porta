<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Debug\BreakpointStore;
use Porta\Debug\DebugAdapters;
use Porta\Debug\DebugController;
use Porta\Debug\DebugSessionState;
use Porta\Debug\EditorDecorations;
use Porta\Debug\ExceptionFilterStore;
use Porta\Debug\InlineValues;
use Porta\Debug\ProcessDapClientFactory;
use Porta\Debug\WatchStore;
use Porta\I18n\Translator;
use Porta\Lsp\InlineValueRequest;
use Porta\Lsp\LspSessionPool;
use Porta\Support\ProjectPaths;
use Porta\Terminal\TerminalSessions;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A {@see DebugController} for a hand-built {@see \Porta\Plugin\Runtime\PluginRuntime}:
 * no adapter configured, so no session ever starts — the debugger a plugin
 * test sees is an idle one.
 */
final class TestDebugController
{
    public static function create(string $root = '/p'): DebugController
    {
        $tui = TestTui::create(100, 24);
        $editor = new CodeEditorWidget();
        $dispatcher = new EventDispatcher();
        $panel = new DebugPanelWidget(events: $dispatcher);
        $terminalPanel = new TerminalPanelWidget();
        $sessions = new TerminalSessions();
        $breakpoints = new BreakpointStore();
        $state = new DebugSessionState();

        return new DebugController(
            $breakpoints,
            new DebugAdapters([]),
            $editor,
            $panel,
            $terminalPanel,
            new Notifier(new StatusBarWidget(), $tui),
            $tui,
            new Translator('en'),
            new ProjectPaths($root),
            new RecordingFileOpener(),
            new ProcessDapClientFactory($root),
            $dispatcher,
            $state,
            new EditorDecorations($breakpoints, $state, $editor->decorations),
            new WatchStore(),
            new ExceptionFilterStore(),
            $sessions,
            TestBottomDock::create($tui, $terminalPanel, new ResultsPanelWidget(), $panel, sessions: $sessions),
            new InlineValues(new InlineValueRequest(new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator())), $editor->decorations, $tui, $editor),
        );
    }
}
