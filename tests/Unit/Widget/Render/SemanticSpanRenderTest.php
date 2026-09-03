<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;
use Porta\Editor\LineNumberMode;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\PlainTerminalTheme;
use Porta\Ui\Sgr;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorRowAssembler;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * The join: a decoded token has to reach a painted row, over whatever the
 * lexer already put there, without disturbing the text itself.
 *
 * Tested at the assembler rather than on the span builder, because every defect
 * this feature could ship — a column off by one, a colour that leaks to the end
 * of the line, a row whose visible width changed — is only observable once the
 * row is actually assembled.
 */
final class SemanticSpanRenderTest extends TestCase
{
    private function render(Document $doc, DecorationStore $store, string $highlighted, ?object $theme): string
    {
        $assembler = new EditorRowAssembler(
            $doc,
            new FoldModel($doc),
            $store,
            LineNumberMode::Absolute,
            indentGuides: false,
            vcsGutter: false,
            inlineDiagnostics: false,
            focused: true,
            showCaret: false,
            syntaxTheme: $theme,
        );
        $geo = new EditorGeometry(numberWidth: 2, gutterWidth: 3, contentWidth: 40, stripeCol: 0);
        $rows = $assembler->assemble(
            $geo,
            new EditorDecorations(false, null, [], [], null),
            sticky: [],
            highlighted: [$highlighted],
            stripe: [''],
            contentRows: 1,
            vScroll: 0,
            cursorLine: -1,
            cursorDisplayCol: 0,
            hScroll: 0,
        );

        return $rows[0];
    }

    private function store(array $byLine): DecorationStore
    {
        $store = new DecorationStore(static function (): void {});
        $store->setSemanticTokens($byLine);

        return $store;
    }

    public function testATokenPaintsThePalettesColourOverThatRangeOnly(): void
    {
        $doc = new Document("aaa bbb ccc\n");
        $theme = new DarkTerminalTheme();
        $row = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 4, 'endCol' => 7, 'type' => 'property', 'modifiers' => []]],
            ]),
            'aaa bbb ccc',
            $theme,
        );

        self::assertStringContainsString($theme->before(TokenTypeEnum::PROPERTY), $row);
        // The colour must be closed, or it runs to the end of the line.
        self::assertStringContainsString(Sgr::DEFAULT_FG, $row);
    }

    public function testTheVISIBLETextIsByteForByteUNCHANGED(): void
    {
        // The one thing a decoration may never do. A shifted column or a
        // mangled escape shows up here and nowhere else.
        $doc = new Document("aaa bbb ccc\n");
        $plain = $this->render($doc, $this->store([]), 'aaa bbb ccc', new DarkTerminalTheme());
        $coloured = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 4, 'endCol' => 7, 'type' => 'property', 'modifiers' => []]],
            ]),
            'aaa bbb ccc',
            new DarkTerminalTheme(),
        );

        self::assertNotSame($plain, $coloured, 'the token must actually change the row, or this test proves nothing');
        self::assertSame(AnsiUtils::stripAnsiCodes($plain), AnsiUtils::stripAnsiCodes($coloured));
    }

    public function testNoThemeMeansTheLexersColoursAreLeftAlone(): void
    {
        // The phpactor path, and the no-server path: identical to today.
        $doc = new Document("aaa bbb ccc\n");
        $tokens = [0 => [['col' => 4, 'endCol' => 7, 'type' => 'property', 'modifiers' => []]]];

        self::assertSame(
            $this->render($doc, $this->store([]), 'aaa bbb ccc', null),
            $this->render($doc, $this->store($tokens), 'aaa bbb ccc', null),
        );
    }

    public function testAnUnmappedTypeWithNoModifiersLeavesTheRowUntouched(): void
    {
        $doc = new Document("aaa bbb ccc\n");
        $tokens = [0 => [['col' => 4, 'endCol' => 7, 'type' => 'notInOurPalette', 'modifiers' => []]]];

        self::assertSame(
            $this->render($doc, $this->store([]), 'aaa bbb ccc', new DarkTerminalTheme()),
            $this->render($doc, $this->store($tokens), 'aaa bbb ccc', new DarkTerminalTheme()),
        );
    }

    public function testADeprecatedTokenStrikesThroughEvenUnderAColourlessTheme(): void
    {
        $doc = new Document("aaa bbb ccc\n");
        $row = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 0, 'endCol' => 3, 'type' => 'method', 'modifiers' => ['deprecated']]],
            ]),
            'aaa bbb ccc',
            new PlainTerminalTheme(),
        );

        self::assertStringContainsString("\x1b[9m", $row);
        self::assertStringContainsString("\x1b[29m", $row);
    }

    public function testAWideCharacterBeforeTheTokenShiftsItToTheRightDisplayColumn(): void
    {
        // LSP counts UTF-16 code units; the screen counts display columns. Each
        // CJK glyph is ONE code unit and TWO columns, so a decoder that treated
        // the two as the same would open the colour two columns early — and
        // every ASCII-only test would still pass.
        $doc = new Document("\u{65e5}\u{672c} abc\n");
        $theme = new DarkTerminalTheme();
        $row = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 3, 'endCol' => 6, 'type' => 'property', 'modifiers' => []]],
            ]),
            "\u{65e5}\u{672c} abc",
            $theme,
        );

        $open = $theme->before(TokenTypeEnum::PROPERTY);
        $at = strpos($row, $open);
        self::assertNotFalse($at, 'the token must be painted at all');
        self::assertSame('abc', substr($row, $at + \strlen($open), length: 3), 'the colour must open exactly at "abc"');
    }

    public function testATokenENTIRELYPastTheLineEndPaintsNothing(): void
    {
        // The realistic stale case: the line was shortened since the server
        // answered, so BOTH ends clamp to the same display column. A zero-width
        // span would emit an open and a close around nothing.
        $doc = new Document("ab\n");
        $stale = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 300, 'endCol' => 400, 'type' => 'property', 'modifiers' => []]],
            ]),
            'ab',
            new DarkTerminalTheme(),
        );

        self::assertSame($this->render($doc, $this->store([]), 'ab', new DarkTerminalTheme()), $stale);
    }

    public function testATokenReachingPastTheLineEndDoesNotWidenTheRow(): void
    {
        // Servers do send stale ranges after an edit; the row must stay the
        // width the geometry asked for or the whole layout garbles.
        $doc = new Document("ab\n");
        $wide = $this->render(
            $doc,
            $this->store([
                0 => [['col' => 0, 'endCol' => 400, 'type' => 'property', 'modifiers' => []]],
            ]),
            'ab',
            new DarkTerminalTheme(),
        );
        $none = $this->render($doc, $this->store([]), 'ab', new DarkTerminalTheme());

        self::assertSame(
            AnsiUtils::visibleWidth($none),
            AnsiUtils::visibleWidth($wide),
        );
    }
}
