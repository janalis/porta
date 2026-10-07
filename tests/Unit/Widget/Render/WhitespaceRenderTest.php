<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\Document;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\FoldModel;
use Porta\Editor\InlayLines;
use Porta\Editor\LineNumberMode;
use Porta\Ui\Glyphs;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorRowAssembler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The assembler's whitespace flag. Its DEFAULT is the point: the assembler is
 * built in several places, and a decoration that turned itself on would mark
 * every file in the IDE.
 */
final class WhitespaceRenderTest extends TestCase
{
    private const string CODE = "class A\n{\n    function one() {}\n}\n";
    private const int CONTENT_WIDTH = 40;

    /** @return string[] */
    private function rows(?bool $showWhitespace = null): array
    {
        $doc = new Document(self::CODE);
        $assembler = null === $showWhitespace
            ? new EditorRowAssembler(
                $doc,
                new FoldModel($doc),
                $inlayStore = new DecorationStore(static function (): void {}),
                new InlayLines($inlayStore, new EditorDisplayOptions(static function (): void {})),
                LineNumberMode::Absolute,
                indentGuides: false,
                vcsGutter: false,
                diagnosticMessages: DiagnosticMessageMode::None,
                focused: true,
                showCaret: false,
            )
            : new EditorRowAssembler(
                $doc,
                new FoldModel($doc),
                $inlayStore = new DecorationStore(static function (): void {}),
                new InlayLines($inlayStore, new EditorDisplayOptions(static function (): void {})),
                LineNumberMode::Absolute,
                indentGuides: false,
                vcsGutter: false,
                diagnosticMessages: DiagnosticMessageMode::None,
                focused: true,
                showCaret: false,
                showWhitespace: $showWhitespace,
            );
        $geo = new EditorGeometry(numberWidth: 2, gutterWidth: 3, contentWidth: self::CONTENT_WIDTH, stripeCol: 0);

        return $assembler->assemble(
            $geo,
            new EditorDecorations(false, null, [], [], null),
            sticky: [],
            highlighted: \explode("\n", self::CODE),
            stripe: \array_fill(start_index: 0, count: 5, value: ''),
            contentRows: 4,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );
    }

    public function testAnAssemblerBuiltWithoutTheFlagMarksNOTHING(): void
    {
        $indented = AnsiUtils::stripAnsiCodes($this->rows()[2]);

        $this->assertStringContainsString('    function one() {}', $indented, 'the indent is left as spaces');
        $this->assertStringNotContainsString(Glyphs::WHITESPACE_SPACE, $indented);
    }

    public function testAskingForItMarksTheIndentAndKeepsTheWidth(): void
    {
        $on = $this->rows(true);
        $indented = AnsiUtils::stripAnsiCodes($on[2]);

        $this->assertStringContainsString('····function one() {}', $indented);
        foreach ($on as $index => $row) {
            $this->assertSame(
                AnsiUtils::visibleWidth($this->rows(false)[$index]),
                AnsiUtils::visibleWidth($row),
                "row {$index} keeps its width",
            );
        }
    }
}
