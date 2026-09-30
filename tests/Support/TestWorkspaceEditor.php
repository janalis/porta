<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\BufferManager;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\RefactoringUndo;
use Porta\Lsp\WorkspaceEditor;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A {@see WorkspaceEditor} for a hand-built {@see \Porta\Plugin\Runtime\PluginRuntime}:
 * over the buffers given, repainting nothing, with a pool that serves no
 * language — the {@see TestLspQuery} of the edit side, so a runtime that never
 * applies a rename costs one line.
 */
final class TestWorkspaceEditor
{
    public static function create(?BufferManager $buffers = null, string $root = '/p'): WorkspaceEditor
    {
        $buffers ??= new BufferManager();

        return new WorkspaceEditor(
            $buffers,
            new class implements EditorInvalidator {
                public function invalidate(): void {}
            },
            new LspSessionPool($root, new FixedActiveFile(), new Translator('en')),
            new EventDispatcher(),
            new RefactoringUndo($buffers, new EventDispatcher()),
        );
    }
}
