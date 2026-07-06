<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Lsp\LspSession;
use Porta\Lsp\WorkspaceEditor;
use Porta\Widget\CodeEditorWidget;

final class WorkspaceEditorTest extends TestCase
{
    private function workspaceEditor(): WorkspaceEditor
    {
        return new WorkspaceEditor(new BufferManager(), new CodeEditorWidget(), new LspSession(sys_get_temp_dir()));
    }

    public function testMultiEditRenameIsASingleUndoStep(): void
    {
        $doc = new Document("foo();\nfoo();\nfoo();\n");
        // Rename every `foo` → `bar` (three separate text edits).
        $edits = [];
        for ($line = 0; $line < 3; ++$line) {
            $edits[] = [
                'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 3]],
                'newText' => 'bar',
            ];
        }

        $this->workspaceEditor()->applyTextEdits($doc, $edits);
        $this->assertSame("bar();\nbar();\nbar();\n", $doc->getText());

        $this->assertTrue($doc->undo());
        $this->assertSame("foo();\nfoo();\nfoo();\n", $doc->getText(), 'one undo reverts the whole rename');
        $this->assertFalse($doc->undo(), 'the rename was a single undo step');
    }
}
