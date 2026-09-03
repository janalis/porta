<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Editor\LineNumberMode;
use Porta\Editor\WrapLayout;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorRowAssembler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The row walk with a {@see WrapLayout} in hand: one document line occupies as
 * many rows as it needs, and the segment offset is what
 * {@see \Porta\Widget\Render\EditorRenderer::renderContentLine()} slices at —
 * the same argument horizontal scroll passes.
 */
final class EditorRowAssemblerWrapTest extends TestCase
{
    private const int WIDTH = 10;

    /** @return list<string> plain content columns, gutter stripped */
    /** @param bool|null $wrap null renders unwrapped; true builds a layout — the assembler's own shape */
    private function rows(string $text, ?WrapLayout $wrap, int $contentRows = 6): array
    {
        $document = new Document($text);
        $store = new DecorationStore(static function (): void {});
        $geometry = new EditorGeometry(numberWidth: 0, gutterWidth: 0, contentWidth: self::WIDTH, stripeCol: 0);

        $assembler = new EditorRowAssembler(
            $document,
            new FoldModel($document),
            $store,
            LineNumberMode::Off,
            false,
            false,
            false,
            false,
            false,
            null,
            new \Porta\Ui\CursorStyle(),
            $wrap,
        );

        $rendered = $assembler->assemble(
            $geometry,
            new EditorDecorations(false, null, [], [], null),
            [],
            // The assembler renders the HIGHLIGHTED line, so the fixture has to
            // supply one per line; plain text is a valid highlighting.
            array_map($document->getLine(...), range(0, $document->getLineCount() - 1)),
            array_fill(0, $contentRows, value: ''),
            $contentRows,
            0,
            0,
            0,
            0,
        );

        return array_map(static fn (string $r): string => trim(AnsiUtils::stripAnsiCodes($r)), $rendered);
    }

    public function testALongLineTakesAsManyRowsAsItNeedsAndTheNextLineFollows(): void
    {
        // 25 columns at a width of 10 → three rows, then 'tail' on the fourth.
        $text = str_repeat('a', times: 10).str_repeat('b', times: 10).'ccccc'."\ntail\n";

        $this->assertSame(
            ['aaaaaaaaaa', 'bbbbbbbbbb', 'ccccc', 'tail', '', '~'],
            $this->rows($text, wrap: new WrapLayout(new Document($text))),
            'each segment is the next slice, and the walk moves on when the line runs out',
        );
    }

    /** Without a layout the same document renders one row per line, clipped as before. */
    public function testWithoutWrapTheLineIsClippedToOneRow(): void
    {
        $text = str_repeat('a', times: 10).str_repeat('b', times: 10).'ccccc'."\ntail\n";

        $this->assertSame(
            ['aaaaaaaaaa', 'tail', '', '~', '~', '~'],
            $this->rows($text, wrap: null),
            'one row per line, the overflow simply not shown',
        );
    }

    /** A line that exactly fills the width does not spill into an empty second row. */
    public function testALineExactlyTheWidthDoesNotSpill(): void
    {
        $this->assertSame(
            ['aaaaaaaaaa', 'next', '', '~', '~', '~'],
            $this->rows($exact = str_repeat('a', times: 10)."\nnext\n", wrap: new WrapLayout(new Document($exact))),
        );
    }

    /**
     * Through the widget, which is what the Alt+Z toggle drives: the option
     * decides whether a layout reaches the assembler at all, and turning it on
     * must also stop horizontal scroll — they are the same slice offset, so
     * both driving at once would slice twice.
     */
    public function testTheDisplayToggleTurnsWrappingOnAndOffThroughTheWidget(): void
    {
        $editor = new \Porta\Widget\CodeEditorWidget();
        $long = str_repeat('a', times: 60);
        $editor->showDocument('/p/a.php', new Document("<?php\n{$long}\nafter\n"));

        // Rows carrying the long line, which is what "occupies N rows" means.
        $rowsOfAs = static fn (): int => \count(array_filter(
            $editor->render(new \Symfony\Component\Tui\Render\RenderContext(30, 6)),
            static fn (string $r): bool => str_contains(AnsiUtils::stripAnsiCodes($r), 'aaaaa'),
        ));

        $this->assertSame(1, $rowsOfAs(), 'unwrapped, the long line occupies one row');

        $editor->display()->setSoftWrap(true);

        $this->assertGreaterThan(1, $rowsOfAs(), 'wrapped, it occupies several');
    }

    /**
     * Code lenses are the other kind of virtual row: one per lens, above the
     * line it describes, showing none of that line's text. They compose with
     * wrapping because both are just rows a line occupies.
     */
    public function testCodeLensesRenderAboveTheirLineAndCountAsRows(): void
    {
        $editor = new \Porta\Widget\CodeEditorWidget();
        $editor->showDocument('/p/a.php', new Document("<?php\nclass A {}\nfunction b() {}\n"));
        $editor->decorations()->setCodeLenses([1 => ['3 usages'], 2 => ['1 usage', '2 implementations']]);

        $rows = array_map(
            static fn (string $r): string => trim(AnsiUtils::stripAnsiCodes($r)),
            $editor->render(new \Symfony\Component\Tui\Render\RenderContext(52, 8)),
        );

        $this->assertSame(
            ['1   <?php', '3 usages', '2   class A {}', '1 usage', '2 implementations', '3   function b() {}', '4', '~'],
            $rows,
            'each lens takes a row of its own, unnumbered, above its line',
        );
    }
}
