<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\DockTabClosed;
use Porta\Event\DockTabMoved;
use Porta\Event\DockTabSelected;
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
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
        ?EventDispatcherInterface $events = null,
    ): BottomDock {
        $dock = new BottomDock(
            $tui,
            $terminal,
            $results,
            $debug,
            $dockTabs ?? new DockTabsWidget(events: $events),
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
            $events,
        );
        $dock->wire();
        if ($events instanceof EventDispatcher) {
            // What the container compiles from #[AsEventListener]; a test rig
            // wires the same three by hand.
            $events->addListener(DockTabSelected::class, $dock->onTabSelected(...));
            $events->addListener(DockTabClosed::class, $dock->onTabClosed(...));
            $events->addListener(DockTabMoved::class, $dock->onTabMoved(...));
        }

        return $dock;
    }
}
