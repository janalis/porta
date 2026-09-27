<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A {@see CompletionController} over a pool with no server configured, for
 * tests that must construct something that holds one (the live settings
 * applier) but never exercise completion: every request it would make finds
 * no capability and asks nothing.
 */
final class TestCompletionController
{
    public static function idle(CodeEditorWidget $editor = new CodeEditorWidget()): CompletionController
    {
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $translator = new Translator('en');
        $pool = new LspSessionPool('/p', new FixedActiveFile(), $translator);

        return new CompletionController($pool, $editor, $tui, new Notifier(new StatusBarWidget(), $tui), $translator);
    }
}
