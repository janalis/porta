<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\ColumnModeSegment;
use Porta\Workbench\StatusTone;

/**
 * A mode whose state you cannot see is a trap: you press an arrow, get a
 * rectangle you did not expect, and have nothing to look at that explains it.
 */
final class ColumnModeSegmentTest extends TestCase
{
    private function editor(): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("abcd\nefgh\n"));

        return $editor;
    }

    public function testItSaysNothingWhileTheModeIsOff(): void
    {
        $this->assertSame([], new ColumnModeSegment($this->editor(), new Translator('en'))->statusCells());
    }

    public function testItSaysCOLUMNWhileTheModeIsOn(): void
    {
        $editor = $this->editor();
        // The menu's entry point, which is now also the toggle's own.
        $editor->runAction('toggle_column_mode');

        $cells = new ColumnModeSegment($editor, new Translator('en'))->statusCells();

        $this->assertCount(1, $cells);
        $this->assertSame('COLUMN', $cells[0]->text);
        $this->assertSame(StatusTone::Accent, $cells[0]->tone, 'it is a mode, not a whisper');
        $this->assertSame('Column selection mode: Shift+arrows select a rectangle — Alt+C turns it off', $cells[0]->tooltip, 'a mode says how it works and how to leave it');
    }
}
