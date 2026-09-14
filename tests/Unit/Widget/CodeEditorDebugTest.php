<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class CodeEditorDebugTest extends TestCase
{
    private function editor(): CodeEditorWidget
    {
        $editor = new CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n"));

        return $editor;
    }

    /** @return list<string> */
    private function render(CodeEditorWidget $editor): array
    {
        return $editor->render(new RenderContext(40, 6));
    }

    public function testBreakpointDotRendersInTheGutterMarkerColumn(): void
    {
        $editor = $this->editor();
        $plain = \implode("\n", $this->render($editor));
        $this->assertStringNotContainsString('●', $plain);

        $editor->decorations()->setBreakpointLines([1]);
        $lines = $this->render($editor);
        $this->assertStringContainsString('●', $lines[1], 'the dot sits on the breakpoint line');
        $this->assertStringNotContainsString('●', $lines[2]);
        $this->assertStringContainsString("\x1b[38;5;203m●", $lines[1], 'Breakpoint role color (dark palette)');

        $editor->decorations()->setBreakpointLines([]);
        $this->assertStringNotContainsString('●', \implode("\n", $this->render($editor)));
    }

    public function testDebugLineBandOutranksTheCurrentLineBackground(): void
    {
        $editor = $this->editor();
        $editor->setFocused(true);
        $editor->getDocument()->setCursor(2, 0);

        $editor->decorations()->setDebugLine(2);
        $lines = $this->render($editor);
        $this->assertStringContainsString("\x1b[48;5;52m", $lines[2], 'DebugLineBg painted on the stopped cursor line');
        $this->assertStringNotContainsString("\x1b[48;5;236m", $lines[2], 'RowHighlightBg suppressed there');

        $editor->decorations()->setDebugLine(1);
        $lines = $this->render($editor);
        $this->assertStringContainsString("\x1b[48;5;52m", $lines[1], 'band follows the stopped line');
        $this->assertStringContainsString("\x1b[48;5;236m", $lines[2], 'cursor line highlight returns');

        $editor->decorations()->setDebugLine(null);
        $this->assertStringNotContainsString("\x1b[48;5;52m", \implode("\n", $this->render($editor)));
    }

    public function testStoppedLineArrowOutranksBreakpointDotAndDiagnosticGlyph(): void
    {
        $editor = $this->editor();
        $editor->decorations()->setBreakpointLines([1]);
        $editor->decorations()->setDiagnostics([1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0]]]);

        $editor->decorations()->setDebugLine(1);
        $lines = $this->render($editor);
        $gutter = AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($lines[1], 0, $editor->gutterWidth()));
        $this->assertStringContainsString("\x1b[38;5;203m▶", $lines[1], 'the stopped-line arrow wins the marker column');
        $this->assertStringNotContainsString('●', $gutter, 'breakpoint dot yields to the arrow');
        $this->assertStringNotContainsString('✗', $gutter, 'diagnostic glyph yields to the arrow');

        $editor->decorations()->setDebugLine(null);
        $lines = $this->render($editor);
        $gutter = AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($lines[1], 0, $editor->gutterWidth()));
        $this->assertStringContainsString('●', $gutter, 'breakpoint dot returns once the debugger moves on');
        $this->assertStringNotContainsString('✗', $gutter, 'diagnostic glyph yields to the dot');

        $editor->decorations()->setBreakpointLines([]);
        $gutter = AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($this->render($editor)[1], 0, $editor->gutterWidth()));
        $this->assertStringContainsString('✗', $gutter, 'diagnostic glyph shows once dot and arrow are gone');
    }

    public function testDocumentLineAtResolvesStickyHeadersAndScrolledRows(): void
    {
        $editor = new CodeEditorWidget();
        $code = "<?php\n\nnamespace App;\n\nclass Service\n{\n    public function handle(): void\n    {\n".\str_repeat("        \$x = 1;\n", times: 20)."    }\n}\n";
        $editor->showDocument('/p/Service.php', new Document($code));
        $editor->getDocument()->setCursor(24, 8); // deep inside handle(): class + method pin
        $editor->render(new RenderContext(60, 12));

        $this->assertSame(4, $editor->documentLineAt(0), 'row 0 is the pinned class header');
        $this->assertSame(6, $editor->documentLineAt(1), 'row 1 is the pinned method header');
        $this->assertGreaterThan(6, $editor->documentLineAt(2), 'rows below the band are scrolled content');
        $this->assertGreaterThan(0, $editor->gutterWidth());
    }

    public function testShowDocumentClearsDebugDecorations(): void
    {
        $editor = $this->editor();
        $editor->decorations()->setBreakpointLines([1]);
        $editor->decorations()->setDebugLine(1);

        $editor->showDocument('/p/b.php', new Document("<?php\n// other\n"));
        $screen = \implode("\n", $this->render($editor));
        $this->assertStringNotContainsString('●', $screen);
        $this->assertStringNotContainsString("\x1b[48;5;52m", $screen);
        $this->assertSame(4, AnsiUtils::visibleWidth('●●●●'), 'the dot is single-width (sanity)');
    }
}
