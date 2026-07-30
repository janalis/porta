<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolTabs;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/** Builds a wired {@see BottomDock} over the given panels for unit tests. */
final class TestBottomDock
{
    public static function create(
        Tui $tui,
        TerminalPanelWidget $terminal,
        ResultsPanelWidget $results,
        DebugPanelWidget $debug,
        ?EditorArea $editorArea = null,
        ?DockTabsWidget $dockTabs = null,
        ?TerminalSessions $sessions = null,
        ?DockToolTabs $tools = null,
    ): BottomDock {
        $dock = new BottomDock(
            $tui,
            $terminal,
            $results,
            $debug,
            $dockTabs ?? new DockTabsWidget(),
            $tools ?? new DockToolTabs(),
            $sessions ?? new TerminalSessions(),
            new Translator('en'),
            new Notifier(new StatusBarWidget(), $tui),
            $editorArea ?? new EditorArea(
                new BufferManager(),
                new CodeEditorWidget(),
                new EditorPanes(),
                $tui,
            ),
        );
        $dock->wire();

        return $dock;
    }
}
