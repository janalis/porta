<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\EditorFindTarget;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class EditorFindTargetTest extends TestCase
{
    public function testBridgesTheFindBarToTheEditorDocument(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "alpha\nbeta\n");
        $doc = $editor->getDocument();
        $target = new EditorFindTarget($doc, $editor->decorations);

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

        // Mirroring reaches the editor's actual highlight, not just a no-throw call.
        $target->showSearchMatches([1 => [['start' => 0, 'end' => 4, 'current' => true]]]);
        $this->assertStringContainsString(
            "\x1b[48;5;130m",
            $editor->render(new RenderContext(80, 10))[1],
            'the current match background reaches the editor',
        );

        // The editor is editable, so replace targets its document.
        $this->assertSame($doc, $target->replaceDocument());
    }
}
