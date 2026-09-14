<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\PlainTerminalTheme;
use Porta\Highlight\SemanticTokenStyle;
use Porta\Ui\Sgr;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * The semantic recolour reuses the lexer's own palette, and closes the way the
 * lexer's own token brackets close. Both are asserted against the theme rather
 * than against literal escape bytes, so a palette change moves the test with it
 * instead of breaking it.
 */
final class SemanticTokenStyleTest extends TestCase
{
    public function testAPropertyOpensWithExactlyThePalettesPropertyColour(): void
    {
        $theme = new DarkTerminalTheme();

        $this->assertSame(
            $theme->before(TokenTypeEnum::PROPERTY),
            SemanticTokenStyle::open('property', [], $theme),
        );
    }

    public function testAParameterAndAVariableShareThePalettesVariableColour(): void
    {
        $theme = new DarkTerminalTheme();
        $expected = $theme->before(TokenTypeEnum::VARIABLE);

        $this->assertSame($expected, SemanticTokenStyle::open('parameter', [], $theme));
        $this->assertSame($expected, SemanticTokenStyle::open('variable', [], $theme));
    }

    public function testAPropertyAndALocalDoNotShareAColour(): void
    {
        // The whole point of the feature: the lexer cannot tell these apart.
        $theme = new DarkTerminalTheme();

        $this->assertNotSame(
            SemanticTokenStyle::open('property', [], $theme),
            SemanticTokenStyle::open('variable', [], $theme),
        );
    }

    /**
     * Every LSP type this client declares support for must land on a palette
     * entry. A type we advertise and then fail to map is an identifier the
     * server bothered to classify and we paint no differently.
     */
    #[DataProvider('mappedTypes')]
    public function testEveryDeclaredTypeMapsOntoThePalette(string $type, TokenTypeEnum $expected): void
    {
        $theme = new DarkTerminalTheme();

        $this->assertSame($theme->before($expected), SemanticTokenStyle::open($type, [], $theme));
    }

    public static function mappedTypes(): \Generator
    {
        yield 'namespace' => ['namespace', TokenTypeEnum::TYPE];
        yield 'class' => ['class', TokenTypeEnum::TYPE];
        yield 'interface' => ['interface', TokenTypeEnum::TYPE];
        yield 'enum' => ['enum', TokenTypeEnum::TYPE];
        yield 'struct' => ['struct', TokenTypeEnum::TYPE];
        yield 'type' => ['type', TokenTypeEnum::TYPE];
        yield 'typeParameter' => ['typeParameter', TokenTypeEnum::TYPE];
        yield 'function' => ['function', TokenTypeEnum::GENERIC];
        yield 'method' => ['method', TokenTypeEnum::GENERIC];
        yield 'event' => ['event', TokenTypeEnum::GENERIC];
        yield 'decorator' => ['decorator', TokenTypeEnum::ATTRIBUTE];
        yield 'macro' => ['macro', TokenTypeEnum::ATTRIBUTE];
        yield 'enumMember' => ['enumMember', TokenTypeEnum::LITERAL];
        yield 'keyword' => ['keyword', TokenTypeEnum::KEYWORD];
        yield 'modifier' => ['modifier', TokenTypeEnum::KEYWORD];
        yield 'comment' => ['comment', TokenTypeEnum::COMMENT];
        yield 'string' => ['string', TokenTypeEnum::VALUE];
        yield 'regexp' => ['regexp', TokenTypeEnum::VALUE];
        yield 'number' => ['number', TokenTypeEnum::NUMBER];
        yield 'operator' => ['operator', TokenTypeEnum::OPERATOR];
    }

    public function testDeprecatedStrikesThroughAndStaticLeans(): void
    {
        $theme = new DarkTerminalTheme();

        // Asserted WHOLE, not by containment: the modifiers are appended to the
        // colour, so a `.=` that became a `=` would drop the colour entirely
        // and still contain the strikethrough. A mutant did exactly that.
        $this->assertSame(
            $theme->before(TokenTypeEnum::GENERIC)."\x1b[9m\x1b[3m",
            SemanticTokenStyle::open('method', ['deprecated', 'static'], $theme),
        );
        $this->assertSame("\x1b[23m\x1b[29m".Sgr::DEFAULT_FG, SemanticTokenStyle::close('method', ['deprecated', 'static'], $theme));
    }

    public function testAModifierWeDoNotRenderChangesNothing(): void
    {
        $theme = new DarkTerminalTheme();

        $this->assertSame(
            SemanticTokenStyle::open('method', [], $theme),
            SemanticTokenStyle::open('method', ['declaration', 'readonly'], $theme),
        );
    }

    public function testAnUnmappedTypeWithNoModifiersSaysNothingAtAll(): void
    {
        // '' is the signal the row assembler uses to leave the lexer alone.
        $this->assertSame('', SemanticTokenStyle::open('somethingNewInTheSpec', [], new DarkTerminalTheme()));
    }

    public function testAnUnmappedTypeStillCarriesItsModifiers(): void
    {
        $this->assertSame("\x1b[9m", SemanticTokenStyle::open('somethingNewInTheSpec', ['deprecated'], new DarkTerminalTheme()));
    }

    public function testThePlainThemeOpensNoColourAndSoResetsNone(): void
    {
        // A reset with no open would still be bytes on the wire, and the plain
        // theme's contract is that it emits none.
        $theme = new PlainTerminalTheme();

        $this->assertSame('', SemanticTokenStyle::open('property', [], $theme));
        $this->assertSame('', SemanticTokenStyle::close('property', [], $theme));
    }

    public function testThePlainThemeStillClosesAModifierItOpened(): void
    {
        $theme = new PlainTerminalTheme();

        $this->assertSame("\x1b[9m", SemanticTokenStyle::open('property', ['deprecated'], $theme));
        $this->assertSame("\x1b[29m", SemanticTokenStyle::close('property', ['deprecated'], $theme));
    }
}
