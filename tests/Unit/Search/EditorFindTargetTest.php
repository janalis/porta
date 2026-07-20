<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\EditorFindTarget;
use Porta\Widget\CodeEditorWidget;

final class EditorFindTargetTest extends TestCase
{
    public function testBridgesTheFindBarToTheEditorDocument(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "alpha\nbeta\n");
        $doc = $editor->getDocument();
        $target = new EditorFindTarget($doc, $editor);

        $lines = $target->findLines();
        $this->assertContains('alpha', $lines);
        $this->assertContains('beta', $lines);
        $this->assertSame($doc->getVersion(), $target->findVersion());
        $this->assertSame($doc->getCursorLine(), $target->findCaretLine());
        $this->assertSame($doc->getCursorCol(), $target->findCaretCol());

        // Revealing a match moves the document caret (the editor scrolls it in).
        $target->revealMatch(1, startByte: 0, endByte: 4);
        $this->assertSame(1, $doc->getCursorLine());
        $this->assertSame(0, $doc->getCursorCol());

        // Mirroring is a no-throw pass-through to the editor's highlight.
        $target->showSearchMatches([1 => [['start' => 0, 'end' => 4, 'current' => true]]]);

        // The editor is editable, so replace targets its document.
        $this->assertSame($doc, $target->replaceDocument());
    }
}
