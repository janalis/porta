<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Ui\BottomDock;
use Porta\Ui\EditorArea;
use Porta\Ui\Notifier;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
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
    ): BottomDock {
        $dock = new BottomDock(
            $tui,
            $terminal,
            $results,
            $debug,
            new Notifier(new StatusBarWidget(), $tui),
            $editorArea ?? new EditorArea(new BufferManager(), new CodeEditorWidget(), new ImageViewerWidget(), new HelpViewerWidget(), $tui),
        );
        $dock->wire();

        return $dock;
    }
}
