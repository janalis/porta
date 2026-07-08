<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Highlight\LightTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

final class LightTerminalThemeTest extends TestCase
{
    /** @return iterable<string, array{TokenType, string}> */
    public static function tokenColors(): iterable
    {
        yield 'keyword' => [TokenTypeEnum::KEYWORD, "\x1b[38;5;91m"];
        yield 'literal' => [TokenTypeEnum::LITERAL, "\x1b[38;5;91m"];
        yield 'type' => [TokenTypeEnum::TYPE, "\x1b[38;5;130m"];
        yield 'value' => [TokenTypeEnum::VALUE, "\x1b[38;5;28m"];
        yield 'number' => [TokenTypeEnum::NUMBER, "\x1b[38;5;166m"];
        yield 'property' => [TokenTypeEnum::PROPERTY, "\x1b[38;5;25m"];
        yield 'variable' => [TokenTypeEnum::VARIABLE, "\x1b[38;5;131m"];
        yield 'operator' => [TokenTypeEnum::OPERATOR, "\x1b[38;5;242m"];
        yield 'generic' => [TokenTypeEnum::GENERIC, "\x1b[38;5;30m"];
        yield 'attribute' => [TokenTypeEnum::ATTRIBUTE, "\x1b[38;5;100m"];
        yield 'comment' => [TokenTypeEnum::COMMENT, "\x1b[38;5;245m"];
        yield 'uncolored-injection' => [TokenTypeEnum::INJECTION, ''];
        yield 'uncolored-hidden' => [TokenTypeEnum::HIDDEN, ''];
    }

    #[DataProvider('tokenColors')]
    public function testBeforeColorsEachToken(TokenType $tokenType, string $expected): void
    {
        $this->assertSame($expected, new LightTerminalTheme()->before($tokenType));
    }

    public function testAfterResetsForeground(): void
    {
        $this->assertSame("\x1b[39m", new LightTerminalTheme()->after(TokenTypeEnum::KEYWORD));
    }
}
