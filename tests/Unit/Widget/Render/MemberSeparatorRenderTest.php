<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticMessageMode;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Editor\LineNumberMode;
use Porta\Highlight\CodeHighlighter;
use Porta\Ui\Sgr;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorRowAssembler;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The method separator is a RULE, not a row: it underlines a line across the
 * content width, so it costs no vertical space in a terminal that has little to
 * spare. The line it lands on is a member's last one, which is why both the
 * blank-line and the has-text cases are covered here.
 */
final class MemberSeparatorRenderTest extends TestCase
{
    private const string CODE = "class A\n{\n    function one() {}\n\n    function two() {}\n}\n";
    private const int CONTENT_WIDTH = 40;

    /** @return string[] the fixture as the editor really receives it: coloured */
    private function highlighted(): array
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('/fixture.php');

        return $highlighter->highlight(\explode("\n", self::CODE), version: 1);
    }

    /** @param array<int, true> $separators */
    private function rows(array $separators): array
    {
        $doc = new Document(self::CODE);
        $assembler = new EditorRowAssembler(
            $doc,
            new FoldModel($doc),
            new DecorationStore(static function (): void {}),
            LineNumberMode::Absolute,
            indentGuides: false,
            vcsGutter: false,
            diagnosticMessages: DiagnosticMessageMode::None,
            focused: true,
            showCaret: false,
            separators: $separators,
        );
        $geo = new EditorGeometry(numberWidth: 2, gutterWidth: 3, contentWidth: self::CONTENT_WIDTH, stripeCol: 0);

        return $assembler->assemble(
            $geo,
            new EditorDecorations(false, null, [], [], null),
            sticky: [],
            highlighted: $this->highlighted(),
            stripe: \array_fill(start_index: 0, count: 7, value: ''),
            contentRows: 6,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );
    }

    public function testTheRuledLineIsUnderlinedAcrossTheContentWidth(): void
    {
        // Line 3 is the blank line between one() and two().
        $ruled = $this->rows([3 => true])[3];

        // The underline opens and then covers the whole content width. It is
        // closed by the row's own reset rather than by NO_UNDERLINE — every
        // row ends with one, and a second close would be bytes for nothing.
        $at = \strpos($ruled, Sgr::UNDERLINE);
        $this->assertNotFalse($at, 'the rule is drawn');
        $this->assertSame(
            \str_repeat(' ', self::CONTENT_WIDTH),
            \substr($ruled, $at + \strlen(Sgr::UNDERLINE), self::CONTENT_WIDTH),
            'and covers the content width',
        );
        $this->assertStringEndsWith(Sgr::RESET, $ruled, 'the row closes it');
    }

    public function testARuleOnALineWithTEXTStillSpansTheContentWidth(): void
    {
        // The case that actually ships: the rule closes off a member, so it
        // lands on the row holding its last line — text, then padding. Neither
        // the highlighter's colour codes in the text nor the padding may break
        // the underline, so measure it as the terminal does, by replaying the
        // row and asking which cells carry it.
        $this->assertStringContainsString("\x1b[", $this->highlighted()[2], 'the fixture line is really coloured');

        $row = $this->rows([2 => true])[2];
        $underlined = 0;
        $on = false;
        foreach (\preg_split('/(\x1b\[[0-9;]*m)/', $row, flags: \PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (\str_starts_with($piece, "\x1b")) {
                $on = match ($piece) {
                    Sgr::UNDERLINE => true,
                    Sgr::NO_UNDERLINE, Sgr::RESET => false,
                    default => $on,
                };
                continue;
            }
            $underlined += $on ? \mb_strlen($piece) : 0;
        }

        $this->assertSame(self::CONTENT_WIDTH, $underlined, 'every content cell is ruled');
    }

    public function testTheRuleCanLandOnTheBLANKLineBetweenTwoMembers(): void
    {
        // A blank line has no cells of its own, so this is the case that needs
        // the padding: without it the rule would be zero columns wide.
        $this->assertSame('', \explode("\n", self::CODE)[3], 'the fixture line is empty');
        $this->assertStringContainsString(Sgr::UNDERLINE, $this->rows([3 => true])[3]);
    }

    public function testTheROWSWIDTHIsUnchanged(): void
    {
        // The one thing a decoration may never do, and the failure mode this
        // codebase calls garble.
        foreach ($this->rows([3 => true]) as $index => $row) {
            $this->assertSame(
                AnsiUtils::visibleWidth($this->rows([])[$index]),
                AnsiUtils::visibleWidth($row),
                "row {$index} keeps its width",
            );
        }
    }

    public function testLinesWithNoSeparatorAreUntouched(): void
    {
        $with = $this->rows([3 => true]);
        $without = $this->rows([]);

        $this->assertSame($without[2], $with[2], 'the line above the rule is identical');
        $this->assertSame($without[4], $with[4], 'and so is the one below');
        $this->assertNotSame($without[3], $with[3], 'only the ruled line differs');
    }
}
