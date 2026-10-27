<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\BufferSaver;
use Porta\I18n\Translator;
use Porta\Plugin\Runtime\PluginRuntime;
use Porta\Support\Clipboard;
use Porta\Tree\FileStatuses;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Dock\DockToolController;
use Porta\Workbench\Menu\MenuFileContext;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/**
 * A plugin's live handle into an IDE that is not running: the action half of
 * {@see \Porta\Plugin\Api\PluginApi} — prompts, toasts, the editor — on real
 * widgets a test can read back, with doubles for the rest. For a plugin test
 * that needs `prompt()` or `flash()` to land somewhere.
 *
 * The editor is the prompt's host (its overlay is what `EditorState` reads),
 * and the status bar is where a toast lands without a toast center.
 */
final class TestPluginRuntime
{
    /**
     * @param ?DockToolController     $dock        a real dock for the plugin's tools to land in, when the test reads them
     * @param ?RecordingChangeMarkSink $changeMarks the gutter marks' sink, when the test reads them
     */
    public static function create(
        CodeEditorWidget $editor,
        StatusBarWidget $status,
        ?Tui $tui = null,
        ?DockToolController $dock = null,
        ?RecordingChangeMarkSink $changeMarks = null,
    ): PluginRuntime {
        $tui ??= TestTui::create(120, 24);
        $buffers = new BufferManager();

        return new PluginRuntime(
            new Notifier($status, $tui),
            new Translator('en'),
            $editor,
            new RecordingFileOpener(),
            $tui,
            new RecordingDiagnosticsSink(),
            $editor->decorations,
            $changeMarks ?? new RecordingChangeMarkSink(),
            new FileStatuses(),
            $buffers,
            $dock ?? TestDockTools::controller(),
            TestDockTools::replacer($buffers),
            $editor,
            new MenuFileContext(),
            new ViewRegistry(),
            new Clipboard($tui),
            new StatusBarWidget(),
            $editor,
            new FakePluginLifecycle(),
            new FileTreeWidget(\sys_get_temp_dir()),
            TestLspQuery::create(),
            new BufferSaver($buffers, new EventDispatcher()),
            TestRuns::create(),
            TestWorkspaceEditor::create(),
            TestParticipants::create(),
            TestProjectSwitcher::create(),
        );
    }
}
