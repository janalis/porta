<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Editor\SourceEdits;
use Porta\Event\SourceEditRequested;
use Porta\I18n\Translator;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * A preview's edit of its text: applied to the open buffer as one undoable
 * step with the caret left where the user had it — and refused when the text
 * under it is no longer what the preview drew.
 */
final class SourceEditsTest extends TestCase
{
    private BufferManager $buffers;
    private SourceEdits $edits;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->buffers = new BufferManager();
        $this->tui = TestTui::create();
        $this->edits = new SourceEdits(
            $this->buffers,
            new DocumentReplacer($this->buffers, new CodeEditorWidget(), new LspSessionPool(\sys_get_temp_dir(), new FixedActiveFile(), new Translator('en'))),
            $this->tui,
        );
    }

    public function testATickReplacesItsBoxAsOneUndoableEditAndLeavesTheCaret(): void
    {
        $buffer = $this->buffers->open('/p/todo.md', "# List\n- [ ] one\n- [ ] two\n");
        $buffer->document->setCursor(2, 8); // after the box, on "two"
        Probe::set($this->tui, 'renderRequested', false);
        $this->edits->onSourceEditRequested(new SourceEditRequested('/p/todo.md', 19, 22, '[ ]', '[x]'));
        $this->assertSame("# List\n- [ ] one\n- [x] two\n", $buffer->document->getText());
        $this->assertTrue((bool) Probe::get($this->tui, 'renderRequested'), 'the edit is drawn without waiting for a key');
        $this->assertSame([2, 8], [$buffer->document->cursorLine, $buffer->document->cursorCol]);
        $buffer->document->undo();
        $this->assertSame("# List\n- [ ] one\n- [ ] two\n", $buffer->document->getText());
    }

    public function testAnEditOfTheFirstLineMapsItsOffsetsThere(): void
    {
        $buffer = $this->buffers->open('/p/one.md', '- [ ] only');
        $this->edits->onSourceEditRequested(new SourceEditRequested('/p/one.md', 2, 5, '[ ]', '[x]'));
        $this->edits->onSourceEditRequested(new SourceEditRequested('/p/one.md', 0, 1, '-', '*'));
        $this->edits->onSourceEditRequested(new SourceEditRequested('/p/one.md', 10, 10, '', '!'));
        $this->assertSame('* [x] only!', $buffer->document->getText(), 'the first byte, and the very end');
    }

    public function testAnEditTheTextHasMovedOnFromChangesNothing(): void
    {
        $buffer = $this->buffers->open('/p/todo.md', "- [x] one\n");
        foreach ([
            // Each fails one check alone, and would show had it been applied.
            new SourceEditRequested('/p/todo.md', 2, 5, '[ ]', '[-]'),
            new SourceEditRequested('/p/todo.md', 5, 2, ' o', '[x]'), // substr(…, 5, -3) reads ' o'
            new SourceEditRequested('/p/todo.md', -4, 0, "one\n", 'x'), // substr(…, -4, 4) reads "one\n"
            new SourceEditRequested('/p/gone.md', 2, 5, '[x]', '[ ]'),
        ] as $stale) {
            $this->edits->onSourceEditRequested($stale);
        }
        $this->assertSame("- [x] one\n", $buffer->document->getText());
        $buffer->document->readOnly = true;
        $this->edits->onSourceEditRequested(new SourceEditRequested('/p/todo.md', 2, 5, '[x]', '[ ]'));
        $this->assertSame("- [x] one\n", $buffer->document->getText(), 'a read-only buffer is not edited');
    }
}
