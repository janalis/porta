<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Run\RunConfigs;
use Porta\Run\RunController;
use Porta\Terminal\TerminalKeyEncoder;
use Porta\Terminal\TerminalSessions;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * A {@see RunController} for a hand-built {@see \Porta\Plugin\Runtime\PluginRuntime}
 * or a runs test: real sessions and a real panel over $root, nothing shared
 * with an IDE.
 */
final class TestRunController
{
    public static function create(string $root = '/p', ?Tui $tui = null, ?TerminalSessions $sessions = null, ?TerminalPanelWidget $panel = null, ?CodeEditorWidget $editor = null): RunController
    {
        $tui ??= TestTui::create(100, 24);

        return new RunController(
            new RunConfigs($root),
            $panel ?? new TerminalPanelWidget(),
            new TerminalKeyEncoder($tui),
            $editor ?? new CodeEditorWidget(),
            new Notifier(new StatusBarWidget(), $tui),
            $tui,
            new Translator('en'),
            $root,
            $sessions ?? new TerminalSessions(),
        );
    }
}
