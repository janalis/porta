<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Support\FlashSink;
use Porta\Widget\CodeEditorWidget;

/** A read-only buffer takes the user's keys — and gives back what they changed, saying why. */
final class CodeEditorReadOnlyTest extends TestCase
{
    public function testATypedKeyIsRefusedWithAFlashAndAMoveIsNot(): void
    {
        $flash = new class implements FlashSink {
            /** @var list<string> */
            public array $said = [];

            public function flash(string $message, float $seconds = 2.5): void
            {
                $this->said[] = $message;
            }
        };
        $editor = new CodeEditorWidget(flash: $flash);
        $editor->openFile('a.php', "abc\n");
        $document = $editor->getDocument();
        $document->readOnly = true;

        $editor->handleInput('x');
        $this->assertSame("abc\n", $document->getText());
        $this->assertSame(['This file is read-only'], $flash->said);

        $editor->handleInput("\x1b[C"); // →
        $this->assertSame(1, $document->cursorCol, 'the caret still moves');
        $this->assertCount(1, $flash->said, 'and a move says nothing');

        $document->readOnly = false;
        $editor->handleInput('x');
        $this->assertSame("axbc\n", $document->getText(), 'writable again, the key lands');
    }
}
