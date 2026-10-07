<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintMode;
use Porta\Tests\Support\Probe;
use Porta\Ui\Tooltip;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The three places an inlay hint can be: in the line, on a hover card, or
 * nowhere. Only the first one paints, and only the first one moves the caret
 * — a caret pushed along by text that is not on screen lands in the wrong
 * cell.
 */
final class CodeEditorInlayHintModeTest extends TestCase
{
    private function editor(InlayHintMode $mode): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('status.php', "<?php\nenum StatusTone: string\n{\n}\n");
        $editor->setFocused(true);
        $editor->display->setInlayHints($mode);
        // What phpantom sends after a class name, as the mapper keeps it.
        $editor->decorations->setInlayHints([1 => [new InlayHint(15, '46 references', null, padLeft: true)]]);

        return $editor;
    }

    public function testOnlyInlineModePaintsTheHint(): void
    {
        $row = static fn (CodeEditorWidget $e): string => AnsiUtils::stripAnsiCodes($e->render(new RenderContext(80, 6))[1]);

        $this->assertStringContainsString('StatusTone 46 references: string', $row($this->editor(InlayHintMode::Inline)));
        $this->assertStringContainsString('StatusTone: string', $row($this->editor(InlayHintMode::Tooltip)), 'a card is not painted in the line');
        $this->assertStringNotContainsString('references', $row($this->editor(InlayHintMode::None)));
    }

    public function testOnlyAPaintedHintPushesTheCaretAlong(): void
    {
        // A 20-column pane fits the caret after `$x = foo();` exactly: only a
        // hint painted before it forces a scroll.
        $scrollFor = static function (InlayHintMode $mode): int {
            $editor = new CodeEditorWidget();
            $editor->openFile('a.php', "<?php\n\$x = foo();\n");
            $editor->display->setInlayHints($mode);
            $editor->getDocument()->setCursor(1, 11);
            $editor->decorations->setInlayHints([1 => [new InlayHint(11, ': int')]]);
            $editor->render(new RenderContext(20, 10));

            return Probe::get($editor, 'scroll')->hScroll;
        };

        $this->assertGreaterThan(0, $scrollFor(InlayHintMode::Inline), 'the painted hint widens the line the caret follows');
        $this->assertSame(0, $scrollFor(InlayHintMode::Tooltip), 'an unpainted one does not');
        $this->assertSame(0, $scrollFor(InlayHintMode::None));
    }

    public function testTheCardBelongsToTheWordTheHintAnnotates(): void
    {
        $editor = $this->editor(InlayHintMode::Tooltip);
        $editor->render(new RenderContext(80, 6));
        $gutter = $editor->gutterWidth();

        // `enum StatusTone` — the name spans columns 5..14 of the line.
        $card = $editor->mouse->inlayTooltipAt(1, $gutter + 7, $editor->viewport());
        $this->assertInstanceOf(Tooltip::class, $card, 'resting on the name');
        $this->assertSame('46 references', $card->text, 'the label, without the spacing it keeps in the line');
        $this->assertSame('inlay:1:5', $card->key, 'keyed on the word, so moving along it keeps the one card');
        $this->assertSame([1, $gutter + 7, $gutter + 8], [$card->row, $card->colStart, $card->colEnd]);
        $this->assertSame($card->key, $editor->mouse->inlayTooltipAt(1, $gutter + 14, $editor->viewport())?->key, 'its last character too');

        $this->assertNull($editor->mouse->inlayTooltipAt(1, $gutter + 15, $editor->viewport()), 'not the colon just past it');
        $this->assertNull($editor->mouse->inlayTooltipAt(1, $gutter + 1, $editor->viewport()), 'not `enum`, which has no hint');
        $this->assertNull($editor->mouse->inlayTooltipAt(1, $gutter + 40, $editor->viewport()), 'not past the end of the line');
        $this->assertNull($editor->mouse->inlayTooltipAt(1, 1, $editor->viewport()), 'not the gutter');
        $this->assertNull($editor->mouse->inlayTooltipAt(5, $gutter + 7, $editor->viewport()), 'not below the last line');
    }
}
