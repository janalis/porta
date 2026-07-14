<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Highlight\HighContrastTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final class HighContrastTerminalThemeTest extends TestCase
{
    /** @return iterable<string, array{TokenType, string}> */
    public static function tokenColors(): iterable
    {
        yield 'keyword' => [TokenTypeEnum::KEYWORD, "\x1b[38;5;213m"];
        yield 'literal' => [TokenTypeEnum::LITERAL, "\x1b[38;5;213m"];
        yield 'type' => [TokenTypeEnum::TYPE, "\x1b[38;5;229m"];
        yield 'value' => [TokenTypeEnum::VALUE, "\x1b[38;5;120m"];
        yield 'number' => [TokenTypeEnum::NUMBER, "\x1b[38;5;220m"];
        yield 'property' => [TokenTypeEnum::PROPERTY, "\x1b[38;5;123m"];
        yield 'variable' => [TokenTypeEnum::VARIABLE, "\x1b[38;5;217m"];
        yield 'operator' => [TokenTypeEnum::OPERATOR, "\x1b[38;5;252m"];
        yield 'generic' => [TokenTypeEnum::GENERIC, "\x1b[38;5;87m"];
        yield 'attribute' => [TokenTypeEnum::ATTRIBUTE, "\x1b[38;5;186m"];
        yield 'comment' => [TokenTypeEnum::COMMENT, "\x1b[38;5;248m"];
        yield 'uncolored-injection' => [TokenTypeEnum::INJECTION, ''];
        yield 'uncolored-hidden' => [TokenTypeEnum::HIDDEN, ''];
    }

    #[DataProvider('tokenColors')]
    public function testBeforeColorsEachToken(TokenType $tokenType, string $expected): void
    {
        $this->assertSame($expected, new HighContrastTerminalTheme()->before($tokenType));
    }

    public function testAfterResetsForeground(): void
    {
        $this->assertSame("\x1b[39m", new HighContrastTerminalTheme()->after(TokenTypeEnum::KEYWORD));
    }
}
